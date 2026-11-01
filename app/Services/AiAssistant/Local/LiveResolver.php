<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\AiAssistant\Tools\ToolRegistry;

/**
 * TI-5 - محلّل البيانات الحيّة والشخصية للمحرك الداخلي.
 *
 * يستعمل **أدوات القراءة الموجودة في E45 فقط** عبر `ToolRegistry::execute` (نفس التحقق الصارم من الوسائط وسقف الحجم وعزل الأخطاء):
 *  - 5A عامة: get_current_season, get_campaign_information, get_competition_information, get_team_public_information,
 *    get_ahjiyat_pass_information, get_store_item_information, get_writer_league_information, get_supporter_leaderboard_information.
 *  - 5B شخصية: get_my_* (المستخدم من ToolContext وحده؛ لا وسيط user_id/email/اسم مستخدم من السائل إطلاقًا).
 *
 * لا قراءة مباشرة من قاعدة البيانات، ولا لمس لخدمات الاقتصاد (المحافظ/الجواهر/الخبرة) إلا عبر أداة get_my_progress_summary القرائية الخالصة.
 * الصياغة بقوالب ثابتة من حقول ناتج الأداة نفسها؛ لا يُخترع رقم ولا سعر ولا موعد. الوسيط الوحيد المقبول من نص السائل
 * هو اسم فريق/عنصر متجر عام مقتبَس بين علامتي اقتباس، ويمرّ بتحقق السجل (طول/ترميز/محارف).
 */
final class LiveResolver
{
    public const REASON = 'local_engine_live';

    /** مفتاح الهدف ⇒ اسم أداة عامة. */
    private const PUBLIC = [
        'season' => 'get_current_season',
        'campaign' => 'get_campaign_information',
        'competitions' => 'get_competition_information',
        'teams' => 'get_team_public_information',
        'pass' => 'get_ahjiyat_pass_information',
        'store' => 'get_store_item_information',
        'writers' => 'get_writer_league_information',
        'supporters' => 'get_supporter_leaderboard_information',
    ];

    /** مفتاح الهدف ⇒ اسم أداة «حسابي». */
    private const PERSONAL = [
        'progress' => 'get_my_progress_summary',
        'wallet' => 'get_my_progress_summary',
        'profile' => 'get_my_profile_summary',
        'pass' => 'get_my_pass_status',
        'season' => 'get_my_current_season_status',
        'competition' => 'get_my_competition_status',
        'writer_stars' => 'get_my_writer_star_budget',
        'supporter' => 'get_my_supporter_recognition',
        'creator' => 'get_my_creator_status',
        'campaign' => 'get_my_current_campaign_progress',
    ];

    private const PHASES = [
        'upcoming' => 'قادمة',
        'live' => 'جارية الآن',
        'ended' => 'انتهى وقتها وبانتظار اعتماد النتائج',
        'completed' => 'انتهت واعتُمدت نتائجها',
    ];

    private const VISIBILITY = ['public' => 'عام', 'friends' => 'للأصدقاء فقط', 'private' => 'خاص'];

    public function __construct(private readonly ToolRegistry $tools) {}

    /** @param array<string, mixed> $def */
    public function resolve(string $intent, array $def, Analysis $a, ToolContext $context, string $rawText = ''): ?AssistantAnswer
    {
        $personal = ($def['k'] ?? '') === 'my';
        $key = (string) ($def['t'] ?? '');
        $tool = ($personal ? self::PERSONAL : self::PUBLIC)[$key] ?? null;

        if ($tool === null || ($personal && ! $context->hasMember())) {
            return null;
        }

        $arguments = $personal ? [] : $this->arguments($key, $rawText);
        $result = $this->tools->execute(new AiToolCall('local-'.$key, $tool, $arguments), $context);
        $area = $this->tools->get($tool)?->area();

        if (! $result->ok) {
            return $this->failure($result, $area);
        }

        $text = $personal ? $this->personal($key, $result->data, $a) : $this->publicData($key, $result->data, $a);

        if ($text === null || trim($text) === '') {
            return null;
        }

        return new AssistantAnswer($text, $this->sources($result), 'answered', self::REASON, $area, true, false, 1);
    }

