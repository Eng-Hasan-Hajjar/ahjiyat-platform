<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E34 - لقطة إسناد المهام لكل مستخدم لكل فترة (تحدّد **الظهور** فقط؛ التقدّم والمكافأة يبقيان في user_quest_progress).
 *
 * حدود السلامة في قاعدة البيانات:
 *  - UNIQUE(user_id, quest_definition_id, period_key) ⇒ مهمة واحدة لا تُسند لمستخدم مرتين في الفترة نفسها،
 *    مهما تزامن الطلبان؛ وهي أيضًا الحاجز الأخير خلف قفل صف المستخدم في MissionAssignmentService.
 *  - الإسناد كسول: لا صفوف جماعية مسبقة؛ يُنشأ لمستخدم واحد عند فتح فعلي أو حدث لعب رسمي، وبحدّ selection_count لكل مجموعة.
 *  - اللقطة ثابتة طوال الفترة: تعديل المجموعة لاحقًا لا يبدّل ما أُسند (يسري على الفترة التالية).
 * سياسة الحذف: المستخدم/المهمة cascade (لقطة ظهور مشتقة بلا قيمة مالية؛ المكافأة الفعلية بدفاتر الاقتصاد)، المجموعة RESTRICT
 * (لا تُحذف مجموعة لها تاريخ إسناد؛ تُعطَّل بدلًا من ذلك).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mission_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_pool_id')->constrained('mission_pools')->restrictOnDelete();
            $table->foreignId('quest_definition_id')->constrained('quest_definitions')->cascadeOnDelete();
            $table->string('period_key', 32);
            $table->unsignedTinyInteger('slot')->default(0);
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['user_id', 'quest_definition_id', 'period_key'], 'user_mission_assignments_user_quest_period_unique');
            $table->index(['user_id', 'period_key'], 'user_mission_assignments_user_period_idx');
            $table->index(['mission_pool_id', 'period_key'], 'user_mission_assignments_pool_period_idx');
            $table->index('quest_definition_id', 'user_mission_assignments_quest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mission_assignments');
    }
};
