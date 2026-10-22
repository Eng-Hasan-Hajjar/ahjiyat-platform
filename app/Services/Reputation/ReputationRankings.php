<?php

namespace App\Services\Reputation;

use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Collections\CollectionAccess;
use App\Services\Social\BlockService;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E37 - قراءة ترتيبات الجودة العلنية (أفضل المبدعين، الصاعدون، أفضل المنسّقين، المساهمون المفيدون، المبدعون المختارون).
 *
 * القاعدة الأمنية: **الكاش معرّفات مرتَّبة فقط** (الحوض pool) — لا أسماء ولا درجات ولا أهلية. بعد قراءة الحوض تُعاد قراءة الصفوف من القاعدة وتُفحص حيًّا:
 *   مبدع: معتمَد ∧ حساب غير مجمَّد ∧ ≥ min_works عملًا ظاهرًا الآن ∧ علم الأهلية؛ منسّق: حساب موثَّق غير مجمَّد ∧ ملف عام ∧ ≥ min_collections مجموعة مُدرَجة الآن؛
 *   مساهم: موثَّق غير مجمَّد ∧ ملف عام. فتعليق مبدع أو تجميد حساب أو إخفاء أعماله **يزيله فورًا** (لا يُبعث من الكاش)، ويعود بالاستعادة.
 * ثم يُستبعد كل من بينه وبين المشاهد حظر. الترتيب الحتمي: الدرجة تنازليًا ثم الدليل الداعم تنازليًا ثم user_id تصاعديًا (لا ترتيب عشوائي ولا تعادل مفتوح).
 * لا تُعرض الدرجة الخام العلنية: المتوسط والعدد وأرقام مجمَّعة فقط. لا إشارة مالية/دعم/رعاية/جواهر في أي مكان. بلا ترتيب «الأكثر تعليقًا».
 */
class ReputationRankings
{
    public const CREATORS = 'creators';

    public const RISING = 'rising';

    public const CURATORS = 'curators';

    public const HELPFUL = 'helpful';

    public function __construct(
        protected ReputationSettings $settings,
        protected BlockService $blocks,
        protected CollectionAccess $collections,
        protected CommunityRecognitionPresenter $recognition,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->enabled();
    }

    // ------------------------------------------------------------------ القوائم

    /** @return list<array<string, mixed>> */
    public function creators(?User $viewer = null, ?int $limit = null): array
    {
        $rows = $this->liveCreators(self::CREATORS, 'creator_eligible', 'creator_score', 'ratings_count', $viewer, $limit);
        $tiers = $this->recognition->forUsers(array_map(fn ($r) => (int) $r->user_id, $rows));

        return array_values(array_map(fn ($r, $i) => [
            'rank' => $i + 1,
            'name' => (string) $r->user_name,
            'slug' => (string) $r->creator_slug,
            'tier' => $tiers[(int) $r->user_id]['tier'] ?? null,
            'average' => $r->averageRating(),
            'ratings' => (int) $r->ratings_count,
            'works' => (int) $r->works_count,
            'favorites' => (int) $r->favorites_count,
            'followers' => (int) $r->followers_count,
        ], $rows, array_keys($rows)));
    }

    /** @return list<array<string, mixed>> */
    public function rising(?User $viewer = null, ?int $limit = null): array
    {
        $rows = $this->liveCreators(self::RISING, 'rising_eligible', 'rising_score', 'recent_ratings_count', $viewer, $limit);

        return array_values(array_map(fn ($r, $i) => [
            'rank' => $i + 1,
            'name' => (string) $r->user_name,
            'slug' => (string) $r->creator_slug,
            'recent_ratings' => (int) $r->recent_ratings_count,
            'recent_average' => (int) $r->recent_ratings_count > 0 ? round(((int) $r->recent_ratings_sum) / (int) $r->recent_ratings_count, 1) : null,
            'recent_favorites' => (int) $r->recent_favorites_count,
            'recent_followers' => (int) $r->recent_followers_count,
            'window_days' => $this->settings->windowDays(),
        ], $rows, array_keys($rows)));
    }

