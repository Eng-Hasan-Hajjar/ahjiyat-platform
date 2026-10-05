<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل "أول فتح" لمرحلة لمستخدم - ليس مصدر حقيقة لقواعد التقدّم (الفتح يبقى مشتقًا بـCampaignProgressService::isStageUnlocked).
 * وظيفته الوحيدة منع تكرار الإعلان: UNIQUE(user, stage) + insertOrIgnore ذري. المراحل الأولى لا تُسجَّل (مفتوحة للجميع).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stage_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_stage_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->unique(['user_id', 'campaign_stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stage_unlocks');
    }
};
