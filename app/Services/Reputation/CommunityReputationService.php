<?php

namespace App\Services\Reputation;

use App\Models\CommunityReputationSnapshot;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;
use App\Services\Progression\AchievementService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E37 - حساب لقطة السمعة المجتمعية (مشتقّة من سجلات السلطة) وحفظها واستخراج الاعتراف منها.
 *
 * ما تفعله: مقاييس المصدر (ReputationMetricsCollector) ← أبعاد 0..1 ← درجات صحيحة 0..scale ← UPSERT ذرّي لصف واحد لكل مستخدم ← تقييم إنجازات الاعتراف (بلا مكافآت).
 * ما لا تفعله أبدًا: لا تكتب إلا community_reputation_snapshots (+ تقدّم الإنجازات عبر AchievementService القائم)؛ لا تمسّ حالة المبدع ولا محفظة ولا جواهر ولا XP ولا محتوى ولا إشراف.
 * لا خطّافات في خدمات E28–E36: التحديث مجدول (refreshStale) أو يدوي إداري؛ فلا يمكن لفشل هذه الخدمة أن يكسر تقييمًا أو مفضّلة أو متابعة أو تعليقًا أو حفظًا.
 */
class CommunityReputationService
{
    /** مفتاح كاش بوابة الاكتشاف الدوري (لمنع مسح المستخدمين الجدد عند كل تشغيل). */
    public const DISCOVER_GATE_KEY = 'reputation:discover-gate';

    public function __construct(
        protected ReputationSettings $settings,
        protected ReputationMetricsCollector $collector,
        protected ReputationRecognitionContext $recognition,
    ) {}

    // ------------------------------------------------------------------ الحساب النقيّ (بلا قاعدة بيانات)

