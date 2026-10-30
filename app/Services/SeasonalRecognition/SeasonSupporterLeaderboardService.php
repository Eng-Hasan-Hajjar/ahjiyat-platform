<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E44 - لوحة «داعمو الموسم» (لاعبون فقط). قراءة فقط من season_supporter_stats.
 *
 * - الترتيب: النقاط **العلنية** تنازليًا ثم أقدم أول نقطة ثم المعرّف (حتمي، مرتبة تسلسلية 1..N). أعلى {leaderboard_limit} (100).
 * - علنية = نقاط مصدر غير مجهول (E30). يُستبعد من الأسطح العامة كل من: حسابه مجمَّد، أو له ملف داعم E30 غير علني/غير نشط/موقوف، أو ملف **مؤسسة** (لوحة المؤسسات تبقى لـE30 كما هي).
 * - تبقى مفتوحة حتى نهاية الموسم، ثم SeasonSupporterFinalizer يثبّت المرتبة/النقاط/المستوى فتُقرأ اللوحة من اللقطة.
 * - الحمولة العامة بلا معرّفات داخلية (الاسم العام + الترتيب + النقاط العلنية + المستوى العلني). خريطة user_id⇒مرتبة داخلية فقط (للشارة الديناميكية) ولا تُعاد للعميل.
 * - اللوحة العلنية مخزَّنة بنمط الإصدار؛ كل منح/عكس/تفعيل/إنهاء يُبطلها بعد commit.
 */
class SeasonSupporterLeaderboardService
{
    public function __construct(protected SeasonRecognitionSettingsService $settings) {}

    /**
     * @return array{finalized: bool, generated_at: string, supporters_count: int, rows: list<array<string, mixed>>}
     */
    public function publicBoard(Season $season): array
    {
        return SeasonalRecognitionCache::remember(
            (int) $season->id,
            SeasonalRecognitionCache::SCOPE_SUPPORTERS,
            'public-board',
            fn () => $this->build($season, false)['public'],
        );
    }

