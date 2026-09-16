<?php

namespace App\Filament\GameTypeAuthoring;

/**
 * منطق تحقّق الفروق مُستخرَج بمعزل عن الـFilament Field/Closure عمداً - قابل
 * للاختبار مباشرة بلا حاجة لتشغيل Livewire/Filament بالاختبارات (المشروع لا
 * يملك pest-plugin-livewire حالياً). عام تمامًا - ليس خاصًا بأي موسم.
 */
class SpotDifferenceHotspotValidation
{
    /** يعيد أول رسالة خطأ إن وُجدت، أو null إن كانت كل الفروق صحيحة. */
    public static function firstError(array $hotspots): ?string
    {
        if (count($hotspots) < 1) {
            return 'يجب تحديد فرق واحد على الأقل بالصورة.';
        }

        foreach ($hotspots as $index => $hotspot) {
            $position = $index + 1;
            $x = $hotspot['x'] ?? null;
            $y = $hotspot['y'] ?? null;
            $radius = $hotspot['radius'] ?? null;

            if (! is_numeric($x) || $x < 0 || $x > 1) {
                return "الفرق رقم {$position}: قيمة الموقع الأفقي غير صحيحة.";
            }

            if (! is_numeric($y) || $y < 0 || $y > 1) {
                return "الفرق رقم {$position}: قيمة الموقع العمودي غير صحيحة.";
            }

            if (! is_numeric($radius) || $radius < 0.01 || $radius > 0.5) {
                return "الفرق رقم {$position}: حجم منطقة الالتقاط خارج النطاق المسموح.";
            }
        }

        return null;
    }
}