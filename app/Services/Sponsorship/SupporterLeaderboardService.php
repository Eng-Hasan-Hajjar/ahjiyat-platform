<?php

namespace App\Services\Sponsorship;

use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E30 - لوحات الداعمين العامة (الأعلى كل الوقت/هذا العام/الموسم الحالي/برنامج، الأحدث، المؤسسات). مبدأ التصميم:
 *
 *  - الترتيب بصافي المؤكَّد المحاسبي (SUM مجمَّعة بقاعدة البيانات بفهرس مغطٍّ؛ لا تحميل صفوف ولا فرز PHP) ثم أول تأكيد (الأقدم أولًا) ثم المعرّف: ترتيب قطعي لا يتأرجح.
 *  - المجهولون: تجميع منفصل لكل (داعم، مجهول). صف المجهول يظهر باسم «داعم مجهول» بلا ملف ولا رابط ولا شعار ولا مستوى، فلا يمكن ربطه بالاسم. ما حُسب مجهولًا
 *    لا يدخل صف الاسم الصريح ولا قائمة المؤسسات. المساهمات المجهولة تُحتسب في المجاميع/المستوى/التمويل لكنها لا تُنسب علنًا لأحد.
 *  - المبالغ الدقيقة لا تظهر إلا للوحة برنامج فعّل show_public_amounts؛ اللوحات العامة الأخرى تعرض المستوى فقط.
 *  - الكاش يحمل معرّفات وترتيبًا ومجاميع فقط (TTL قصير + إبطال بأي تغيير مالي/ظهور)؛ العلنية تُعاد فحصها حيًّا بكل عرض (موقوف/خاص يختفي فورًا) بهامش جلب إضافي.
 *  - كل قائمة محدودة بسقف (لا ترقيم مفتوح).
 *
 * @phpstan-type Entry array{rank: int, anonymous: bool, supporter: ?SupporterProfile, tier: ?SupporterTier, total_minor: ?int, since: ?Carbon}
 */