    /** @return list<array<string, mixed>> */
    public function curators(?User $viewer = null, ?int $limit = null): array
    {
        $limit ??= $this->settings->int('rankings.list_limit', 20);

        $rows = $this->pooled(self::CURATORS, 'curator_eligible', 'curator_score', 'curator_savers_count', function ($q) {
            return $q->join('users as u', 'u.id', '=', 'community_reputation_snapshots.user_id')
                ->whereNotNull('u.email_verified_at')->where('u.is_frozen', false)->where('u.profile_visibility', User::VISIBILITY_PUBLIC)
                ->select('community_reputation_snapshots.*', 'u.name as user_name', 'u.public_id as user_public_id');
        }, $viewer);

        if ($rows === []) {
            return [];
        }

        // إعادة فحص حيّة: ≥ min_collections مجموعة مُدرَجة علنًا الآن لكل منسّق (استعلام واحد مجمَّع).
        $live = $this->collections->listableQuery(null, $this->settings->int('curator.min_visible_items', 3))
            ->whereIn('user_collections.user_id', array_map(fn ($r) => (int) $r->user_id, $rows))
            ->groupBy('user_collections.user_id')->selectRaw('user_collections.user_id as uid, COUNT(*) as c')
            ->toBase()->pluck('c', 'uid');

        $min = $this->settings->int('curator.eligibility.min_collections', 2);
        $rows = array_values(array_filter($rows, fn ($r) => (int) ($live[(int) $r->user_id] ?? 0) >= $min));
        $rows = array_slice($rows, 0, $limit);
        $tiers = $this->recognition->forUsers(array_map(fn ($r) => (int) $r->user_id, $rows));

        return array_values(array_map(fn ($r, $i) => [
            'rank' => $i + 1,
            'name' => (string) $r->user_name,
            'public_id' => (string) $r->user_public_id,
            'collections' => (int) ($live[(int) $r->user_id] ?? 0),
            'savers' => (int) $r->curator_savers_count,
            'recognition' => $tiers[(int) $r->user_id]['groups']['curator'][0] ?? null,
        ], $rows, array_keys($rows)));
    }

    /** @return list<array<string, mixed>> */
    public function helpful(?User $viewer = null, ?int $limit = null): array
    {
        $limit ??= $this->settings->int('rankings.helpful_limit', 10);

        $rows = $this->pooled(self::HELPFUL, 'helpful_eligible', 'helpfulness_score', 'helpful_likers_count', function ($q) {
            return $q->join('users as u', 'u.id', '=', 'community_reputation_snapshots.user_id')
                ->whereNotNull('u.email_verified_at')->where('u.is_frozen', false)->where('u.profile_visibility', User::VISIBILITY_PUBLIC)
                ->select('community_reputation_snapshots.*', 'u.name as user_name', 'u.public_id as user_public_id');
        }, $viewer);

        return array_values(array_map(fn ($r, $i) => [
            'rank' => $i + 1,
            'name' => (string) $r->user_name,
            'public_id' => (string) $r->user_public_id,
            'likers' => (int) $r->helpful_likers_count,
        ], array_slice($rows, 0, $limit), array_keys(array_slice($rows, 0, $limit))));
    }

