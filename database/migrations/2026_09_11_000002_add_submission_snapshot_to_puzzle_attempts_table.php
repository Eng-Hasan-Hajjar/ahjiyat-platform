<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            // نسخة من بيانات الحل المُرسلة فعلياً - ضرورية لمراجعة الاحتيال
            // بألعاب Phase 2+ (Drag & Drop، Memory...)، نبدأ بتفعيلها من الآن.
            $table->json('submission_snapshot')->nullable()->after('used_hint');
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropColumn('submission_snapshot');
        });
    }
};