    /**
     * أبعاد ودرجات وأهلية من مقاييس مصدر **جاهزة** (انظر ReputationMetricsCollector). نقيّة: نفس المقاييس + نفس الإعداد + نفس الوقت = نفس الناتج.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, int|bool>
     */
    public function scores(array $m, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $scale = $this->settings->scale();

        $creatorActive = (bool) $m['creator_active'];
        $frozen = (bool) $m['frozen'];

        // ---------- جودة المبدع
        $rating = $creatorActive ? ReputationMath::ratingComponent(
            (int) $m['ratings_count'], (int) $m['ratings_sum'],
            $this->settings->float('creator.rating.prior_mean'), $this->settings->float('creator.rating.prior_weight'), $this->settings->float('creator.rating.floor'),
        ) : 0.0;

        $engagement = $creatorActive ? ReputationMath::blend([
            'favorites' => ReputationMath::logNorm((int) $m['favorites'], $this->settings->float('creator.engagement.caps.favorites', 1)),
            'players' => ReputationMath::logNorm((int) $m['players'], $this->settings->float('creator.engagement.caps.players', 1)),
            'ratings' => ReputationMath::logNorm((int) $m['ratings_count'], $this->settings->float('creator.engagement.caps.ratings', 1)),
        ], $this->settings->group('creator.engagement.weights')) : 0.0;

        $consistency = $creatorActive ? ReputationMath::blend([
            'rated_works' => ReputationMath::linearNorm((int) $m['rated_works'], $this->settings->float('creator.consistency.caps.rated_works', 1)),
            'span' => ReputationMath::linearNorm(ReputationMath::daysBetween($m['first_published_ts'] ?? null, $m['last_published_ts'] ?? null), $this->settings->float('creator.consistency.caps.span_days', 1)),
            'recency' => $this->recency($m['last_published_ts'] ?? null, $now),
            'series' => ReputationMath::linearNorm((int) $m['series'], $this->settings->float('creator.consistency.caps.series', 1)),
        ], $this->settings->group('creator.consistency.weights')) : 0.0;

        $reach = $creatorActive ? ReputationMath::logNorm((int) $m['followers'], $this->settings->float('creator.reach.cap_followers', 1)) : 0.0;

        $creator = ReputationMath::blend([
            'rating' => $rating, 'engagement' => $engagement, 'consistency' => $consistency, 'reach' => $reach,
        ], $this->settings->group('creator.weights'));

        $creatorEligible = $creatorActive
            && (int) $m['works'] >= $this->settings->int('creator.eligibility.min_works', 2)
            && (int) $m['ratings_count'] >= $this->settings->int('creator.eligibility.min_ratings', 8)
            && (int) $m['raters'] >= $this->settings->int('creator.eligibility.min_raters', 5);

        // ---------- الصاعدون (نافذة حديثة)
        $recentCount = (int) $m['recent_ratings_count'];
        $recentShrunk = ReputationMath::shrunk($recentCount, (int) $m['recent_ratings_sum'], $this->settings->float('creator.rating.prior_mean'), $this->settings->float('rising.prior_weight'));

        $risingRating = ($creatorActive && $recentCount > 0)
            ? ReputationMath::clamp(($recentShrunk - $this->settings->float('creator.rating.floor')) / (5.0 - $this->settings->float('creator.rating.floor')))
            : 0.0;

        $rising = $creatorActive ? ReputationMath::blend([
            'rating' => $risingRating,
            'favorites' => ReputationMath::logNorm((int) $m['recent_favorites'], $this->settings->float('rising.caps.favorites', 1)),
            'players' => ReputationMath::logNorm((int) $m['recent_players'], $this->settings->float('rising.caps.players', 1)),
            'followers' => ReputationMath::logNorm((int) $m['recent_followers'], $this->settings->float('rising.caps.followers', 1)),
        ], $this->settings->group('rising.weights')) : 0.0;

        $risingEligible = $creatorActive
            && (int) $m['works'] >= $this->settings->int('rising.eligibility.min_works', 1)
            && $recentCount >= $this->settings->int('rising.eligibility.min_recent_ratings', 3)
            && $recentShrunk >= $this->settings->float('rising.eligibility.min_quality', 3.8);

        // ---------- المنسّق
        $collections = (int) $m['collections'];
        $curator = ($collections > 0 && ! $frozen) ? ReputationMath::blend([
            'savers' => ReputationMath::logNorm((int) $m['savers'], $this->settings->float('curator.caps.savers', 1)),
            'breadth' => ReputationMath::linearNorm($collections, $this->settings->float('curator.caps.collections', 1)),
            'depth' => ReputationMath::linearNorm((int) $m['items'], $this->settings->float('curator.caps.items', 1)),
            'freshness' => ReputationMath::clamp($collections > 0 ? ((int) $m['fresh']) / $collections : 0.0),
        ], $this->settings->group('curator.weights')) : 0.0;

        $curatorEligible = ! $frozen
            && $collections >= $this->settings->int('curator.eligibility.min_collections', 2)
            && (int) $m['savers'] >= $this->settings->int('curator.eligibility.min_savers', 5);

        // ---------- المساهم المفيد (إعجابات فريدة على تعليقات حيّة ظاهرة؛ لا عدّ تعليقات)
        $helpful = ! $frozen ? ReputationMath::blend([
            'likers' => ReputationMath::logNorm((int) $m['likers'], $this->settings->float('helpfulness.caps.likers', 1)),
            'liked_comments' => ReputationMath::linearNorm((int) $m['liked_comments'], $this->settings->float('helpfulness.caps.liked_comments', 1)),
        ], $this->settings->group('helpfulness.weights')) : 0.0;

        $helpfulEligible = ! $frozen && (bool) $m['verified'] && (bool) $m['profile_public']
            && (int) $m['comments'] >= $this->settings->int('helpfulness.eligibility.min_comments', 5)
            && (int) $m['likers'] >= $this->settings->int('helpfulness.eligibility.min_likers', 10);

        return [
            'creator_score' => ReputationMath::toScale($creator, $scale),
            'creator_rating_score' => ReputationMath::toScale($rating, $scale),
            'creator_engagement_score' => ReputationMath::toScale($engagement, $scale),
            'creator_consistency_score' => ReputationMath::toScale($consistency, $scale),
            'creator_reach_score' => ReputationMath::toScale($reach, $scale),
            'rising_score' => ReputationMath::toScale($rising, $scale),
            'curator_score' => ReputationMath::toScale($curator, $scale),
            'helpfulness_score' => ReputationMath::toScale($helpful, $scale),
            'creator_eligible' => $creatorEligible,
            'rising_eligible' => $risingEligible,
            'curator_eligible' => $curatorEligible,
            'helpful_eligible' => $helpfulEligible,
        ];
    }

