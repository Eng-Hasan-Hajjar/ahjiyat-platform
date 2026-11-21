<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E47: «مختبر الأحجيات» - جدول واحد لمحتوى تمارين التدريب (additive فقط: لا تعديل على أي جدول قائم).
 *
 * - لا جدول للجولات ولا للمحاولات ولا للنتائج عمدًا: الجولة بلا حالة مخزَّنة (انظر App\Services\PuzzleLab\PuzzleLabRounds)، فلا سجل تدريب دائم ولا أثر على puzzle_attempts أو المحافظ أو التقدّم.
 * - game_config يحمل بيانات التأليف الخام نفسها التي تستعملها أحجيات memory/sequence الرسمية (faces / items) كي تُعاد محرّرات Filament القائمة بلا JSON يدوي.
 * - عمدًا بلا مفتاح أجنبي على users (created_by/updated_by معرّفات للعرض فقط؛ حذف مستخدم لا يمسّ التمرين).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzle_lab_exercises', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('game_type', 24);                      // memory | sequence (يُفرض بالنموذج لا بقيد قاعدة)
            $table->string('level', 12)->default('easy');        // easy | medium | hard (نفس مفردات الأحجيات)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title', 120);
            $table->string('summary', 220)->nullable();
            $table->text('instructions')->nullable();
            $table->text('wrong_note')->nullable();               // ملاحظة تعليمية تُعرض بعد محاولة خاطئة فقط
            $table->text('success_explanation')->nullable();      // شرح موجز يُعرض عند النجاح فقط
            $table->json('game_config')->nullable();
            $table->string('status', 12)->default('draft');       // draft | published | hidden
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'puzzle_lab_status_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_lab_exercises');
    }
};
