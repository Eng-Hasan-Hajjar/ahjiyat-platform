<?php

namespace App\View\Components;

use App\Services\Advertising\AdServingService;
use App\Services\Advertising\AdTrackingService;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * E14 (بند 603): واجهة موحَّدة وحيدة - الصفحات لا تعرف شيئًا عن Direct أو
 * Google إطلاقًا، فقط <x-ad-slot name="..." />. التحديد بالفئة (لا Anonymous
 * Component) لأن منطق استدعاء الخدمة وتسجيل Impression يحتاج حقن حقيقي.
 */
class AdSlot extends Component
{
    public bool $isMobileDevice;

    public function __construct(public string $name)
    {
        $this->isMobileDevice = (bool) preg_match('/Mobi|Android|iPhone/i', request()->userAgent() ?? '');
    }

    public function render(): View
    {
        $result = app(AdServingService::class)->serve($this->name, $this->isMobileDevice);

        if ($result->hasAd && $result->providerType === 'direct') {
            app(AdTrackingService::class)->recordImpression($result->placementId, $result->campaignId, $result->creativeId);
        }

        return view('components.ad-slot', ['result' => $result]);
    }
}
