<?php

namespace App\Services\LiveOps;

use App\Models\MissionPool;
use App\Models\PlayerProgression;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E34 - إسناد المهام لكل مستخدم من مجموعات المهام. **يحدّد الظهور فقط**: لا يمنح ولا يحسب تقدّمًا ولا يكتب مكافأة.
 * التقدّم والمكافآت يبقيان بالكامل في QuestService/UserQuestProgress/ProgressionRewardService (لا محرك ثانٍ).
 *
 * الخصائص المقصودة:
 *  - كسول: لا صفوف جماعية. صف الإسناد يُنشأ لمستخدم واحد، عند فتح فعلي أو حدث لعب، وبحدّ selection_count.
 *  - لقطة ثابتة: بعد أن يملك المستخدم إسنادًا للفترة، لا تغيّره تعديلات المجموعات (تسري على الفترة التالية).
 *  - حتمي: sha1(user|period|pool) ثم sha1(seed|quest) - لا random() ولا حالة خفية؛ نفس المدخلات = نفس المهام.
 *  - آمن تحت التزامن: معاملة + قفل صف المستخدم + إعادة فحص تحت القفل + UNIQUE + insertOrIgnore كحاجز أخير.
 *  - مهمة غير منتمية لأي مجموعة = مهمة عامة تعمل كما كانت (توافق خلفي كامل).
 *  - مهمة منتمية لمجموعة لا تظهر لغير من أُسندت له (أو من له تقدّم قائم بالفترة الحالية = استمرارية).
 *
 * الإشارات المستعملة للتخصيص (شفافة وقائمة على قواعد): المستوى، فئات الأحجيات **الرسمية** المحلولة مؤخرًا، تقدّم الحملات الأخير،
 * وتقدّم غير مكتمل قائم بالمهمة. مستبعد صراحةً: الدردشة، المدفوعات، مبالغ الرعاية، أعلام الاحتيال، البريد، الملف الخاص.
 *
 * ليست singleton عمدًا: الذاكرة المؤقتة (memo) بعمر المثيل الذي يحمله QuestService الواحد (طلب/حدث واحد)، فلا بيانات قديمة عبر الطلبات.
 */
class MissionAssignmentService
{
    public const CACHE_KEY = 'liveops:mission-pools:v1';

    /** @var array<string, list<int>> */
    private array $assignedMemo = [];

    /** @var array<string, list<int>> */
    private array $progressMemo = [];

    /** @var array<int, array{level:int,last_active_date:?string}> */
    private array $factsMemo = [];

    /** @var array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}|null */
    private ?array $snapshotMemo = null;

    public function __construct(
        protected QuestPeriodService $periods,
        protected QuestEvaluatorRegistry $registry,
    ) {}

    // ───────────────────────── لقطة التعريفات المشتركة (بلا بيانات مستخدم) ─────────────────────────

    /**
     * @return array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}
     */
    public function snapshot(): array
    {
        return $this->snapshotMemo ??= $this->loadSnapshot();
    }

    /**
     * @return array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}
     */
    protected function loadSnapshot(): array
    {
        $seconds = max(0, (int) config('liveops.pools.cache_seconds', 300));

        $build = function (): array {
            $pools = MissionPool::query()->orderBy('priority')->orderBy('id')->get();
            $members = DB::table('mission_pool_quests')->orderBy('sort_order')->orderBy('quest_definition_id')->get(['mission_pool_id', 'quest_definition_id'])
                ->groupBy('mission_pool_id');

            $out = ['pools' => [], 'pooled' => []];

            foreach ($pools as $pool) {
                $ids = ($members[$pool->id] ?? collect())->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

                $out['pools'][$pool->id] = [
                    'id' => (int) $pool->id,
                    'period_type' => (string) $pool->period_type,
                    'selection_count' => (int) $pool->selection_count,
                    'priority' => (int) $pool->priority,
                    'eligibility_rule' => (string) $pool->eligibility_rule,
                    'personalized' => (bool) $pool->personalized,
                    'is_active' => (bool) $pool->is_active,
                    'starts_at' => $pool->starts_at?->toIso8601String(),
                    'ends_at' => $pool->ends_at?->toIso8601String(),
                    'quest_ids' => $ids,
                ];

                foreach ($ids as $id) {
                    $out['pooled'][$id] = true;
                }
            }

            return $out;
        };

        if ($seconds === 0) {
            return $build();
        }

        return Cache::remember(self::CACHE_KEY, $seconds, $build);
    }

