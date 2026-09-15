<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Season هي Wrapper رسمية/تسويقية حول Campaign - لا تكرر أي بيانات
 * Content/Availability موجودة أصلاً على Campaign (title/description/cover/
 * starts_at/ends_at/is_active). campaign_id فريد (unique) عمداً: كل Campaign
 * تخص موسمًا رسميًا واحدًا على الأكثر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('slug')->unique();
            $table->string('banner_image')->nullable();
            $table->string('logo_image')->nullable();
            $table->text('grand_prize_description')->nullable();
            $table->json('theme_config')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};