    /** حداثة لطيفة 0..1: الاستراحة لا تمحو التاريخ (الحدّ الأدنى floor)، والنشر الحديث = 1، وتتناقص خطيًا حتى decay_days. بلا نشر = 0. */
    protected function recency(?int $lastPublishedTs, CarbonInterface $now): float
    {
        if ($lastPublishedTs === null) {
            return 0.0;
        }

        $floor = ReputationMath::clamp($this->settings->float('creator.consistency.recency.floor', 0.5));
        $decay = max(1.0, $this->settings->float('creator.consistency.recency.decay_days', 365));
        $days = ReputationMath::daysBetween($lastPublishedTs, $now->getTimestamp());

        return $floor + (1.0 - $floor) * max(0.0, 1.0 - $days / $decay);
    }

    /**
     * صف لقطة كامل (أعمدة الجدول) من المقاييس + الدرجات. المفاتيح = أعمدة community_reputation_snapshots ما عدا id/created_at/updated_at.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, int|bool>  $scores
     * @return array<string, mixed>
     */
    public function row(array $m, array $scores, CarbonInterface $now): array
    {
        $when = fn (?int $ts) => $ts !== null ? date('Y-m-d H:i:s', $ts) : null;

        return $scores + [
            'user_id' => (int) $m['user_id'],
            'works_count' => (int) $m['works'], 'rated_works_count' => (int) $m['rated_works'], 'series_count' => (int) $m['series'],
            'ratings_count' => (int) $m['ratings_count'], 'ratings_sum' => (int) $m['ratings_sum'], 'raters_count' => (int) $m['raters'],
            'favorites_count' => (int) $m['favorites'], 'players_count' => (int) $m['players'], 'followers_count' => (int) $m['followers'],
            'first_published_at' => $when($m['first_published_ts'] ?? null), 'last_published_at' => $when($m['last_published_ts'] ?? null),
            'recent_ratings_count' => (int) $m['recent_ratings_count'], 'recent_ratings_sum' => (int) $m['recent_ratings_sum'],
            'recent_favorites_count' => (int) $m['recent_favorites'], 'recent_players_count' => (int) $m['recent_players'], 'recent_followers_count' => (int) $m['recent_followers'],
            'curator_collections_count' => (int) $m['collections'], 'curator_saves_count' => (int) $m['saves'], 'curator_savers_count' => (int) $m['savers'],
            'curator_items_count' => (int) $m['items'], 'curator_fresh_count' => (int) $m['fresh'],
            'helpful_comments_count' => (int) $m['comments'], 'helpful_liked_comments' => (int) $m['liked_comments'],
            'helpful_likers_count' => (int) $m['likers'], 'helpful_likes_count' => (int) $m['likes'],
            'formula_version' => $this->settings->formulaVersion(),
            'calculated_at' => $now->toDateTimeString(),
        ];
    }

    // ------------------------------------------------------------------ إعادة الحساب والكتابة

