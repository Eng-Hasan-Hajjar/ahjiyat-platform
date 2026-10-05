<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل "أول إكمال" لحملة لمستخدم - ليس مصدر حقيقة لقواعد الإكمال (يبقى مشتقًا بـCampaignProgressService::isCampaignCompleted).
 * وظيفته الوحيدة منع تكرار الإعلان: UNIQUE(user, campaign) + insertOrIgnore ذري. تاريخي: لا يُمسح بتعطيل الحملة ولا بتعديل
 * الإدارة لمحتواها لاحقًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_campaign_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_campaign_completions');
    }
};
