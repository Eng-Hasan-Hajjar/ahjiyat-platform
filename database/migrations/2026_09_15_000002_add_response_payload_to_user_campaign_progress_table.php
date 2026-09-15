<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دعم عام لـCampaignStep kind=reflection (D2) - مصدر الحقيقة يبقى
 * UserCampaignProgress.completed_at بالضبط مثل narrative، فقط نضيف عمود
 * إضافي لتخزين نص الإجابة نفسه. لا صف PuzzleAttempt لهذا الغرض أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_campaign_progress', function (Blueprint $table) {
            $table->json('response_payload')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_campaign_progress', function (Blueprint $table) {
            $table->dropColumn('response_payload');
        });
    }
};