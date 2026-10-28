<?php

namespace App\Http\Controllers\CreatorPremium;

use App\Http\Controllers\Creators\CreatorController;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorSeries;
use App\Services\CreatorPremium\CreatorPremiumAnalytics;
use App\Services\CreatorPremium\CreatorPremiumPolicy;
use App\Services\CreatorPremium\CreatorSeriesOfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * E43 - استوديو السلاسل المميزة للمبدع: يعرض عروض بيع سلاسله ومبيعاته المجمَّعة، ويدير عروضه (إنشاء/سعر/تفعيل/إيقاف/إغلاق) عبر CreatorSeriesOfferService فقط.
 * الأهلية بميدلوير E28 نفسه + بالخدمة (دفاع مزدوج). كل عرض يُحلّ بمعرّف عام ULID محصورًا بمالكه (عرض غيرك = 404). **بيانات المبدع نفسه فقط** ولا هوية مشترٍ في أي مخرج.
 * السعر يُكتب نصًا بعملة حسابه (الخيار A) ويحلله الخادم؛ لا يتحكم المبدع بالعملة ولا بنسبة المنصة.
 */
class CreatorPremiumStudioController extends CreatorController
{
    public function __construct(
        protected CreatorSeriesOfferService $offers,
        protected CreatorPremiumAnalytics $analytics,
        protected CreatorPremiumPolicy $policy,
    ) {
        abort_unless((bool) config('creator_series.enabled', true), 404);
    }

    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $account = CreatorEarningAccount::query()->where('creator_profile_id', $profile->getKey())->first();

        $series = CreatorSeries::query()
            ->select('creator_series.*')
            ->leftJoin('creator_series_sale_offers as pso', 'pso.live_series_id', '=', 'creator_series.id')
            ->addSelect([
                'pso.public_id as offer_public_id', 'pso.status as offer_status', 'pso.price_minor as offer_price_minor', 'pso.currency as offer_currency', 'pso.pause_reason as offer_pause_reason',
                'owners' => DB::table('creator_series_accesses as acc')->selectRaw('COUNT(*)')->whereColumn('acc.creator_series_id', 'creator_series.id')->whereNull('acc.revoked_at'),
            ])
            ->where('creator_series.creator_profile_id', $profile->getKey())
            ->whereNotNull('creator_series.published_revision_id')->whereNull('creator_series.archived_at')
            ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
            ->paginate(10);

        return view('creator.premium.index', [
            'account' => $account,
            'enabled' => $this->policy->enabled(),
            'seriesList' => $series,
            'stats' => $this->analytics->forCreator($profile),
            'transactions' => $this->analytics->transactions($profile, (int) config('creator_premium.limits.transactions_per_page', 15)),
            'currency' => $account?->currency,
        ]);
    }

    public function storeOffer(Request $request): RedirectResponse
    {
        $data = $request->validate(['series' => ['required', 'string', 'size:26'], 'price' => ['required', 'string', 'max:20']]);

        return $this->perform(
            fn () => $this->offers->createDraft($request->user(), strtoupper($data['series']), $data['price']),
            'أُنشئ عرض البيع كمسودة. راجع السعر ثم فعّله.',
        );
    }

    public function updatePrice(Request $request, string $offer): RedirectResponse
    {
        $data = $request->validate(['price' => ['required', 'string', 'max:20']]);

        return $this->perform(fn () => $this->offers->updatePrice($request->user(), $offer, $data['price']), 'تم تحديث السعر؛ يسري على المشتريات الجديدة فقط.');
    }

    public function activate(Request $request, string $offer): RedirectResponse
    {
        return $this->perform(fn () => $this->offers->activate($request->user(), $offer), 'فُعّل عرض البيع.');
    }

    public function pause(Request $request, string $offer): RedirectResponse
    {
        return $this->perform(fn () => $this->offers->pause($request->user(), $offer), 'أُوقف البيع مؤقتًا. المالكون يحتفظون بوصولهم.');
    }

    public function retire(Request $request, string $offer): RedirectResponse
    {
        return $this->perform(fn () => $this->offers->retire($request->user(), $offer), 'أُغلق عرض البيع نهائيًا. صارت أحجيات السلسلة مجانية ويحتفظ المالكون بسجلّهم.');
    }
}
