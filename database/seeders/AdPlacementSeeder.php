<?php

namespace Database\Seeders;

use App\Models\AdPlacement;
use App\Services\Advertising\AdPlacementRegistry;
use Illuminate\Database\Seeder;

/**
 * E14 (بند 575-578): مزامنة آمنة بين سجلّ الكود وقاعدة البيانات -
 * updateOrCreate بمفتاح internal_key يجعل إعادة التشغيل Idempotent تمامًا
 * (بند 576: لا تكرار صفوف). صفوف جديدة تُبذَر بـis_active=false افتراضيًا
 * (بند 548/600: آمن افتراضيًا - المالك يُفعِّلها صراحةً لاحقًا). لا حملة
 * راعٍ وهمية تُبذَر هنا إطلاقًا (بند 577).
 */
class AdPlacementSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AdPlacementRegistry::all() as $internalKey => $definition) {
            AdPlacement::updateOrCreate(
                ['internal_key' => $internalKey],
                [
                    'name' => $definition['name'],
                    'description' => $definition['position'],
                    'surface' => $definition['surface'],
                    'position' => $definition['position'],
                ],
            );
        }
    }
}