    /**
     * أحدث البطاقات المفعَّلة للعرض العام: الاسم العام للعلني، و«داعم مجهول» للمجهول (لا اسم ولا هوية). حسابات E30 غير العلنية/المجمَّدة تُستبعد كليًا.
     *
     * @return list<array{name: string, tier: string, anonymous: bool, at: string}>
     */
    public function recentCards(Season $season, int $limit = 10): array
    {
        return SeasonalRecognitionCache::remember((int) $season->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS, 'recent-'.$limit, function () use ($season, $limit): array {
            return DB::table('season_supporter_cards as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->leftJoin('supporter_profiles as sp', 'sp.user_id', '=', 'u.id')
                ->where('c.season_id', $season->id)->where('c.status', SeasonSupporterCard::STATUS_ACTIVE)
                ->where('u.is_frozen', false)
                ->where(function ($q) {
                    $q->whereNull('sp.id')->orWhere(function ($q2) {
                        $q2->where('sp.status', 'active')->where('sp.is_public', true)->where('sp.type', 'individual');
                    });
                })
                ->orderByDesc('c.activated_at')->orderByDesc('c.id')->limit(max(1, min(50, $limit)))
                ->get(['u.name', 'c.tier_key', 'c.is_anonymous', 'c.activated_at'])
                ->map(fn ($r) => [
                    'name' => $r->is_anonymous ? 'داعم مجهول' : (string) $r->name,
                    'tier' => (string) $r->tier_key,
                    'anonymous' => (bool) $r->is_anonymous,
                    'at' => (string) $r->activated_at,
                ])->all();
        });
    }

    /**
     * خريطة user_id ⇒ مرتبة (للشارة الديناميكية «داعم الموسم #N»). داخلية: لا تُسلَّم للعميل.
     *
     * @return array<int, int>
     */
    public function rankMap(Season $season): array
    {
        return SeasonalRecognitionCache::remember(
            (int) $season->id,
            SeasonalRecognitionCache::SCOPE_SUPPORTERS,
            'rank-map',
            fn () => $this->build($season, true)['map'],
            max(1, (int) config('seasonal_recognition.cache.identity_ttl_seconds', 60)),
        );
    }

    /**
     * أساس استعلام المؤهَّلين للأسطح العامة (المُنهي يستعمله أيضًا فلا يختلف العرض عن اللقطة).
     */
    public function eligibleQuery(int $seasonId): Builder
    {
        return DB::table('season_supporter_stats as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('supporter_profiles as sp', 'sp.user_id', '=', 'u.id')
            ->where('s.season_id', $seasonId)
            ->where('s.public_points_total', '>', 0)
            ->where('u.is_frozen', false)
            ->where(function ($q) {
                $q->whereNull('sp.id')->orWhere(function ($q2) {
                    $q2->where('sp.status', 'active')->where('sp.is_public', true)->where('sp.type', 'individual');
                });
            })
            ->orderByDesc('s.public_points_total')
            ->orderByRaw('s.first_point_at IS NULL')
            ->orderBy('s.first_point_at')
            ->orderBy('s.id');
    }

    /**
     * مجاميع للإدارة والتحليلات (بلا هويات): عدد الداعمين، مجموع النقاط الحيّ، البطاقات بحسب المستوى والحالة.
     *
     * @return array{supporters: int, points: int, public_points: int, cards: array<string, array<string, int>>}
     */
    public function totals(Season $season): array
    {
        $stats = SeasonSupporterStat::query()->where('season_id', $season->id)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(points_total), 0) as p, COALESCE(SUM(public_points_total), 0) as pp')->first();

        $cards = [];

        SeasonSupporterCard::query()->where('season_id', $season->id)->groupBy('tier_key', 'status')
            ->selectRaw('tier_key, status, COUNT(*) as n')->get()
            ->each(function ($row) use (&$cards): void {
                $cards[(string) $row->tier_key][(string) $row->status] = (int) $row->n;
            });

        return [
            'supporters' => (int) ($stats->n ?? 0),
            'points' => (int) ($stats->p ?? 0),
            'public_points' => (int) ($stats->pp ?? 0),
            'cards' => $cards,
        ];
    }

    /**
     * @return array{public: array{finalized: bool, generated_at: string, supporters_count: int, rows: list<array<string, mixed>>}, map: array<int, int>}
     */
    protected function build(Season $season, bool $needMap): array
    {
        $setting = $this->settings->settingFor($season);
        $finalized = $setting?->supporters_finalized_at !== null;
        $limit = max(1, (int) config('seasonal_recognition.supporters.leaderboard_limit', 100));

        if ($finalized) {
            $rows = DB::table('season_supporter_stats as s')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->leftJoin('supporter_profiles as sp', 'sp.user_id', '=', 'u.id')
                ->where('s.season_id', $season->id)
                ->whereNotNull('s.final_rank')
                ->where('u.is_frozen', false)
                ->where(function ($q) {
                    $q->whereNull('sp.id')->orWhere(function ($q2) {
                        $q2->where('sp.status', 'active')->where('sp.is_public', true)->where('sp.type', 'individual');
                    });
                })
                ->orderBy('s.final_rank')->limit($limit)
                ->get(['s.user_id', 'u.name', 's.final_rank as pos', 's.final_points as points', 's.final_card_tier_key as tier']);
        } else {
            $rows = $this->eligibleQuery((int) $season->id)->limit($limit)->get(['s.user_id', 'u.name', 's.public_points_total as points']);
        }

        $tiers = $finalized ? collect() : $this->publicTiers((int) $season->id, $rows->pluck('user_id')->all());
        $map = [];
        $public = [];

        foreach ($rows as $i => $row) {
            $rank = $finalized ? (int) $row->pos : $i + 1;
            $tier = $finalized ? ($row->tier ?: null) : ($tiers[(int) $row->user_id] ?? null);
            $map[(int) $row->user_id] = $rank;
            $public[] = [
                'rank' => $rank,
                'name' => (string) $row->name,
                'points' => (int) $row->points,
                'tier' => $tier,
            ];
        }

        return [
            'public' => [
                'finalized' => $finalized,
                'generated_at' => now()->toIso8601String(),
                'supporters_count' => count($public),
                'rows' => $public,
            ],
            'map' => $map,
        ];
    }

    /**
     * أعلى مستوى **علني** (بطاقات نشطة غير مجهولة) لكل مستخدم.
     *
     * @param  list<int|string>  $userIds
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function publicTiers(int $seasonId, array $userIds)
    {
        if ($userIds === []) {
            return collect();
        }

        return SeasonSupporterCard::query()
            ->where('season_id', $seasonId)->where('status', SeasonSupporterCard::STATUS_ACTIVE)->where('is_anonymous', false)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'tier_key'])
            ->groupBy('user_id')
            ->map(fn ($cards) => $cards->sortByDesc(fn ($c) => SeasonSupporterPackage::rankOf($c->tier_key))->first()->tier_key);
    }
}