class SupporterLeaderboardService
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_YEAR = 'year';

    public const SCOPE_SEASON = 'season';

    public const SCOPE_PROGRAM = 'program';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_YEAR, self::SCOPE_SEASON, self::SCOPE_PROGRAM];

    public function __construct(protected SupporterTierService $tiers) {}

    /**
     * @return array{entries: list<array>, show_amounts: bool, scope: string}
     */
    public function top(string $scope = self::SCOPE_ALL, ?SponsorshipProgram $program = null, ?int $limit = null): array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_ALL;
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.top_limit', 20)), 100));
        $programId = $scope === self::SCOPE_PROGRAM ? ($program?->id) : null;

        if ($scope === self::SCOPE_PROGRAM && $programId === null) {
            return ['entries' => [], 'show_amounts' => false, 'scope' => $scope];
        }

        $seasonId = $scope === self::SCOPE_SEASON ? $this->currentSeasonId() : null;

        if ($scope === self::SCOPE_SEASON && $seasonId === null) {
            return ['entries' => [], 'show_amounts' => false, 'scope' => $scope];
        }

        $fetch = $limit + (int) config('sponsorship.public.overfetch', 10);
        $rows = SponsorshipCache::remember('top', [$scope, $programId ?? 0, $seasonId ?? 0, now()->year, $fetch], null, fn () => $this->aggregate($scope, $programId, $seasonId, $fetch));
        $showAmounts = $scope === self::SCOPE_PROGRAM && (bool) $program?->show_public_amounts;

        return ['entries' => $this->hydrate($rows, $limit, $showAmounts), 'show_amounts' => $showAmounts, 'scope' => $scope];
    }

    /**
     * أحدث الداعمين (تأكيدات حديثة). كل مساهمة مجهولة سطر «داعم مجهول» مستقل؛ المسمّون مرة واحدة لكل داعم.
     *
     * @return list<array{anonymous: bool, supporter: ?SupporterProfile, at: ?Carbon}>
     */
    public function recent(?int $limit = null): array
    {
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.recent_limit', 12)), 50));
        $fetch = $limit * 3 + (int) config('sponsorship.public.overfetch', 10);

        $rows = SponsorshipCache::remember('recent', [$fetch], null, fn () => DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true)
            ->orderByDesc('c.confirmed_at')->orderByDesc('c.id')->limit($fetch)
            ->get(['c.supporter_profile_id as sid', 'c.is_anonymous as anon', 'c.confirmed_at as at'])
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'anon' => (bool) $r->anon, 'at' => $r->at])->all());

        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            if (! $row['anon']) {
                if (isset($seen[$row['sid']])) {
                    continue;
                }

                $seen[$row['sid']] = true;
            }

            $out[] = ['anonymous' => $row['anon'], 'supporter' => $row['anon'] ? null : $profiles[$row['sid']], 'at' => $row['at'] ? Carbon::parse($row['at']) : null];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * المؤسسات الداعمة (علنيًا باسمها فقط): مؤسسات علنية لها دعم مؤكَّد **غير مجهول**، بترتيب صافي الدعم المسمّى.
     *
     * @return list<array{rank: int, supporter: SupporterProfile, tier: ?SupporterTier}>
     */
    public function organizations(?int $limit = null): array
    {
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.organizations_limit', 12)), 50));
        $fetch = $limit + (int) config('sponsorship.public.overfetch', 10);

        $rows = SponsorshipCache::remember('orgs', [$fetch], null, fn () => DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false)
            ->where('s.type', SupporterProfile::TYPE_ORGANIZATION)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true)
            ->groupBy('c.supporter_profile_id')
            ->selectRaw('c.supporter_profile_id as sid, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as first_at')
            ->orderByDesc('total')->orderBy('first_at')->orderBy('sid')->limit($fetch)
            ->get()->map(fn ($r) => ['sid' => (int) $r->sid])->all());

        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $tiers = $this->tiers->forMany(array_keys($profiles));
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            $out[] = ['rank' => count($out) + 1, 'supporter' => $profiles[$row['sid']], 'tier' => $tiers[$row['sid']] ?? null];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** الموسم «الحالي»: منشور وحملته نشطة ضمن نافذتها، الأحدث فالمميّز أولًا؛ null إن لم يوجد. */
    public function currentSeasonId(): ?int
    {
        // E42: «الحالي» = ظاهر (نشر + موعد إعلان) وضمن نافذته الفعلية (تاريخ الموسم الصريح، وإلا تاريخ حملته) وغير مؤرشف. الموسم بلا نافذة صريحة يشترط حملة نشطة كما كان.
        $now = now();
        $id = Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->publiclyVisible($now)
            ->where(fn ($q) => $q->whereNotNull('seasons.starts_at')->orWhereNotNull('seasons.ends_at')->orWhere('campaigns.is_active', true))
            ->where(fn ($q) => $q->whereNull('seasons.archive_at')->orWhere('seasons.archive_at', '>', $now))
            ->where(fn ($q) => $q->whereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) IS NULL')->orWhereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) <= ?', [$now]))
            ->where(fn ($q) => $q->whereRaw('COALESCE(seasons.ends_at, campaigns.ends_at) IS NULL')->orWhereRaw('COALESCE(seasons.ends_at, campaigns.ends_at) >= ?', [$now]))
            ->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')->value('seasons.id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{sid: int, anon: bool, total: int, first: ?string}> */
    protected function aggregate(string $scope, ?int $programId, ?int $seasonId, int $limit): array
    {
        $query = DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true);

        match ($scope) {
            self::SCOPE_YEAR => $query->where('c.confirmed_at', '>=', now()->startOfYear()),
            self::SCOPE_SEASON => $query->whereIn('c.sponsorship_program_id', fn ($q) => $q->select('id')->from('sponsorship_programs')
                ->where('target_type', SponsorshipProgram::TARGET_SEASON)->where('target_id', $seasonId)),
            self::SCOPE_PROGRAM => $query->where('c.sponsorship_program_id', $programId),
            default => null,
        };

        return $query->groupBy('c.supporter_profile_id', 'c.is_anonymous')
            ->selectRaw('c.supporter_profile_id as sid, c.is_anonymous as anon, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as first_at')
            ->orderByDesc('total')->orderBy('first_at')->orderBy('sid')->orderBy('anon')
            ->limit($limit)->get()
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'anon' => (bool) $r->anon, 'total' => (int) $r->total, 'first' => $r->first_at])->all();
    }

    /**
     * @param  list<array{sid: int, anon: bool, total: int, first: ?string}>  $rows
     * @return list<array>
     */
    protected function hydrate(array $rows, int $limit, bool $showAmounts): array
    {
        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $named = array_values(array_unique(array_map(fn ($r) => $r['sid'], array_filter($rows, fn ($r) => ! $r['anon'] && isset($profiles[$r['sid']])))));
        $tiers = $this->tiers->forMany($named);
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            $out[] = [
                'rank' => count($out) + 1,
                'anonymous' => $row['anon'],
                'supporter' => $row['anon'] ? null : $profiles[$row['sid']],
                'tier' => $row['anon'] ? null : ($tiers[$row['sid']] ?? null),
                'total_minor' => $showAmounts ? $row['total'] : null,
                'since' => $row['first'] ? Carbon::parse($row['first']) : null,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @param list<int> $ids @return array<int, SupporterProfile> علنيون الآن فقط (فحص حيّ بكل عرض). */
    protected function livePublic(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return SupporterProfile::query()->public()->whereIn('id', $ids)
            ->get(['id', 'public_id', 'type', 'display_name', 'slug', 'description', 'logo_path', 'website_url', 'status', 'is_public', 'created_at'])->keyBy('id')->all();
    }
}
