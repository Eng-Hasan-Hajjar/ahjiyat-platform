<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Safety\CannedReplies;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\AiAssistant\Tools\ToolRegistry;
use App\Services\Search\TextFold;

/**
 * TATAWWUR-FINAL - توصيل أدوات القراءة الست التي كانت غير موصولة بالمحرك الداخلي، عبر موجِّه كيانات حتمي:
 *   search_platform · get_platform_guide_article · get_season_information · get_creator_public_information ·
 *   get_creator_series_public_information · get_my_premium_series_access.
 *
 * يعمل فقط تحت علم الدلالة المحلية (مغلق افتراضيًا)، وبعد حراس E45 كلها وبعد فحص المحرك للتصنيفات المحجوبة. القواعد الصارمة:
 *  - الوسيط الوحيد الممرَّر للأداة يأتي من (أ) عنوان مقتبس بين علامتي اقتباس، أو (ب) رابط داخلي بصيغة /seasons|/series|/creators، أو (ج) معرّف ULID، أو (د) معرّف مقال من كتالوج المحرك نفسه؛
 *    ولا يُستخرج شيء من النص الحرّ سوى عبارة بحث بعد فعل بحث صريح. لا هوية مستخدم (user_id/email/اسم) تُمرَّر أبدًا؛ أداة «حسابي» تأخذ المستخدم من ToolContext وحده.
 *  - اسم موسم/سلسلة يُحلّ عبر search_platform نفسها؛ إن تعدد المرشحون يُطلب توضيح بأسماء عامة، وإن غاب أي مرشح تُعاد رسالة «لم أجد» دون تخمين.
 *  - مبدع بلا رابط/معرّف صريح لا يُخمَّن له معرّف: يُطلب الرابط (لا أداة بحث عن مبدعين في E45، ولم تُضَف لأنها خارج النطاق).
 *  - لا كتابة ولا لمس لخدمات الاقتصاد؛ كل ناتج صياغة قالب ثابت من حقول الأداة نفسها.
 */
final class EntityTools
{
    public const REASON = 'local_engine_entity';

    private const SEARCH_TYPES = [
        'creator_series' => ['سلسله', 'سلاسل'], 'seasons' => ['موسم', 'مواسم'], 'campaigns' => ['حمله', 'حملات'], 'competitions' => ['منافسه', 'منافسات'],
        'championships' => ['بطوله', 'بطولات'], 'teams' => ['فريق', 'فرق'], 'collections' => ['مجموعه', 'مجموعات'], 'puzzles' => ['احجيه', 'احجيات', 'لغز', 'الغاز'],
    ];

    private const SEARCH_VERBS = ['ابحث', 'ابحثلي', 'دورلي', 'دور لي', 'لاقيلي', 'لاقي لي', 'فتش', 'ادور', 'بدور', 'دورلنا'];

    /** سؤال «كيف» عن طريقة البحث ليس أمر بحث: يبقى للمعرفة (search.how). */
    private const HOW_WORDS = ['كيف', 'طريقه', 'ازاي', 'كيفاش', 'شلون', 'اشلون', 'خطوات'];

    private const INFO_WORDS = ['تفاصيل', 'معلومات', 'مراحل', 'مراحله', 'مراحلها', 'سعر', 'بكم', 'وصف', 'كم احجيه', 'كم لغز', 'مميزه', 'نبذه', 'متابعين', 'اعمال', 'من هو', 'من هي', 'متي يبدا', 'متي ينتهي', 'حاله', 'جايزه', 'عن'];

    /** كلمات عامة لا تصلح عنوانًا لبحث (يُترك السؤال للمعرفة). */
    private const GENERIC = ['شرح', 'للشرح', 'دليل', 'الدليل', 'مساعده', 'المساعده', 'ميزات', 'للميزات', 'الميزات', 'معلومات', 'تعليمات', 'طريقه', 'خطوات', 'اجابه', 'سؤال', 'ميزه', 'كل', 'شيء', 'شي'];

