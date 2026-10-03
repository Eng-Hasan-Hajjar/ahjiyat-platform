<?php

namespace App\Http\Controllers;

use App\Models\SponsorCreative;
use App\Services\Advertising\AdTrackingService;
use App\Services\Advertising\UrlSafetyGuard;
use Illuminate\Http\RedirectResponse;

/**
 * E14 (بند 605/AN): لا Open Redirect أبدًا - المسار يستقبل معرِّف Creative
 * فقط، الخادم يقرأ الرابط المخزَّن بنفسه. GET بلا حالة حسّاسة (لا مكافأة،
 * لا تعديل) - لا قلق CSRF هنا. فشل التتبُّع لا يمنع التحويل الآمن أبدًا،
 * إلا إذا كان الرابط نفسه غير آمن - الأمان يتغلَّب فورًا حينها (بند 610/AQ).
 */
class AdClickController extends Controller
{
    public function __invoke(SponsorCreative $creative, AdTrackingService $tracking): RedirectResponse
    {
        if (! UrlSafetyGuard::isSafe($creative->destination_url)) {
            abort(404);
        }

        $tracking->recordClick($creative->campaign->placements->first()?->id ?? 0, $creative->sponsor_campaign_id, $creative->id);

        return redirect()->away($creative->destination_url);
    }
}