    /**
     * الوسيط الوحيد من نص السائل: اسم مقتبَس لفريق/عنصر متجر عام. غيره لا يُمرَّر أبدًا.
     *
     * @return array<string, string>
     */
    private function arguments(string $key, string $rawText): array
    {
        if (! in_array($key, ['teams', 'store'], true)) {
            return [];
        }

        if (preg_match('/[«"“„]([^«»"“”„]{2,60})[»"”“]/u', $rawText, $m) !== 1) {
            return [];
        }

        $name = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');

        return $name === '' ? [] : ['name' => $name];
    }

    private function failure(AiToolResult $result, ?string $area): ?AssistantAnswer
    {
        $message = is_string($result->data['message'] ?? null) ? (string) $result->data['message'] : null;

        return match ($result->error) {
            'not_found' => new AssistantAnswer($message ?? 'لم أجد ما يطابق طلبك في المنصة.', $this->helpSources(), 'answered', self::REASON, $area, true, false, 1),
            'unavailable', 'disabled' => new AssistantAnswer($message ?? 'هذه المعلومة غير متاحة لي الآن. يمكنك مراجعة الصفحة المختصة في المنصة.', $this->helpSources(), 'fallback', 'tool_unavailable', $area, false, false, 1),
            default => null,
        };
    }

    // ───────────────────────────── 5A: بيانات عامة ─────────────────────────────

    /** @param array<string, mixed> $d */
    private function publicData(string $key, array $d, Analysis $a): ?string
    {
        return match ($key) {
            'season' => $this->season($d, $a),
            'campaign' => $this->campaigns($d),
            'competitions' => $this->competitions($d, $a),
            'teams' => $this->teams($d),
            'pass' => $this->pass($d),
            'store' => $this->store($d),
            'writers' => $this->writers($d),
            'supporters' => $this->supporters($d),
            default => null,
        };
    }