    private const MY_ACCESS = ['هل املك', 'هل اشتريت', 'وصولي', 'عندي وصول', 'لي وصول', 'مفتوحه لي', 'اقدر افتح', 'اقدر الوصول', 'ملكتها', 'اشتريتها', 'هل عندي', 'هل لدي', 'هل افتحها'];

    public function __construct(private readonly ToolRegistry $tools) {}

    /** أسماء الأدوات التي يوصلها هذا الصنف (للتوثيق والاختبار). @return list<string> */
    public static function wiredTools(): array
    {
        return ['search_platform', 'get_platform_guide_article', 'get_season_information', 'get_creator_public_information', 'get_creator_series_public_information', 'get_my_premium_series_access'];
    }

    /** هل يبدو النص طلبًا لإحدى هذه الأدوات؟ (فحص رخيص بلا أي استدعاء). */
    public function applies(string $text): bool
    {
        return $this->plan($text) !== null;
    }

    public function answer(string $text, ToolContext $context): ?AssistantAnswer
    {
        $plan = $this->plan($text);

        if ($plan === null) {
            return null;
        }

        return match ($plan['kind']) {
            'search' => $this->search($plan['query'], $plan['type'], $context),
            'creator' => $this->creator($plan['slug'], $context),
            'season' => $this->season($plan['slug'], $plan['title'], $context),
            'series' => $this->series($plan['id'], $plan['title'], $context),
            'my_series' => $this->mySeries($plan['id'], $plan['title'], $context),
            'ask' => $this->ask($plan['what']),
        };
    }

    /** عرض مقال دليل بمعرّفه من كتالوج المحرك (لا من نص السائل). */
    public function guideArticle(string $slug, ToolContext $context): ?AssistantAnswer
    {
        $result = $this->run('get_platform_guide_article', ['slug' => $slug], $context);

        if (! $result->ok) {
            return $this->failure($result, 'general');
        }

        $article = is_array($result->data['article'] ?? null) ? $result->data['article'] : [];
        $title = $this->s($article['title'] ?? '', 160);
        $summary = $this->s($article['summary'] ?? '', 400);
        $body = $this->s($article['text'] ?? '', 900);

        if ($title === '') {
            return null;
        }

        $lines = ['مقال الدليل: «'.$title.'»'];

        if ($summary !== '') {
            $lines[] = $summary;
        }

        if ($body !== '' && $body !== $summary) {
            $lines[] = $body;
        }

        return $this->answered(implode("\n", $lines), $result, 'general');
    }

    // ───────────────────────────── التخطيط (استخراج الكيان) ─────────────────────────────