    /**
     * يحسب (ويكتب إن لم يكن dry-run) لقطات مجموعة مستخدمين. كل **دفعة** مقاييس باستعلامات مجمَّعة + UPSERT واحد، لا استعلامات لكل مستخدم.
     * عند الإعداد غير الصالح يُرمى ReputationConfigException قبل أي كتابة.
     *
     * @param  list<int>  $userIds
     * @return array{processed:int, created:int, changed:int, unchanged:int, rows: array<int, array<string, mixed>>}
     */
    public function refreshUsers(array $userIds, bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        $this->settings->assertValid();

        $now ??= now();
        $result = ['processed' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0, 'rows' => []];
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));

        foreach (array_chunk($ids, max(1, $this->settings->int('refresh.chunk', 100))) as $chunk) {
            $metrics = $this->collector->collect($chunk, $now);

            if ($metrics === []) {
                continue;
            }

            $existing = CommunityReputationSnapshot::query()->whereIn('user_id', array_keys($metrics))->get()->keyBy('user_id');
            $rows = [];
            $recognise = [];

            foreach ($metrics as $userId => $m) {
                $row = $this->row($m, $this->scores($m, $now), $now);
                $previous = $existing->get($userId);

                $isNew = $previous === null;
                $changed = $isNew || $this->differs($previous, $row);

                $result['processed']++;
                $result[$isNew ? 'created' : ($changed ? 'changed' : 'unchanged')]++;
                $result['rows'][$userId] = $row;

                $rows[] = $row;

                if ($changed) {
                    $recognise[] = $userId;
                }
            }

            if ($dryRun || $rows === []) {
                continue;
            }

            $this->persist($rows);

            // اللقطة التي لم تتغيّر لا تُعيد التقييم إلا إن كان هناك اعتراف **مستحَق ولم يُفتح** (حدث فات سابقًا): التعافي الذاتي بلا مسح شامل.
            $recognise = array_values(array_unique(array_merge($recognise, $this->pendingRecognition(array_keys($metrics), $result['rows'], $metrics))));
            $this->recognise($recognise, $metrics, $result['rows']);
        }

        return $result;
    }

    /** مستخدم واحد (إداريًا أو بعد تغيير): يعيد الحساب والكتابة ويرجع اللقطة، أو null إن لم يوجد المستخدم أو كانت الميزة معطّلة. */
    public function refreshUser(int $userId): ?CommunityReputationSnapshot
    {
        if (! $this->settings->enabled()) {
            return null;
        }

        $this->refreshUsers([$userId]);

        return CommunityReputationSnapshot::query()->where('user_id', $userId)->first();
    }

    /**
     * UPSERT ذرّي على user_id الفريد: حساب متزامن لنفس المستخدم لا يكرّر صفًا ولا يفشل (الأخير يفوز بقيم صحيحة من النفس المصدر).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function persist(array $rows): void
    {
        $columns = array_values(array_diff(array_keys($rows[0]), ['user_id']));

        CommunityReputationSnapshot::query()->upsert($rows, ['user_id'], $columns);
    }

    /** هل تغيّر أي عمود مشتق (درجات/أهلية/مصادر/إصدار الصيغة)؟ calculated_at لا يُحتسب تغييرًا. @param array<string, mixed> $row */
    protected function differs(CommunityReputationSnapshot $previous, array $row): bool
    {
        foreach ($row as $column => $value) {
            if (in_array($column, ['calculated_at', 'user_id'], true)) {
                continue;
            }

            $old = $previous->getAttribute($column);

            if ($old instanceof CarbonInterface) {
                $old = $old->toDateTimeString();
            }

            if (is_bool($value) || is_bool($old)) {
                if ((bool) $old !== (bool) $value) {
                    return true;
                }

                continue;
            }

            if ((string) $old !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ الاعتراف (إنجازات بلا مكافآت)

    /**
     * يقيِّم إنجازات الاعتراف للمستخدمين الذين تغيّرت لقطتهم. عبر AchievementService القائم (لا محرك ثانٍ). أي فشل يُسجَّل ولا يمسّ اللقطة المكتوبة أصلًا.
     *
     * @param  list<int>  $userIds
     * @param  array<int, array<string, mixed>>  $metrics
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function recognise(array $userIds, array $metrics, array $rows): void
    {
        $userIds = array_values(array_filter($userIds, fn (int $id) => $this->hasRecognisableSignal($rows[$id] ?? [])));

        if ($userIds === []) {
            return;
        }

        try {
            $users = User::query()->whereIn('id', $userIds)->get();
            $service = app(AchievementService::class);

            foreach ($users as $user) {
                $this->recognition->put((int) $user->id, $this->recognitionValues($rows[$user->id], (bool) ($metrics[$user->id]['creator_active'] ?? false)));

                try {
                    $service->evaluateForEvent('community_reputation_refreshed', $user);
                } catch (\Throwable $e) {
                    Log::warning('فشل تقييم إنجازات الاعتراف المجتمعي', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                } finally {
                    $this->recognition->forget((int) $user->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('فشل تحميل مستخدمي الاعتراف المجتمعي', ['error' => $e->getMessage()]);
        }
    }

    /**
     * من بلغ حدّ إنجاز اعتراف فعّال ولم يُفتح له بعد (فاتَه الحدث: عطل الإنجازات/إعادة تشغيل/حذف تقدّم). استعلامان مجمَّعان لكل دفعة، لا N+1.
     * يقرأ شرط وهدف كل إنجاز مجتمعي فعّال من جدول الإنجازات نفسه (لا من الإعداد) فتعديل الإدارة للهدف يُحترم.
     *
     * @param  list<int>  $userIds
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $metrics
     * @return list<int>
     */
    protected function pendingRecognition(array $userIds, array $rows, array $metrics): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            $definitions = DB::table('achievements')->where('is_active', true)->whereIn('condition_type', AchievementEvaluatorRegistry::COMMUNITY_TYPES)
                ->get(['id', 'condition_type', 'target_value']);

            if ($definitions->isEmpty()) {
                return [];
            }

            $unlocked = [];

            foreach (DB::table('user_achievement_progress')->whereIn('user_id', $userIds)->whereIn('achievement_id', $definitions->pluck('id')->all())
                ->whereNotNull('unlocked_at')->get(['user_id', 'achievement_id']) as $row) {
                $unlocked[(int) $row->user_id][(int) $row->achievement_id] = true;
            }

            $pending = [];

            foreach ($userIds as $id) {
                if (! isset($rows[$id])) {
                    continue;
                }

                $values = $this->recognitionValues($rows[$id], (bool) ($metrics[$id]['creator_active'] ?? false));

                foreach ($definitions as $definition) {
                    if ((int) $definition->target_value > 0 && ($values[$definition->condition_type] ?? 0) >= (int) $definition->target_value && ! isset($unlocked[$id][(int) $definition->id])) {
                        $pending[] = (int) $id;

                        break;
                    }
                }
            }

            return $pending;
        } catch (\Throwable $e) {
            Log::warning('فشل فحص الاعتراف المعلَّق', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @param array<string, mixed> $row */
    protected function hasRecognisableSignal(array $row): bool
    {
        return $row !== [] && (
            (int) $row['works_count'] > 0 || (int) $row['series_count'] > 0 || (int) $row['ratings_count'] > 0 || (int) $row['favorites_count'] > 0
            || (int) $row['followers_count'] > 0 || (int) $row['curator_collections_count'] > 0 || (int) $row['curator_savers_count'] > 0 || (int) $row['helpful_likers_count'] > 0
        );
    }

    /**
     * القيم الحالية لأنواع شروط الاعتراف (مفتاح = نوع الشرط). creator_quality_score لا يُعطى إلا لمبدع **مؤهَّل للترتيب** (جودة بلا دليل كافٍ لا تعتبر مستوى).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int>
     */
    public function recognitionValues(array $row, bool $creatorActive): array
    {
        return [
            'creator_published_puzzles_total' => $creatorActive ? (int) $row['works_count'] : 0,
            'creator_published_series_total' => $creatorActive ? (int) $row['series_count'] : 0,
            'creator_ratings_received_total' => $creatorActive ? (int) $row['ratings_count'] : 0,
            'creator_favorites_received_total' => $creatorActive ? (int) $row['favorites_count'] : 0,
            'creator_followers_total' => $creatorActive ? (int) $row['followers_count'] : 0,
            'creator_quality_score' => ($creatorActive && ! empty($row['creator_eligible'])) ? (int) $row['creator_score'] : 0,
            'public_collections_total' => (int) $row['curator_collections_count'],
            'public_collection_savers_total' => (int) $row['curator_savers_count'],
            'discussion_likers_total' => (int) $row['helpful_likers_count'],
        ];
    }

    // ------------------------------------------------------------------ التحديث المجدول

    /**
     * يحدّث دفعة محدودة: (1) الأقدم حسابًا أو بإصدار صيغة أقدم، (2) مستخدمون جدد لا لقطة لهم (مبدعون/ناشرو مجموعات/كتّاب تعليقات معجَب بها) بميزانية الدفعة نفسها.
     * الدفعة محدودة بـ refresh.batch؛ لا مسح كامل. بلا خطّافات: غيابه لا يكسر أي ميزة، فقط يتأخر تحديث اللقطة.
     *
     * @return array{stale:int, discovered:int, processed:int, created:int, changed:int}
     */
    public function refreshStale(?int $limit = null): array
    {
        $empty = ['stale' => 0, 'discovered' => 0, 'processed' => 0, 'created' => 0, 'changed' => 0];

        if (! $this->settings->enabled()) {
            return $empty;
        }

        $this->settings->assertValid();

        $limit = max(1, $limit ?? $this->settings->int('refresh.batch', 200));
        $cutoff = now()->subMinutes(max(1, $this->settings->int('refresh.max_age_minutes', 180)))->toDateTimeString();

        $stale = CommunityReputationSnapshot::query()
            ->where(fn ($q) => $q->where('calculated_at', '<', $cutoff)->orWhereNull('calculated_at')->orWhere('formula_version', '<', $this->settings->formulaVersion()))
            ->orderBy('calculated_at')->orderBy('id')->limit($limit)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $discovered = [];
        $budget = $limit - count($stale);

        if ($budget > 0 && $this->discoverGateOpen()) {
            $discovered = $this->discoverMissing($budget);
        }

        $result = $this->refreshUsers(array_merge($stale, $discovered));

        return ['stale' => count($stale), 'discovered' => count($discovered)] + array_intersect_key($result, array_flip(['processed', 'created', 'changed']));
    }

    /** بوابة اكتشاف المستخدمين الجدد: مرة لكل discover_interval_minutes (Cache::add ذرّي)، 0 = دائمًا. */
    protected function discoverGateOpen(): bool
    {
        $minutes = $this->settings->int('refresh.discover_interval_minutes', 60);

        return $minutes <= 0 || Cache::add(self::DISCOVER_GATE_KEY, 1, now()->addMinutes($minutes));
    }

    /**
     * مستخدمون بلا لقطة لكن لديهم مصدر سمعة محتمل. الاستعلامات مفهرسة وموقوفة بحدّ؛ الترتيب بـ user_id حتمي (كل لقطة جديدة تخرج من المجموعة فيتقدّم الاكتشاف).
     *
     * @return list<int>
     */
    public function discoverMissing(int $limit): array
    {
        $missing = fn ($q, string $column) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('community_reputation_snapshots as s')->whereColumn('s.user_id', $column));

        $found = [];

        $found += array_flip($missing(DB::table('creator_profiles as p'), 'p.user_id')->orderBy('p.user_id')->limit($limit)->pluck('p.user_id')->all());

        if (count($found) < $limit) {
            $found += array_flip($missing(DB::table('user_collections as c')->where('c.visibility', '!=', 'private'), 'c.user_id')
                ->distinct()->orderBy('c.user_id')->limit($limit)->pluck('c.user_id')->all());
        }

        if (count($found) < $limit) {
            $found += array_flip($missing(DB::table('discussion_comments as dc')->where('dc.likes_count', '>', 0)->where('dc.status', 'visible')->whereNull('dc.deleted_at')->whereNotNull('dc.user_id'), 'dc.user_id')
                ->distinct()->orderBy('dc.user_id')->limit($limit)->pluck('dc.user_id')->all());
        }

        return array_slice(array_map('intval', array_keys($found)), 0, $limit);
    }

    // ------------------------------------------------------------------ المصالحة

    /**
     * مصالحة: يقارن لقطات محدَّدة (أو أقدم $limit لقطة) بما تحسبه السجلات الأصلية الآن ويبلغ الانحراف، ويصلحه عند fix. لا يمسّ سوى اللقطات.
     * يكشف أيضًا: صيغة قديمة، درجة خارج المدى، مستخدمين بلا لقطة (اكتشاف).
     *
     * @return array{checked:int, drifted:int, drifted_user_ids:list<int>, outdated_formula:int, out_of_range:int, missing_subjects:int, fixed:bool}
     */
    public function reconcile(int $limit = 500, bool $fix = false, ?int $userId = null): array
    {
        $this->settings->assertValid();

        $scale = $this->settings->scale();
        $query = CommunityReputationSnapshot::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->orderBy('calculated_at')->orderBy('id')->limit(max(1, $limit));
        }

        $ids = $query->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $outdated = CommunityReputationSnapshot::query()->where('formula_version', '<', $this->settings->formulaVersion())->count();
        $outOfRange = CommunityReputationSnapshot::query()->where(fn ($q) => $q
            ->where('creator_score', '>', $scale)->orWhere('rising_score', '>', $scale)->orWhere('curator_score', '>', $scale)->orWhere('helpfulness_score', '>', $scale)
            ->orWhere('creator_rating_score', '>', $scale)->orWhere('creator_engagement_score', '>', $scale)->orWhere('creator_consistency_score', '>', $scale)->orWhere('creator_reach_score', '>', $scale))->count();

        $dry = $this->refreshUsers($ids, dryRun: true);

        $drifted = [];

        if ($dry['changed'] > 0) {
            $existing = CommunityReputationSnapshot::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

            foreach ($dry['rows'] as $uid => $row) {
                $previous = $existing->get($uid);

                if ($previous !== null && $this->differs($previous, $row)) {
                    $drifted[] = (int) $uid;
                }
            }
        }

        if ($fix && $drifted !== []) {
            $this->refreshUsers($drifted);
        }

        return [
            'checked' => count($ids),
            'drifted' => count($drifted),
            'drifted_user_ids' => $drifted,
            'outdated_formula' => $outdated,
            'out_of_range' => $outOfRange,
            'missing_subjects' => $userId === null ? count($this->discoverMissing(1000)) : 0,
            'fixed' => $fix,
        ];
    }
}