    /** @param array<string, mixed> $d */
    private function season(array $d, Analysis $a): string
    {
        $live = is_array($d['live'] ?? null) ? $d['live'] : null;
        $upcoming = array_values(array_filter((array) ($d['upcoming'] ?? []), 'is_array'));
        $lines = [];

        if ($live !== null) {
            $lines[] = 'الموسم المباشر الآن: «'.$this->s($live['name'] ?? '').'».';

            if (! empty($live['tagline'])) {
                $lines[] = $this->s($live['tagline']);
            }

            $window = $this->window($live);

            if ($window !== null) {
                $lines[] = $window;
            }
        } else {
            $lines[] = 'لا يوجد موسم مباشر الآن.';
        }

        if ($upcoming !== []) {
            $next = $upcoming[0];
            $lines[] = 'الموسم القادم المعلَن: «'.$this->s($next['name'] ?? '').'»'.(! empty($next['starts_at']) ? ' ويبدأ في '.$this->s($next['starts_at']) : '').'.';
        } elseif ($live === null) {
            $lines[] = 'ولا يوجد موسم قادم معلَن حاليًا.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function campaigns(array $d): string
    {
        $rows = array_values(array_filter((array) ($d['campaigns'] ?? []), 'is_array'));

        if ($rows === []) {
            return 'لا توجد حملة متاحة الآن.';
        }

        $lines = ['الحملات المتاحة الآن:'];

        foreach (array_slice($rows, 0, 4) as $r) {
            $line = '• «'.$this->s($r['title'] ?? '').'»';
            $bits = [];

            if (($r['stages_count'] ?? 0) > 0) {
                $bits[] = (int) $r['stages_count'].' مراحل';
            }

            if (($r['steps_count'] ?? 0) > 0) {
                $bits[] = (int) $r['steps_count'].' خطوات';
            }

            if (! empty($r['ends_at'])) {
                $bits[] = 'تنتهي في '.$this->s($r['ends_at']);
            }

            $lines[] = $line.($bits === [] ? '' : ' — '.implode('، ', $bits));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function competitions(array $d, Analysis $a): string
    {
        $rows = array_values(array_filter((array) ($d['competitions'] ?? []), 'is_array'));

        if ($rows === []) {
            return 'لا توجد منافسة عامة الآن.';
        }

        $lines = ['المنافسات العامة:'];

        foreach (array_slice($rows, 0, 4) as $r) {
            $bits = [self::PHASES[(string) ($r['phase'] ?? '')] ?? null];

            if (! empty($r['registration_open'])) {
                $bits[] = 'التسجيل مفتوح'.(! empty($r['registration_ends_at']) ? ' حتى '.$this->s($r['registration_ends_at']) : '');
            } elseif (($r['phase'] ?? '') === 'upcoming' && ! empty($r['registration_starts_at'])) {
                $bits[] = 'يفتح التسجيل في '.$this->s($r['registration_starts_at']);
            }

            if (! empty($r['is_full'])) {
                $bits[] = 'اكتمل عدد المشاركين';
            } elseif (isset($r['participants_count'])) {
                $bits[] = 'المشاركون: '.(int) $r['participants_count'].(! empty($r['max_participants']) ? ' من '.(int) $r['max_participants'] : '');
            }

            $lines[] = '• «'.$this->s($r['title'] ?? '').'» — '.implode('، ', array_filter($bits));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function teams(array $d): string
    {
        $rows = array_values(array_filter((array) ($d['teams'] ?? []), 'is_array'));

        if ($rows === []) {
            return 'لم أجد فريقًا عامًا في دليل الفرق.';
        }

        $lines = ['من دليل الفرق العامة (مرتبة بعدد الأعضاء):'];

        foreach (array_slice($rows, 0, 5) as $r) {
            $lines[] = '• «'.$this->s($r['name'] ?? '').'» — '.(int) ($r['members_count'] ?? 0).' من '.(int) ($r['max_members'] ?? 0).' عضوًا، '.$this->s($r['join_policy'] ?? '');
        }

        $lines[] = 'الاختيار الأنسب لك يعتمد على ما تفضّله؛ افتح صفحة الفريق لتعرف أسلوبه وشروط الانضمام.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function pass(array $d): string
    {
        $p = is_array($d['pass'] ?? null) ? $d['pass'] : null;

        if ($p === null) {
            return 'لا يوجد مسار أحجيات نشط الآن.';
        }

        $lines = ['مسار الأحجيات الحالي: «'.$this->s($p['name'] ?? '').'»'.(! empty($p['active']) ? ' (نشط الآن).' : '.')];

        if (! empty($p['subtitle'])) {
            $lines[] = $this->s($p['subtitle']);
        }

        if (! empty($p['starts_at']) || ! empty($p['ends_at'])) {
            $lines[] = 'يبدأ في '.$this->s($p['starts_at'] ?? '—').' وينتهي في '.$this->s($p['ends_at'] ?? '—').'.';
        }

        if (! empty($p['claim_ends_at'])) {
            $lines[] = 'آخر موعد لاستلام المكافآت: '.$this->s($p['claim_ends_at']).'.';
        }

        if (($p['total_tiers'] ?? 0) > 0) {
            $lines[] = 'عدد المستويات: '.(int) $p['total_tiers'].'.';
        }

        if (is_array($p['premium'] ?? null)) {
            $lines[] = 'المسار المميز: '.(! empty($p['premium']['sales_open']) ? 'الشراء متاح' : 'الشراء غير متاح الآن').(! empty($p['premium']['price']) ? '، وسعره '.$this->s($p['premium']['price']) : '').'.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function store(array $d): string
    {
        $rows = array_values(array_filter((array) ($d['items'] ?? []), 'is_array'));

        if ($rows === []) {
            return 'لا توجد عناصر متاحة في المتجر الآن.';
        }

        $lines = ['من المتجر:'];

        foreach (array_slice($rows, 0, 5) as $r) {
            $bits = [$this->s($r['type'] ?? '')];

            foreach (array_slice((array) ($r['prices'] ?? []), 0, 2) as $price) {
                if (is_array($price)) {
                    $bits[] = 'السعر '.(int) ($price['amount'] ?? 0).' '.$this->s($price['currency'] ?? '');
                }
            }

            if (isset($r['remaining_stock']) && $r['remaining_stock'] !== null) {
                $bits[] = 'المتبقي '.(int) $r['remaining_stock'];
            }

            if (! empty($r['available_until'])) {
                $bits[] = 'متاح حتى '.$this->s($r['available_until']);
            }

            $lines[] = '• «'.$this->s($r['name'] ?? '').'» — '.implode('، ', array_filter($bits));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function writers(array $d): string
    {
        if (empty($d['available'])) {
            return 'دوري الكتّاب غير متاح حاليًا.';
        }

        $lines = ['دوري الكتّاب في «'.$this->s($d['season'] ?? '').'»: '.$this->s($d['state_label'] ?? '').'.'];

        if (! empty($d['voting_opens_at']) || ! empty($d['voting_closes_at'])) {
            $lines[] = 'نافذة التصويت: من '.$this->s($d['voting_opens_at'] ?? '—').' إلى '.$this->s($d['voting_closes_at'] ?? '—').'.';
        }

        if (($d['stars_per_voter'] ?? 0) > 0) {
            $lines[] = 'لكل مصوِّت '.(int) $d['stars_per_voter'].' نجوم.';
        }

        if (($d['writers_count'] ?? 0) > 0) {
            $lines[] = 'عدد الكتّاب المشاركين: '.(int) $d['writers_count'].'.';
        }

        $top = array_values(array_filter((array) ($d['top_writers'] ?? []), 'is_array'));

        if ($top !== []) {
            $lines[] = 'في الصدارة الآن:';

            foreach (array_slice($top, 0, 3) as $r) {
                $lines[] = '• '.(int) ($r['rank'] ?? 0).'. '.$this->s($r['writer'] ?? '').' — '.(int) ($r['stars'] ?? 0).' نجمة';
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function supporters(array $d): string
    {
        if (empty($d['available'])) {
            return 'ميزة داعمي الموسم غير متاحة حاليًا.';
        }

        $lines = ['داعمو «'.$this->s($d['season'] ?? '').'»: '.(! empty($d['finalized']) ? 'صدر الترتيب النهائي' : 'الترتيب مفتوح'), 'عدد الداعمين: '.(int) ($d['supporters_count'] ?? 0).'.'];

        $top = array_values(array_filter((array) ($d['top_supporters'] ?? []), 'is_array'));

        foreach (array_slice($top, 0, 3) as $r) {
            $lines[] = '• '.(int) ($r['rank'] ?? 0).'. '.$this->s($r['supporter'] ?? '').' — '.(int) ($r['points'] ?? 0).' نقطة دعم';
        }

        $packages = array_values(array_filter((array) ($d['card_packages'] ?? []), 'is_array'));

        if (! empty($d['cards_on_sale']) && $packages !== []) {
            $lines[] = 'باقات البطاقات المعروضة:';

            foreach (array_slice($packages, 0, 4) as $p) {
                $lines[] = '• '.$this->s($p['name'] ?? '').' — '.$this->s($p['price'] ?? '').' (تقدير '.(int) ($p['estimated_support_points'] ?? 0).' نقطة دعم)';
            }
        } else {
            $lines[] = 'بيع البطاقات غير متاح الآن.';
        }

        $lines[] = 'نقاط الدعم تقدير للتكريم فقط؛ ليست مالًا ولا جواهر ولا خبرة، ولا تمنح أي أفضلية في اللعب.';

        return implode("\n", $lines);
    }

    // ───────────────────────────── 5B: بيانات حسابك أنت ─────────────────────────────

    /** @param array<string, mixed> $d */
    private function personal(string $key, array $d, Analysis $a): ?string
    {
        return match ($key) {
            'progress' => $this->myProgress($d),
            'wallet' => $this->myWallet($d),
            'profile' => $this->myProfile($d),
            'pass' => $this->myPass($d),
            'season' => $this->mySeason($d),
            'competition' => $this->myCompetition($d),
            'writer_stars' => $this->myStars($d),
            'supporter' => $this->mySupporter($d),
            'creator' => $this->myCreator($d),
            'campaign' => $this->myCampaign($d),
            default => null,
        };
    }

    /** @param array<string, mixed> $d */
    private function myProgress(array $d): string
    {
        $lines = ['مستواك الحالي: '.(int) ($d['level'] ?? 1).(! empty($d['level_name']) ? ' («'.$this->s($d['level_name']).'»)' : '').'، وخبرتك '.(int) ($d['total_xp'] ?? 0).'.'];

        if (isset($d['next_level']) && $d['next_level'] !== null) {
            $lines[] = 'للوصول إلى المستوى '.(int) $d['next_level'].' يلزمك '.(int) ($d['xp_to_next_level'] ?? 0).' من الخبرة (أنجزت '.(int) ($d['progress_to_next_percent'] ?? 0).'% من الطريق).';
        } else {
            $lines[] = 'وصلت إلى أعلى مستوى متاح حاليًا.';
        }

        $lines[] = 'الإنجازات المفتوحة: '.(int) ($d['achievements_unlocked'] ?? 0).' من '.(int) ($d['achievements_total'] ?? 0).'.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myWallet(array $d): string
    {
        $wallets = array_values(array_filter((array) ($d['wallets'] ?? []), 'is_array'));

        if ($wallets === []) {
            return 'لا توجد محفظة مسجَّلة لحسابك بعد؛ سيظهر رصيدك هنا بعد أول مكافأة.';
        }

        $lines = ['رصيدك الحالي:'];

        foreach ($wallets as $w) {
            $lines[] = '• '.$this->s($w['currency'] ?? '').': المتاح '.(int) ($w['available'] ?? 0).'، والمعلّق '.(int) ($w['pending'] ?? 0);
        }

        $lines[] = 'الجواهر المعلّقة تبقى مدة حماية ثم تصبح متاحة للإنفاق.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myProfile(array $d): string
    {
        $lines = [];

        if (! empty($d['display_name'])) {
            $lines[] = 'اسم العرض: '.$this->s($d['display_name']).'.';
        }

        if (! empty($d['member_since'])) {
            $lines[] = 'عضو منذ '.$this->s($d['member_since']).'.';
        }

        $lines[] = 'البريد الإلكتروني: '.(! empty($d['email_verified']) ? 'موثَّق' : 'غير موثَّق بعد').'.';

        if (isset(self::VISIBILITY[(string) ($d['profile_visibility'] ?? '')])) {
            $lines[] = 'ظهور ملفك: '.self::VISIBILITY[(string) $d['profile_visibility']].'.';
        }

        $lines[] = 'طلبات الصداقة: '.(! empty($d['friend_requests_enabled']) ? 'مفعَّلة' : 'متوقفة').'.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myPass(array $d): string
    {
        $p = is_array($d['pass'] ?? null) ? $d['pass'] : null;

        if ($p === null) {
            return 'لا يوجد مسار أحجيات يمكنك المشاركة فيه الآن.';
        }

        $lines = ['في مسار «'.$this->s($p['name'] ?? '').'»: أنت في المستوى '.(int) ($p['tier'] ?? 0).' من '.(int) ($p['max_tier'] ?? 0).'، ونقاط المسار لديك '.(int) ($p['pass_points'] ?? 0).'.'];

        if (($p['points_to_next_tier'] ?? 0) > 0) {
            $lines[] = 'يلزمك '.(int) $p['points_to_next_tier'].' نقطة مسار للمستوى التالي.';
        }

        $lines[] = 'مكافآت جاهزة للاستلام: '.(int) ($p['claimable_rewards'] ?? 0).'. المسار المميز: '.(! empty($p['premium_unlocked']) ? 'مفتوح لك' : 'غير مفتوح').'.';

        if (($p['days_left'] ?? 0) > 0) {
            $lines[] = 'المتبقي على انتهائه: '.(int) $p['days_left'].' يومًا.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function mySeason(array $d): string
    {
        $live = is_array($d['live_season'] ?? null) ? $d['live_season'] : null;

        if ($live === null) {
            return 'لا يوجد موسم مباشر الآن، فلا تقدّم موسميًا لعرضه.';
        }

        $lines = ['الموسم المباشر: «'.$this->s($live['name'] ?? '').'».'];
        $window = $this->window($live);

        if ($window !== null) {
            $lines[] = $window;
        }

        $p = is_array($d['my_campaign_progress'] ?? null) ? $d['my_campaign_progress'] : null;

        if ($p !== null) {
            $lines[] = 'تقدّمك في حملة الموسم: '.(int) ($p['completed_steps'] ?? 0).' من '.(int) ($p['total_steps'] ?? 0).' خطوة ('.(int) ($p['percent'] ?? 0).'%)'.(! empty($p['campaign_completed']) ? ' — أكملتها.' : '.');
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myCompetition(array $d): string
    {
        $rows = array_values(array_filter((array) ($d['participations'] ?? []), 'is_array'));

        if ($rows === []) {
            return 'لم تسجّل في أي منافسة عامة بعد. يمكنك تصفّح المنافسات المفتوحة من صفحة المنافسات.';
        }

        $lines = ['مشاركاتك في المنافسات:'];

        foreach (array_slice($rows, 0, 4) as $r) {
            $bits = [($r['my_status'] ?? '') === 'completed' ? 'أكملتها' : 'مسجَّل', self::PHASES[(string) ($r['phase'] ?? '')] ?? null];

            if (isset($r['final_rank']) && $r['final_rank'] !== null) {
                $bits[] = 'ترتيبك النهائي '.(int) $r['final_rank'];
            }

            $lines[] = '• «'.$this->s($r['competition'] ?? '').'» — '.implode('، ', array_filter($bits));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myStars(array $d): string
    {
        if (empty($d['available'])) {
            return 'دوري الكتّاب غير متاح حاليًا، فلا رصيد نجوم لعرضه.';
        }

        $lines = ['رصيد نجومك في دوري الكتّاب: الإجمالي '.(int) ($d['stars_total'] ?? 0).'، المستخدم '.(int) ($d['stars_spent'] ?? 0).'، المتبقي '.(int) ($d['stars_remaining'] ?? 0).'.'];
        $lines[] = 'التصويت '.(! empty($d['voting_open']) ? 'مفتوح الآن' : 'غير مفتوح الآن').'، ويمكنك التصويت لـ'.(int) ($d['writers_i_can_vote_for'] ?? 0).' كاتبًا، وقد منحت نجومًا لـ'.(int) ($d['writers_i_gave_stars_to'] ?? 0).'.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function mySupporter(array $d): string
    {
        if (empty($d['available'])) {
            return 'ميزة داعمي الموسم غير متاحة حاليًا.';
        }

        $lines = ['نقاط دعمك في هذا الموسم: '.(int) ($d['support_points'] ?? 0).'.'];

        if (! empty($d['card_tier'])) {
            $lines[] = 'مستوى بطاقتك: '.$this->s($d['card_tier']).'.';
        }

        if (isset($d['public_rank']) && $d['public_rank'] !== null) {
            $lines[] = 'ترتيبك العلني: '.(int) $d['public_rank'].'.';
        }

        foreach (array_slice(array_values(array_filter((array) ($d['my_cards'] ?? []), 'is_array')), 0, 3) as $c) {
            $lines[] = '• بطاقة '.$this->s($c['tier'] ?? '').' — '.$this->s($c['status'] ?? '');
        }

        $lines[] = 'نقاط الدعم تقدير للتكريم فقط، وليست مالًا ولا جواهر ولا خبرة.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myCreator(array $d): string
    {
        if (empty($d['applied'])) {
            return 'لم تقدّم طلب مبدع بعد. يمكنك التقديم من صفحة «التقديم كمبدع».';
        }

        $lines = ['حالة طلبك كمبدع: '.$this->s($d['status_label'] ?? '').'.'];

        if (isset($d['followers_count']) && $d['followers_count'] !== null) {
            $lines[] = 'المتابعون: '.(int) $d['followers_count'].'، والأحجيات المنشورة: '.(int) ($d['published_puzzles'] ?? 0).'.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $d */
    private function myCampaign(array $d): string
    {
        $lines = ['تقدّمك في حملة «'.$this->s($d['campaign'] ?? '').'»: '.(int) ($d['completed_steps'] ?? 0).' من '.(int) ($d['total_steps'] ?? 0).' خطوة ('.(int) ($d['percent'] ?? 0).'%).'];

        if (! empty($d['campaign_completed'])) {
            $lines[] = 'أكملت هذه الحملة.';
        }

        return implode("\n", $lines);
    }

    // ───────────────────────────── مساعدات ─────────────────────────────

    /** @param array<string, mixed> $season */
    private function window(array $season): ?string
    {
        $from = $season['starts_at'] ?? null;
        $to = $season['ends_at'] ?? null;

        return match (true) {
            $from && $to => 'من '.$this->s($from).' إلى '.$this->s($to).'.',
            (bool) $to => 'ينتهي في '.$this->s($to).'.',
            (bool) $from => 'بدأ في '.$this->s($from).'.',
            default => null,
        };
    }

    private function s(mixed $value): string
    {
        return trim((string) (Presenter::text(is_scalar($value) ? (string) $value : '', 160) ?? ''));
    }

    /** @return list<array{label: string, url: string}> */
    private function sources(AiToolResult $result): array
    {
        $out = [];

        foreach ($result->sources as $source) {
            $clean = AiLinkPolicy::source((string) ($source['label'] ?? ''), $source['url'] ?? null);

            if ($clean !== null) {
                $out[$clean['url']] ??= $clean;
            }
        }

        return array_slice(array_values($out), 0, 3);
    }

    /** @return list<array{label: string, url: string}> */
    private function helpSources(): array
    {
        $help = Presenter::link('دليل المنصة', Presenter::path('help.index'));

        return $help === null ? [] : [$help];
    }
}
