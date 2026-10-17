<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * E30 - يشغّل بيانات Demo للوحدتين المستقلتين معًا: الرعاية والداعمون، وسلاسل المبدعين.   php artisan db:seed --class=DemoE30Seeder
 * اختيارية وترفض الإنتاج. كل وحدة تعمل منفردة (DemoSponsorshipSeeder / DemoCreatorSeriesSeeder) ولا تستدعي إحداهما الأخرى.
 */
class DemoE30Seeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction('DemoE30Seeder');
        $this->call(DemoSponsorshipSeeder::class);
        $this->call(DemoCreatorSeriesSeeder::class);
    }
}
