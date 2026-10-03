<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E14: نقطة عرض معروفة بالواجهة - ليست إعلانًا ولا حملة. internal_key
 * مُقيَّد بسجلّ الكود (AdPlacementRegistry) حصرًا - الإدارة تُفعِّل/تُعطِّل
 * فقط، لا تخترع موضع DOM جديد عبر نص حر إطلاقًا (بند 12/317).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table) {
            $table->id();
            $table->string('internal_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('surface');
            $table->string('position');
            $table->boolean('is_active')->default(false);
            $table->boolean('desktop_enabled')->default(true);
            $table->boolean('mobile_enabled')->default(true);
            $table->unsignedInteger('max_ads_per_render')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placements');
    }
};
