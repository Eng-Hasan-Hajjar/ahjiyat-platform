<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            // كل الحقول Nullable/بقيمة افتراضية - صفر تأثير على الصفوف الحالية،
            // ولا حاجة لأي Backfill. عندما game_type = null نتعامل مع الأحجية
            // كأحد الأنواع الثلاثة الكلاسيكية بالضبط كما تعمل اليوم.
            $table->string('game_type')->nullable()->after('type');

            // بيانات عرض عامة تصل للمتصفح - ممنوع تحتوي الحل الصحيح إطلاقاً
            $table->json('game_config')->nullable()->after('game_type');

            // الحل الصحيح الخاص بنوع اللعبة - يُقرأ حصراً من داخل Validators
            // بمجلد app/GameEngine، ولا يصل أبداً لأي Blade view أو API response
            $table->json('solution_data')->nullable()->after('game_config');

            $table->string('renderer')->nullable()->after('solution_data');
            $table->string('validation_type')->nullable()->after('renderer');
            $table->string('score_mode')->default('flat')->after('validation_type');
        });
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropColumn([
                'game_type', 'game_config', 'solution_data',
                'renderer', 'validation_type', 'score_mode',
            ]);
        });
    }
};