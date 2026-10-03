<?php

namespace App\Services\Advertising\Providers;

/**
 * هيكل مُزوِّد خارجي آمن - "منفَّذ بالكود لكن مُعطَّل تشغيليًا" بقرار صريح.
 * لا تكامل وهمي، لا معرِّف ناشر ملفَّق، لا سكربت حقيقي يُحمَّل.
 * isConfigured() تتحقَّق من بيانات اعتماد حقيقية فقط - غيابها = none() بصمت.
 */
class GoogleAdSenseProvider implements AdProviderContract
{
    public function isConfigured(): bool
    {
        return filled(config('advertising.google_adsense.client_id'));
    }

    public function selectFor(string $placementInternalKey): AdRenderResult
    {
        if (! $this->isConfigured()) {
            return AdRenderResult::none();
        }

        // مؤجَّل تشغيليًا عمدًا: لا اختيار/تحميل وحدة AdSense حقيقية بهذا الإصدار.
        return AdRenderResult::none();
    }
}