    /** @return array<string, mixed>|null */
    private function plan(string $text): ?array
    {
        $folded = trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($text)));

        if ($folded === '') {
            return null;
        }

        $title = $this->quoted($text);
        $creatorSlug = $this->match('~/creators/([a-z0-9][a-z0-9_-]{1,99})~i', $text);
        $seriesId = $this->match('~/series/([A-Za-z0-9_-]{3,110})~', $text) ?? $this->ulid($text);
        $seasonSlug = $this->match('~/seasons/([a-z0-9][a-z0-9_-]{0,99})~i', $text);

        $isSeries = $this->has($folded, ['سلسله', 'سلاسل']) || $seriesId !== null;
        $isSeason = $this->has($folded, ['موسم', 'مواسم']) || $seasonSlug !== null;
        $isCreator = $this->has($folded, ['مبدع', 'مبدعه', 'مبدعين', 'صانع محتوي']) || $creatorSlug !== null;

        if ($isSeries && ($seriesId !== null || $title !== null) && $this->has($folded, self::MY_ACCESS)) {
            return ['kind' => 'my_series', 'id' => $seriesId, 'title' => $title];
        }

        if ($isSeries && ($seriesId !== null || $title !== null) && $this->has($folded, self::INFO_WORDS)) {
            return ['kind' => 'series', 'id' => $seriesId, 'title' => $title];
        }

        if ($isCreator && $creatorSlug !== null) {
            return ['kind' => 'creator', 'slug' => $creatorSlug];
        }

        if ($isCreator && $title !== null && ! $isSeries && $this->has($folded, self::INFO_WORDS)) {
            return ['kind' => 'ask', 'what' => 'creator'];
        }

        if ($isSeason && ($seasonSlug !== null || $title !== null) && $this->has($folded, self::INFO_WORDS)) {
            return ['kind' => 'season', 'slug' => $seasonSlug, 'title' => $title];
        }

        $query = $this->searchQuery($folded, $title);

        if ($query !== null) {
            return ['kind' => 'search', 'query' => $query, 'type' => $this->searchType($folded)];
        }

        return null;
    }

    private function searchQuery(string $folded, ?string $title): ?string
    {
        $verb = false;

        if ($this->has($folded, self::HOW_WORDS)) {
            return null;
        }

        foreach (self::SEARCH_VERBS as $v) {
            if (preg_match('/(^|\s)'.preg_quote($v, '/').'(\s|$)/u', $folded) === 1) {
                $verb = true;
                break;
            }
        }

        if (! $verb) {
            return null;
        }

        if ($title !== null) {
            return $this->cleanQuery($title);
        }

        if (preg_match('/(?:عن|علي|على|باسم|بعنوان)\s+(.{2,80})$/u', $folded, $m) !== 1) {
            return null;
        }

        $words = array_values(array_filter(explode(' ', $m[1]), fn (string $w) => ! in_array($w, ['لي', 'في', 'المنصه', 'بالمنصه', 'الموقع', 'من', 'فضلك', 'لو', 'سمحت', 'احجيه', 'احجيات', 'لغز', 'الغاز', 'سلسله', 'سلاسل', 'موسم', 'فريق', 'منافسه', 'بطوله', 'حمله', 'مجموعه', 'اسمها', 'اسمه', 'التي', 'الذي', 'اسم'], true)));

        // كلمات عامة (طلب شرح/مساعدة لا عنوان محدد) ⇒ ليست بحثًا عن عنصر؛ تبقى للمعرفة.
        $words = array_values(array_filter($words, fn (string $w) => ! in_array($w, self::GENERIC, true)));

        return $words === [] ? null : $this->cleanQuery(implode(' ', $words));
    }

    private function cleanQuery(string $q): ?string
    {
        $q = trim((string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $q));
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));

        return mb_strlen($q) >= 2 && mb_strlen($q) <= 80 ? $q : null;
    }

    private function searchType(string $folded): ?string
    {
        foreach (self::SEARCH_TYPES as $type => $words) {
            if ($this->has($folded, $words)) {
                return $type;
            }
        }

        return null;
    }

    private function quoted(string $text): ?string
    {
        if (preg_match('/[«"“„]([^«»"“”„]{2,80})[»"”“]/u', $text, $m) !== 1) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $m[1]));

        return $name === '' ? null : $name;
    }

    private function ulid(string $text): ?string
    {
        return preg_match('/\b([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})\b/', $text, $m) === 1 ? $m[1] : null;
    }

    private function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * مطابقة كلمة/عبارة كاملة مع السوابق العربية الشائعة (ال، وال، بال، لل، و، ب، ل) لأن «الموسم» و«بالسلسلة» يجب أن تطابق «موسم» و«سلسلة».
     *
     * @param  list<string>  $words
     */
    private function has(string $folded, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(^|\s)(?:وال|بال|لل|ال|و|ب|ل)?'.preg_quote($w, '/').'(\s|$)/u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    // ───────────────────────────── التنفيذ ─────────────────────────────

    private function search(string $query, ?string $type, ToolContext $context): AssistantAnswer
    {
        $args = ['query' => $query] + ($type !== null ? ['type' => $type] : []);
        $result = $this->run('search_platform', $args, $context);

        if (! $result->ok) {
            return $this->failure($result, 'search');
        }

        $state = (string) ($result->data['state'] ?? 'ok');
        $rows = array_values(array_filter((array) ($result->data['results'] ?? []), 'is_array'));

        if ($state === 'too_short' || $state === 'empty') {
            return $this->clarification('عبارة البحث قصيرة جدًا. اكتب كلمتين أو عنوانًا أوضح لأبحث عنه في المنصة.', 'search', 1);
        }

        if ($rows === []) {
            return $this->notFound('لم أجد في المنصة ما يطابق «'.$query.'». جرّب كلمات أخرى، أو تصفّح الصفحة المعنية مباشرة.', 'search');
        }

        $lines = ['وجدت في المنصة ما يطابق «'.$query.'»:'];

        foreach (array_slice($rows, 0, 5) as $row) {
            $line = '• '.$this->s($row['type'] ?? '', 40).': '.$this->s($row['title'] ?? '', 120);
            $sub = $this->s($row['subtitle'] ?? '', 120);
            $lines[] = $sub !== '' ? $line.' — '.$sub : $line;
        }

        return $this->answered(implode("\n", $lines), $result, 'search');
    }

    private function creator(string $slug, ToolContext $context): AssistantAnswer
    {
        $result = $this->run('get_creator_public_information', ['slug' => $slug], $context);

        if (! $result->ok) {
            return $this->failure($result, 'creators');
        }

        $c = is_array($result->data['creator'] ?? null) ? $result->data['creator'] : [];
        $lines = ['المبدع: «'.$this->s($c['name'] ?? '', 80).'».'];

        if (! empty($c['bio'])) {
            $lines[] = $this->s($c['bio'], 280);
        }

        $lines[] = 'عدد المتابعين: '.(int) ($c['followers_count'] ?? 0).'، وعدد الألغاز المنشورة: '.(int) ($c['published_puzzles'] ?? 0).'.';

        return $this->answered(implode("\n", $lines), $result, 'creators');
    }

    private function season(?string $slug, ?string $title, ToolContext $context): AssistantAnswer
    {
        if ($slug === null) {
            $resolved = $this->resolve((string) $title, 'seasons', '~^/seasons/([A-Za-z0-9_-]{1,100})$~', $context);

            if ($resolved instanceof AssistantAnswer) {
                return $resolved;
            }

            $slug = $resolved;
        }

        $result = $this->run('get_season_information', ['slug' => $slug], $context);

        if (! $result->ok) {
            return $this->failure($result, 'seasons');
        }

        $d = $result->data;
        $lines = ['الموسم: «'.$this->s($d['name'] ?? '', 120).'» — '.$this->s($d['state_label'] ?? '', 60).'.'];

        if (! empty($d['tagline'])) {
            $lines[] = $this->s($d['tagline'], 160);
        }

        if (! empty($d['starts_at']) || ! empty($d['ends_at'])) {
            $lines[] = match (true) {
                ! empty($d['starts_at']) && ! empty($d['ends_at']) => 'من '.$this->s($d['starts_at']).' إلى '.$this->s($d['ends_at']).'.',
                ! empty($d['ends_at']) => 'ينتهي في '.$this->s($d['ends_at']).'.',
                default => 'يبدأ في '.$this->s($d['starts_at']).'.',
            };
        }

        $phases = array_values(array_filter((array) ($d['phases'] ?? []), 'is_array'));

        if ($phases !== []) {
            $lines[] = 'المراحل: '.implode('، ', array_map(fn (array $p) => $this->s($p['name'] ?? '', 80), array_slice($phases, 0, 8))).'.';
        }

        if (! empty($d['prize_description'])) {
            $lines[] = 'الجائزة الكبرى: '.$this->s($d['prize_description'], 240);
        }

        $lines[] = ($d['writers_league_available'] ?? false) ? 'دوري الكتّاب متاح في هذا الموسم.' : 'دوري الكتّاب غير متاح في هذا الموسم حاليًا.';

        return $this->answered(implode("\n", $lines), $result, 'seasons');
    }

    private function series(?string $id, ?string $title, ToolContext $context): AssistantAnswer
    {
        if ($id === null) {
            $resolved = $this->resolve((string) $title, 'creator_series', '~^/series/([A-Za-z0-9_-]{3,110})$~', $context);

            if ($resolved instanceof AssistantAnswer) {
                return $resolved;
            }

            $id = $resolved;
        }

        $result = $this->run('get_creator_series_public_information', ['series' => $id], $context);

        if (! $result->ok) {
            return $this->failure($result, 'creator_series');
        }

        $s = is_array($result->data['series'] ?? null) ? $result->data['series'] : [];
        $lines = ['السلسلة: «'.$this->s($s['title'] ?? '', 120).'»'.(! empty($s['creator']) ? ' للمبدع «'.$this->s($s['creator'], 80).'».' : '.')];

        if (! empty($s['description'])) {
            $lines[] = $this->s($s['description'], 280);
        }

        $lines[] = 'عدد الألغاز المنشورة فيها: '.(int) ($s['puzzles_count'] ?? 0).'.';

        if ($s['is_premium'] ?? false) {
            $lines[] = 'هذه سلسلة مميزة (بوصول مدفوع)'.(! empty($s['premium_price']) ? ' بسعر '.$this->s($s['premium_price'], 40) : '').'، '.(($s['premium_on_sale'] ?? false) ? 'والبيع متاح الآن.' : 'والبيع غير متاح الآن.');
        } else {
            $lines[] = 'هذه سلسلة مجانية.';
        }

        return $this->answered(implode("\n", $lines), $result, 'creator_series');
    }

    private function mySeries(?string $id, ?string $title, ToolContext $context): AssistantAnswer
    {
        if (! $context->hasMember()) {
            return new AssistantAnswer(CannedReplies::NEEDS_VERIFIED, $this->helpSources(), 'answered', 'needs_verified', null, false);
        }

        if ($id === null) {
            $resolved = $this->resolve((string) $title, 'creator_series', '~^/series/([A-Za-z0-9_-]{3,110})$~', $context);

            if ($resolved instanceof AssistantAnswer) {
                return $resolved;
            }

            $id = $resolved;
        }

        $result = $this->run('get_my_premium_series_access', ['series' => $id], $context);

        if (! $result->ok) {
            return $this->failure($result, 'creator_series');
        }

        $name = $this->s($result->data['series'] ?? '', 120);
        $label = $name !== '' ? '«'.$name.'»' : 'هذه السلسلة';
        $text = match ((string) ($result->data['my_access'] ?? '')) {
            'free' => $label.' سلسلة مجانية، فالوصول إليها متاح لك دون شراء.',
            'creator' => $label.' من سلاسلك أنت كمبدع، فالوصول إليها متاح لك.',
            'purchased' => 'نعم، لديك وصول إلى '.$label.' لأنك اشتريتها.',
            'not_purchased' => $label.' سلسلة مميزة ولم تشترِ الوصول إليها بعد.',
            default => 'تعذّر تحديد حالة وصولك إلى '.$label.' الآن.',
        };

        return $this->answered($text, $result, 'creator_series');
    }

    private function ask(string $what): AssistantAnswer
    {
        $text = $what === 'creator'
            ? 'لأعرض معلومات مبدع محدد أحتاج رابط صفحته في المنصة (يبدأ بـ /creators/) أو معرّفه القصير؛ فلا أخمّن معرّفًا من الاسم.'
            : 'أخبرني بالاسم أو الرابط الذي تقصده.';

        return $this->clarification($text, $what === 'creator' ? 'creators' : null);
    }

    /**
     * يحلّ عنوانًا مقتبسًا إلى معرّف عبر search_platform. @return string|AssistantAnswer معرّف مُحلَّل، أو ردّ جاهز (توضيح/لم أجد/فشل).
     */
    private function resolve(string $title, string $type, string $pathPattern, ToolContext $context): string|AssistantAnswer
    {
        $query = $this->cleanQuery($title);

        if ($query === null) {
            return $this->clarification('اكتب اسمًا أوضح بين علامتي اقتباس لأبحث عنه.', 'search');
        }

        $result = $this->run('search_platform', ['query' => $query, 'type' => $type], $context);

        if (! $result->ok) {
            return $this->failure($result, 'search');
        }

        $candidates = [];

        foreach (array_filter((array) ($result->data['results'] ?? []), 'is_array') as $row) {
            if (preg_match($pathPattern, (string) ($row['path'] ?? ''), $m) === 1) {
                $candidates[$m[1]] = $this->s($row['title'] ?? '', 120);
            }
        }

        if ($candidates === []) {
            return $this->notFound('لم أجد في المنصة عنصرًا ظاهرًا بالاسم «'.$query.'». تأكد من الكتابة أو أرسل رابط صفحته.', 'search');
        }

        if (count($candidates) === 1) {
            return (string) array_key_first($candidates);
        }

        $folded = TextFold::fold($query);
        $exact = array_keys(array_filter($candidates, fn (string $t) => TextFold::fold($t) === $folded));

        if (count($exact) === 1) {
            return (string) $exact[0];
        }

        return $this->clarification(EnhancedReplies::clarify(array_values(array_slice($candidates, 0, 4))), 'search', 1);
    }

    private function run(string $tool, array $arguments, ToolContext $context): AiToolResult
    {
        return $this->tools->execute(new AiToolCall('local-'.$tool, $tool, $arguments), $context);
    }

    private function failure(AiToolResult $result, ?string $area): AssistantAnswer
    {
        $message = is_string($result->data['message'] ?? null) ? (string) $result->data['message'] : null;

        return match ($result->error) {
            'not_found' => new AssistantAnswer($message ?? 'لم أجد ما يطابق طلبك في المنصة.', $this->helpSources(), 'answered', self::REASON, $area, true, false, 1),
            'forbidden' => new AssistantAnswer(CannedReplies::NEEDS_VERIFIED, $this->helpSources(), 'answered', 'needs_verified', $area, false, false, 1),
            'unavailable', 'disabled' => new AssistantAnswer($message ?? EnhancedReplies::LIVE_FAILURE, $this->helpSources(), 'fallback', 'tool_unavailable', $area, false, false, 1),
            default => new AssistantAnswer(EnhancedReplies::LIVE_FAILURE, $this->helpSources(), 'fallback', 'tool_unavailable', $area, false, false, 1),
        };
    }

    private function answered(string $text, AiToolResult $result, ?string $area): AssistantAnswer
    {
        $sources = [];

        foreach ($result->sources as $source) {
            $clean = AiLinkPolicy::source((string) ($source['label'] ?? ''), $source['url'] ?? null);

            if ($clean !== null) {
                $sources[$clean['url']] ??= $clean;
            }
        }

        return new AssistantAnswer($text, array_slice(array_values($sources), 0, 3), 'answered', self::REASON, $area, true, false, 1);
    }

    /** طلب توضيح (لا ادّعاء حقيقة): reason=clarify وغير مؤسَّس. */
    private function clarification(string $text, ?string $area, int $calls = 0): AssistantAnswer
    {
        return new AssistantAnswer($text, $this->helpSources(), 'answered', 'clarify', $area, false, false, $calls);
    }

    /** نتيجة بحث/جلب سليمة لكن بلا عنصر مطابق: ردّ مؤسَّس على أداة نفّذناها. */
    private function notFound(string $text, ?string $area): AssistantAnswer
    {
        return new AssistantAnswer($text, $this->helpSources(), 'answered', self::REASON, $area, true, false, 1);
    }

    private function s(mixed $value, int $max = 160): string
    {
        return trim((string) (Presenter::text(is_scalar($value) ? (string) $value : '', $max) ?? ''));
    }

    /** @return list<array{label: string, url: string}> */
    private function helpSources(): array
    {
        $help = Presenter::link('دليل المنصة', Presenter::path('help.index'));

        return $help === null ? [] : [$help];
    }
}
