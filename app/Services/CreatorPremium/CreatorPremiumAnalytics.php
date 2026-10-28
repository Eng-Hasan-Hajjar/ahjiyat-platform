<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\CreatorProfile;
use Illuminate\Support\Facades\DB;

/**
 * E43 - تحليلات المبيعات المباشرة. قراءة فقط، بتجميع قاعدة البيانات (عدد ثابت من الاستعلامات مهما كبر عدد المبيعات).
 *
 *  - المبدع: **بياناته فقط** (creator_profile_id)، مجمَّعة: لا هوية مشترٍ ولا بريد ولا مرجع دفع ولا معرّف مزوّد في أي صف. السجل المعروض: تاريخ، سلسلة، إجمالي، حصته، حالة.
 *  - الإدارة: لكل عملة على حدة (لا جمع بين عملات أبدًا ولا تحويل)، مع عدّادات المراجعة المالية.
 *  - «صافي حصة المبدع» = مجموع حصصه − حصص المبيعات المعكوسة (REFUNDED فقط؛ المعلَّق للمراجعة والمتحمَّل من المنصة يبقيان للمبدع).
 */
class CreatorPremiumAnalytics
{
    /**
     * @return array{currency: ?string, sales: int, gross_minor: int, creator_minor: int, refunded: int, reversed_minor: int, net_minor: int, window: array{days: int, sales: int, net_minor: int}, top_series: list<array{series_public_id: string, title: string, sales: int, net_minor: int}>}
     */
    public function forCreator(CreatorProfile $profile): array
    {
        $refunded = CreatorDirectSale::STATUS_REFUNDED;
        $days = max(1, (int) config('creator_premium.analytics.window_days', 30));
        $since = now()->subDays($days);

        $totals = DB::table('creator_direct_sales')->where('creator_profile_id', $profile->getKey())
            ->selectRaw('MAX(currency) as currency, COUNT(*) as sales, COALESCE(SUM(gross_minor),0) as gross, COALESCE(SUM(creator_share_minor),0) as creator, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),0) as refunded, COALESCE(SUM(CASE WHEN status = ? THEN creator_share_minor ELSE 0 END),0) as reversed', [$refunded, $refunded])
            ->first();

        $window = DB::table('creator_direct_sales')->where('creator_profile_id', $profile->getKey())->where('sold_at', '>=', $since)
            ->selectRaw('COUNT(*) as sales, COALESCE(SUM(CASE WHEN status = ? THEN 0 ELSE creator_share_minor END),0) as net', [$refunded])->first();

        $top = DB::table('creator_direct_sales')->where('creator_profile_id', $profile->getKey())
            ->groupBy('series_public_id')
            ->orderByRaw('SUM(CASE WHEN status = ? THEN 0 ELSE creator_share_minor END) DESC', [$refunded])->orderBy('series_public_id')
            ->limit(max(1, (int) config('creator_premium.analytics.top_series', 5)))
            ->selectRaw('series_public_id, MAX(series_title) as title, COUNT(*) as sales, COALESCE(SUM(CASE WHEN status = ? THEN 0 ELSE creator_share_minor END),0) as net', [$refunded])
            ->get();

        $creator = (int) $totals->creator;
        $reversed = (int) $totals->reversed;

        return [
            'currency' => $totals->currency,
            'sales' => (int) $totals->sales,
            'gross_minor' => (int) $totals->gross,
            'creator_minor' => $creator,
            'refunded' => (int) $totals->refunded,
            'reversed_minor' => $reversed,
            'net_minor' => $creator - $reversed,
            'window' => ['days' => $days, 'sales' => (int) $window->sales, 'net_minor' => (int) $window->net],
            'top_series' => $top->map(fn ($r) => ['series_public_id' => (string) $r->series_public_id, 'title' => (string) $r->title, 'sales' => (int) $r->sales, 'net_minor' => (int) $r->net])->all(),
        ];
    }

    /** سجل مبيعات المبدع (بلا هوية مشترٍ). */
    public function transactions(CreatorProfile $profile, int $perPage = 15)
    {
        return DB::table('creator_direct_sales')->where('creator_profile_id', $profile->getKey())
            ->orderByDesc('id')
            ->select(['public_id', 'series_title', 'gross_minor', 'creator_share_minor', 'currency', 'status', 'sold_at', 'refunded_at'])
            ->simplePaginate(max(1, $perPage), ['*'], 'tx');
    }

    /**
     * للإدارة: لكل عملة. @return list<array{currency: string, sales: int, gross_minor: int, creator_minor: int, platform_minor: int, refunded: int, reversed_minor: int, needs_review: int, written_off: int}>
     */
    public function forAdmin(): array
    {
        $refunded = CreatorDirectSale::STATUS_REFUNDED;

        return DB::table('creator_direct_sales')->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, COUNT(*) as sales, COALESCE(SUM(gross_minor),0) as gross, COALESCE(SUM(creator_share_minor),0) as creator, COALESCE(SUM(platform_share_minor),0) as platform, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),0) as refunded, COALESCE(SUM(CASE WHEN status = ? THEN creator_share_minor ELSE 0 END),0) as reversed, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),0) as needs_review, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END),0) as written_off',
                [$refunded, $refunded, CreatorDirectSale::STATUS_NEEDS_REVIEW, CreatorDirectSale::STATUS_WRITTEN_OFF])
            ->get()
            ->map(fn ($r) => [
                'currency' => (string) $r->currency,
                'sales' => (int) $r->sales,
                'gross_minor' => (int) $r->gross,
                'creator_minor' => (int) $r->creator,
                'platform_minor' => (int) $r->platform,
                'refunded' => (int) $r->refunded,
                'reversed_minor' => (int) $r->reversed,
                'needs_review' => (int) $r->needs_review,
                'written_off' => (int) $r->written_off,
            ])->all();
    }
}
