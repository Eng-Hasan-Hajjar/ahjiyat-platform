<?php

namespace App\Services\Advertising\Providers;

/**
 * E14 (بند 591/592): هيكل مُزوِّد خارجي آمن - "منفَّذ بالكود لكن مُعطَّل
 * تشغيليًا" بقرار صريح (راجع التقرير النهائي: غياب CSP قائم بالمشروع أصلًا
 * + غياب حساب AdSense فعلي + قرار CMP معتمَد غير محسوم وقت التنفيذ). لا
 * تكامل وهمي، لا معرِّف ناشر ملفَّق، لا سكربت حقيقي يُحمَّل. isConfigured()
 * تتحقَّق من بيانات اعتماد حقيقية فقط - غيابها = selectFor() تُعيد none()
 * دومًا بصمت، بلا أي استثناء يكسر الصفحة (بند 557/638).
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

        // E14: عمدًا بلا تنفيذ فعلي لاختيار/تحميل وحدة AdSense حقيقية -
        // قرار تشغيلي مؤجَّل صراحةً (راجع التقرير). هذا الفرع لن يُنفَّذ
        // عمليًا بالإنتاج الحالي لأن isConfigured() أعلاه تمنعه دائمًا ما
        // لم يُضَف ADSENSE_CLIENT_ID حقيقي + يُستكمَل القرار التشغيلي.
        return AdRenderResult::none();
    }
}