    /**
     * المبدعون المختارون الآن (تحريري، يُعرض بترتيب حداثة الاختيار): معتمَد ∧ غير مجمَّد ∧ عمل ظاهر واحد على الأقل (يُفحص حيًّا لا يُخزَّن). بلا كاش.
     *
     * @return list<array<string, mixed>>
     */
    public function featured(?User $viewer = null, ?int $limit = null): array
    {
        $limit ??= $this->settings->int('rankings.featured_limit', 6);
        $hidden = $viewer !== null ? $this->blocks->hiddenIds($viewer) : [];

        $rows = CommunityFeaturedCreator::query()->activeNow()
            ->join('creator_profiles as p', 'p.id', '=', 'community_featured_creators.creator_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.status', CreatorProfile::STATUS_APPROVED)->where('u.is_frozen', false)
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('creator_puzzles as w')->whereColumn('w.creator_profile_id', 'p.id')->where('w.is_visible', true))
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('p.user_id', $hidden))
            ->orderByDesc('community_featured_creators.featured_from')->orderByDesc('community_featured_creators.id')
            ->limit(max(1, $limit))
            ->get(['p.user_id as user_id', 'p.slug as slug', 'u.name as name']);

        return $rows->map(fn ($r) => ['name' => (string) $r->name, 'slug' => (string) $r->slug, 'user_id' => (int) $r->user_id])->all();
    }

    /** هل هذا الملف مبدع مختار الآن (لشارة «مبدع مختار» في صفحته)؟ استعلام exists واحد. */
    public function isFeatured(int $creatorProfileId): bool
    {
        return CommunityFeaturedCreator::query()->activeNow()->where('creator_profile_id', $creatorProfileId)->exists();
    }

    // ------------------------------------------------------------------ داخلي

    /**
     * صفوف مبدعين بعد الفحص الحيّ، بترتيب حتمي، مقصوصة للحدّ.
     *
     * @return list<CommunityReputationSnapshot>
     */
    protected function liveCreators(string $track, string $eligibleColumn, string $scoreColumn, string $supportColumn, ?User $viewer, ?int $limit): array
    {
        $limit ??= $this->settings->int('rankings.list_limit', 20);
        $minWorks = $this->settings->int($track === self::RISING ? 'rising.eligibility.min_works' : 'creator.eligibility.min_works', 1);

        $rows = $this->pooled($track, $eligibleColumn, $scoreColumn, $supportColumn, function ($q) use ($minWorks) {
            return $q->join('creator_profiles as p', 'p.user_id', '=', 'community_reputation_snapshots.user_id')
                ->join('users as u', 'u.id', '=', 'community_reputation_snapshots.user_id')
                ->where('p.status', CreatorProfile::STATUS_APPROVED)->where('u.is_frozen', false)
                ->whereRaw('(select count(*) from creator_puzzles w where w.creator_profile_id = p.id and w.is_visible = ?) >= ?', [true, max(1, $minWorks)])
                ->select('community_reputation_snapshots.*', 'p.slug as creator_slug', 'u.name as user_name');
        }, $viewer);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * حوض المعرّفات (مخبَّأ) ← صفوف حيّة بفحص إضافي ← استبعاد الحظر ← ترتيب حتمي.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): \Illuminate\Database\Eloquent\Builder  $live
     * @return list<CommunityReputationSnapshot>
     */
    protected function pooled(string $track, string $eligibleColumn, string $scoreColumn, string $supportColumn, \Closure $live, ?User $viewer): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $ids = $this->pool($track, $eligibleColumn, $scoreColumn, $supportColumn);

        if ($ids === []) {
            return [];
        }

        $hidden = $viewer !== null ? $this->blocks->hiddenIds($viewer) : [];

        if ($hidden !== []) {
            $ids = array_values(array_diff($ids, array_map('intval', $hidden)));
        }

        if ($ids === []) {
            return [];
        }

        $query = CommunityReputationSnapshot::query()
            ->whereIn('community_reputation_snapshots.user_id', $ids)
            ->where('community_reputation_snapshots.'.$eligibleColumn, true);

        $rows = $live($query)->get()->all();

        // ترتيب حتمي: الدرجة ↓ ثم الدليل الداعم ↓ ثم user_id ↑ (لا تعادل مفتوح).
        usort($rows, function ($a, $b) use ($scoreColumn, $supportColumn) {
            return ((int) $b->{$scoreColumn} <=> (int) $a->{$scoreColumn})
                ?: ((int) $b->{$supportColumn} <=> (int) $a->{$supportColumn})
                ?: ((int) $a->user_id <=> (int) $b->user_id);
        });

        return $rows;
    }

    /**
     * معرّفات مرتَّبة فقط (الكاش لا يحمل بيانات للعرض). TTL قصير؛ 0 = بلا كاش.
     *
     * @return list<int>
     */
    protected function pool(string $track, string $eligibleColumn, string $scoreColumn, string $supportColumn): array
    {
        $version = $this->settings->formulaVersion();

        return CachedSnapshot::remember(
            "reputation.pool.{$track}.v{$version}",
            $this->settings->int('rankings.cache_seconds', 600),
            fn () => CommunityReputationSnapshot::query()
                ->where($eligibleColumn, true)->where('formula_version', '>=', $version)->where($scoreColumn, '>', 0)
                ->orderByDesc($scoreColumn)->orderByDesc($supportColumn)->orderBy('user_id')
                ->limit(max(1, $this->settings->int('rankings.pool', 100)))
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /** يمسح أحواض الكاش (بعد إعادة حساب إدارية أو تغيير إبراز). لا يؤثر على صحة العرض (الفحص حيّ)؛ فقط يعجّل رؤية الترتيب الجديد. */
    public function flush(): void
    {
        $version = $this->settings->formulaVersion();

        foreach ([self::CREATORS, self::RISING, self::CURATORS, self::HELPFUL] as $track) {
            try {
                \Illuminate\Support\Facades\Cache::forget("reputation.pool.{$track}.v{$version}");
            } catch (\Throwable) {
                // لا يفشل شيء بسبب الكاش
            }
        }
    }
}