    public static function flushSnapshot(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function forgetMemo(): void
    {
        $this->assignedMemo = [];
        $this->progressMemo = [];
        $this->factsMemo = [];
        $this->snapshotMemo = null;
    }

    /** هل المهمة منتمية لأي مجموعة (فتُعرض بالإسناد فقط)؟ */
    public function isPooled(QuestDefinition $quest): bool
    {
        return isset($this->snapshot()['pooled'][$quest->id]);
    }

    /** @return list<int> */
    public function pooledQuestIds(): array
    {
        return array_map('intval', array_keys($this->snapshot()['pooled']));
    }

    // ───────────────────────── الواجهة التي يستعملها QuestService ─────────────────────────

    /**
     * هل يجوز عرض/تقييم هذه المهمة لهذا المستخدم في هذه الفترة؟
     * غير منتمية لمجموعة = نعم (سلوك E13 كما هو). منتمية = مُسندة له أو لديه تقدّم قائم بالفترة (استمرارية).
     * أي خلل داخلي يُغلق بأمان للمهام المجمَّعة فقط (لا تظهر) ولا يكسر اللعب.
     */
    public function isAllowed(User $user, QuestDefinition $quest, PeriodContext $period): bool
    {
        if (! $this->isPooled($quest)) {
            return true;
        }

        try {
            if (in_array((int) $quest->id, $this->assignedQuestIds($user, $period), true)) {
                return true;
            }

            return in_array((int) $quest->id, $this->progressQuestIds($user, $period), true);
        } catch (\Throwable $e) {
            Log::error('فشل فحص إسناد المهمة', ['user_id' => $user->id, 'quest_id' => $quest->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * معرّفات المهام المُسندة للمستخدم في هذه الفترة (تُنشئ الإسناد كسولًا عند أول طلب، إن وُجدت مجموعة نشطة مؤهِّلة).
     *
     * @return list<int>
     */
    public function assignedQuestIds(User $user, PeriodContext $period): array
    {
        $key = $user->id.'|'.$period->periodKey;

        if (isset($this->assignedMemo[$key])) {
            return $this->assignedMemo[$key];
        }

        $existing = $this->existingIds($user, $period->periodKey);

        if ($existing !== []) {
            return $this->assignedMemo[$key] = $existing;
        }

        $pool = $this->choosePool($user, $period);

        if ($pool === null) {
            return $this->assignedMemo[$key] = [];
        }

        return $this->assignedMemo[$key] = $this->assignWithinLock($user, $pool, $period);
    }

    // ───────────────────────── الاختيار والإسناد ─────────────────────────

    /**
     * أول مجموعة (بالأولوية ثم المعرّف) نشطة الآن لنوع الفترة ومؤهِّل لها المستخدم ولديها ما تُسنده فعليًا.
     * المجموعة القياسية = مجموعة قاعدتها "all" بأقل أولوية (آخر ما يُفحص) فتعمل كاحتياطي.
     *
     * @return array<string,mixed>|null
     */
    protected function choosePool(User $user, PeriodContext $period): ?array
    {
        $now = now();
        $facts = null;

        foreach ($this->snapshot()['pools'] as $pool) {
            if (! $pool['is_active'] || $pool['period_type'] !== $period->periodType || $pool['quest_ids'] === []) {
                continue;
            }

            if ($pool['starts_at'] !== null && $now->lt($pool['starts_at'])) {
                continue;
            }

            if ($pool['ends_at'] !== null && $now->gt($pool['ends_at'])) {
                continue;
            }

            $rule = MissionEligibilityRule::tryFrom($pool['eligibility_rule']) ?? MissionEligibilityRule::All;

            if ($rule !== MissionEligibilityRule::All) {
                $facts ??= $this->factsFor($user);

                if (! $rule->check($user, $facts)) {
                    continue;
                }
            }

            if ($this->candidates($pool, $period) === []) {
                continue;
            }

            return $pool;
        }

        return null;
    }

    /**
     * المهام الصالحة فعليًا للإسناد: نشطة، ضمن نافذتها الزمنية الآن، نفس نوع فترة المجموعة، ولها مُقيِّم مدعوم.
     *
     * @param  array<string,mixed>  $pool
     * @return list<QuestDefinition>
     */
    protected function candidates(array $pool, PeriodContext $period): array
    {
        $now = now();

        return QuestDefinition::query()
            ->whereIn('id', $pool['quest_ids'])
            ->where('is_active', true)
            ->where('period_type', $pool['period_type'])
            ->get()
            ->filter(fn (QuestDefinition $q) => ($q->starts_at === null || ! $now->lt($q->starts_at))
                && ($q->ends_at === null || ! $now->gt($q->ends_at))
                && $this->registry->evaluatorFor($q->condition_type) !== null)
            ->values()
            ->all();
    }

    /**
     * يكتب لقطة الإسناد تحت قفل صف المستخدم. عند وجود إسناد سابق (سباق خسرناه) يُعاد كما هو بلا إضافة.
     *
     * @param  array<string,mixed>  $pool
     * @return list<int>
     */
    public function assignWithinLock(User $user, array $pool, PeriodContext $period): array
    {
        $ids = DB::transaction(function () use ($user, $pool, $period) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);

            $existing = $this->existingIds($user, $period->periodKey);

            if ($existing !== []) {
                return $existing;
            }

            $selected = $this->select($user, $pool, $period);
            $now = now();
            $rows = [];

            foreach ($selected as $slot => $quest) {
                $rows[] = [
                    'user_id' => $user->id,
                    'mission_pool_id' => $pool['id'],
                    'quest_definition_id' => $quest->id,
                    'period_key' => $period->periodKey,
                    'slot' => min(255, $slot),
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                UserMissionAssignment::query()->insertOrIgnore($rows);
            }

            return $this->existingIds($user, $period->periodKey);
        });

        $this->assignedMemo[$user->id.'|'.$period->periodKey] = $ids;

        return $ids;
    }

    /**
     * اختيار حتمي: أعلى تفضيل شفاف (إن كان التخصيص مفعّلًا) ثم ترتيب sha1 ثابت، ثم المعرّف كفاصل نهائي.
     *
     * @param  array<string,mixed>  $pool
     * @return list<QuestDefinition>
     */
    public function select(User $user, array $pool, PeriodContext $period): array
    {
        $candidates = $this->candidates($pool, $period);

        if ($candidates === []) {
            return [];
        }

        $count = max(1, min((int) $pool['selection_count'], (int) config('liveops.pools.max_selection', 10)));
        $seed = $user->id.'|'.$period->periodKey.'|'.$pool['id'];
        $affinity = ($pool['personalized'] && config('liveops.pools.personalization', true))
            ? $this->affinities($user, $candidates, $period)
            : [];

        $scored = [];

        foreach ($candidates as $quest) {
            $scored[] = [
                'quest' => $quest,
                'affinity' => $affinity[$quest->id] ?? 0,
                'hash' => hexdec(substr(sha1($seed.'|'.$quest->id), 0, 12)),
            ];
        }

        usort($scored, fn (array $a, array $b) => [$b['affinity'], $a['hash'], $a['quest']->id] <=> [$a['affinity'], $b['hash'], $b['quest']->id]);

        return array_map(fn (array $s) => $s['quest'], array_slice($scored, 0, $count));
    }

    /**
     * تفضيل شفاف قائم على قواعد (لا ML): +3 تقدّم غير مكتمل قائم، +2 فئة أحجيات رسمية لُعبت مؤخرًا، +2 نشاط حملة حديث.
     *
     * @param  list<QuestDefinition>  $candidates
     * @return array<int,int>
     */
    protected function affinities(User $user, array $candidates, PeriodContext $period): array
    {
        $days = max(1, (int) config('liveops.pools.recent_category_days', 14));
        $since = now()->subDays($days);
        $ids = array_map(fn (QuestDefinition $q) => $q->id, $candidates);

        $open = UserQuestProgress::query()
            ->where('user_id', $user->id)
            ->where('period_key', $period->periodKey)
            ->whereIn('quest_definition_id', $ids)
            ->whereNull('completed_at')
            ->where('current_value', '>', 0)
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

        $needsCategory = collect($candidates)->contains(fn (QuestDefinition $q) => $q->scope_type === 'puzzle_category');
        $categories = [];

        if ($needsCategory) {
            $categories = PuzzleAttempt::query()
                ->join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
                ->where('puzzle_attempts.user_id', $user->id)
                ->where('puzzle_attempts.is_correct', true)
                ->where('puzzle_attempts.created_at', '>=', $since)
                ->where('puzzles.origin', \App\Models\Puzzle::ORIGIN_OFFICIAL)
                ->distinct()
                ->pluck('puzzles.puzzle_category_id')->map(fn ($i) => (int) $i)->all();
        }

        $campaignActive = false;

        if (collect($candidates)->contains(fn (QuestDefinition $q) => $q->condition_type === 'campaign_steps_completed')) {
            $campaignActive = UserCampaignProgress::query()->where('user_id', $user->id)->where('updated_at', '>=', $since)->exists();
        }

        $out = [];

        foreach ($candidates as $quest) {
            $score = 0;

            if (in_array((int) $quest->id, $open, true)) {
                $score += 3;
            }

            if ($quest->scope_type === 'puzzle_category' && in_array((int) $quest->scope_id, $categories, true)) {
                $score += 2;
            }

            if ($quest->condition_type === 'campaign_steps_completed' && $campaignActive) {
                $score += 2;
            }

            $out[$quest->id] = $score;
        }

        return $out;
    }

    // ───────────────────────── قراءات مساعدة ─────────────────────────

    /** @return list<int> */
    protected function existingIds(User $user, string $periodKey): array
    {
        return UserMissionAssignment::query()
            ->where('user_id', $user->id)
            ->where('period_key', $periodKey)
            ->orderBy('slot')->orderBy('quest_definition_id')
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /** تقدّم قائم للمستخدم بالفترة الحالية (استمرارية لمهمة صارت منتمية لمجموعة بعد أن بدأها). @return list<int> */
    protected function progressQuestIds(User $user, PeriodContext $period): array
    {
        $key = $user->id.'|'.$period->periodKey;

        return $this->progressMemo[$key] ??= UserQuestProgress::query()
            ->where('user_id', $user->id)
            ->where('period_key', $period->periodKey)
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array{level:int,last_active_date:?string} */
    protected function factsFor(User $user): array
    {
        return $this->factsMemo[$user->id] ??= [
            'level' => (int) (PlayerProgression::query()->where('user_id', $user->id)->value('current_level') ?? 1),
            'last_active_date' => ($d = PlayerStreak::query()->where('user_id', $user->id)->value('last_active_date')) !== null
                ? \Illuminate\Support\Carbon::parse($d)->toDateString()
                : null,
        ];
    }
}
