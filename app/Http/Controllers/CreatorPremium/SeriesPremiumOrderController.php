<?php

namespace App\Http\Controllers\CreatorPremium;

use App\Http\Controllers\Controller;
use App\Models\CreatorSeries;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceException;
use App\Services\CreatorPremium\CreatorPremiumException;
use App\Services\CreatorPremium\CreatorSeriesPurchaseService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * E43 - بدء شراء وصول سلسلة مميزة. POST /series/{series}/premium/order (CSRF من مجموعة web + auth + verified + account.active + حد معدّل commerce-checkout).
 *
 * العميل لا يحدد شيئًا ماليًا: السلسلة تُحلّ بمعرّفها العام (ULID) من استعلام الظهور العام (غير الظاهرة = 404)، ثم CreatorSeriesPurchaseService يأخذ السعر والعملة والمبدع من
 * عرض البيع في القاعدة. يُمرَّر request_key اختياري فقط (يفكّ النقر المزدوج). بعد إنشاء الطلب (أو إعادة المفتوح) يبدأ دفع E41 المستضاف ويُعاد التوجيه إلى عنوان المزوّد الذي اعتمده
 * CheckoutUrlGuard حصرًا. هذا المسار لا يمنح وصولًا: الوصول يأتي من تأكيد الدفع الموثَّق (webhook/مزامنة) في معالج المنتج.
 */
class SeriesPremiumOrderController extends Controller
{
    public function __construct(
        protected CreatorSeriesPurchaseService $purchases,
        protected CommerceCheckoutService $checkout,
        protected CreatorSeriesVisibilityService $visibility,
    ) {}

    public function store(Request $request, string $series): RedirectResponse
    {
        $model = $this->visibility->visibleQuery()->where('creator_series.public_id', Str::upper($series))->first();
        abort_unless($model instanceof CreatorSeries, 404);

        $back = route('series.show', $model->public_id);

        try {
            $purchase = $this->purchases->request($request->user(), $model, $request->input('request_key') !== null ? (string) $request->input('request_key') : null);
            $started = $this->checkout->start($request->user(), $purchase);
        } catch (CreatorPremiumException|CommerceException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }

        return redirect()->away($started['url']);
    }
}
