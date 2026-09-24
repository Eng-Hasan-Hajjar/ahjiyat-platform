<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('currencies')->updateOrInsert(
            ['internal_key' => 'platform-earned'],
            [
                'code' => 'GEM',
                'name' => 'جواهر',
                'short_name' => 'جوهرة',
                'description' => 'العملة الأساسية المكتسَبة من حل الأحجيات والحملات - الجواهر التاريخية للمنصة.',
                'type' => 'standard',
                'is_active' => true,
                'is_earnable' => true,
                'is_spendable' => true,
                'is_purchasable' => false,
                'is_redeemable' => true,
                'is_system' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('currencies')->updateOrInsert(
            ['internal_key' => 'platform-premium'],
            [
                'code' => 'PRM',
                'name' => 'الرصيد المميَّز',
                'short_name' => 'مميَّز',
                'description' => 'عملة رسمية مميَّزة - الشراء الفعلي غير متاح بعد (بانتظار ربط بوابة الدفع بمرحلة لاحقة).',
                'type' => 'premium',
                'is_active' => true,
                'is_earnable' => false,
                'is_spendable' => false,
                'is_purchasable' => true,
                'is_redeemable' => false,
                'is_system' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
    }
};