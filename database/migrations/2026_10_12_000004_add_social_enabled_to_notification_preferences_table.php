<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E16: تفضيل واحد لفئة الإشعارات الاجتماعية (طلب صداقة جديد + قبول طلبك)، لا تفضيل لكل حدث. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('social_enabled')->default(true)->after('campaign_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('social_enabled');
        });
    }
};
