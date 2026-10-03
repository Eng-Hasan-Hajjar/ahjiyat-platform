<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E14 (بند 46-56): "Impression" هنا = عُرِضت Creative فعليًا باستجابة
 * الخادم (Render Impression) - ليست ادِّعاء رؤية فعلية. Append-Only، لا
 * user_id ولا أي بصمة جهاز - خصوصية أولًا، إجمالي فقط (بند 52/55).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_placement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_creative_id')->constrained()->cascadeOnDelete();
            $table->timestamp('rendered_at');

            $table->index(['sponsor_campaign_id', 'rendered_at']);
            $table->index(['ad_placement_id', 'rendered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_impressions');
    }
};
