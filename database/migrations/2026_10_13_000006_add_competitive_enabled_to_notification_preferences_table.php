<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E17-E: تفضيل واحد لفئة إشعارات المنافسات (بدء/ينتهي قريبًا/نتائج). تحديات الأصدقاء تستعمل social_enabled الموجود. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('competitive_enabled')->default(true)->after('social_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('competitive_enabled');
        });
    }
};
