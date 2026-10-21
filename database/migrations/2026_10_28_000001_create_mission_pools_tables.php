<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E34 - مجموعات المهام (إعدادات فقط؛ لا تاريخ مالي ولا تقدّم هنا).
 *
 * المجموعة لا تُنشئ محرك مهام: هي قائمة اختيار فوق QuestDefinition الموجودة. العضوية (mission_pool_quests) تُبقي
 * المهمة المنتمية لمجموعة غير ظاهرة للجميع؛ تظهر فقط لمن أُسندت له (انظر user_mission_assignments).
 *
 * سياسة الحذف:
 *  - حذف المجموعة يحذف عضويتها فقط (cascade). وحذف مهمة غير مستعملة يزيل عضويتها (cascade) بلا أثر على أي تاريخ.
 *  - المنشئ SET NULL (حذفه لا يحذف المجموعة).
 *  - لا أعمدة مالية: كل المكافآت تبقى بتعريف المهمة نفسه (سلطة الاقتصاد الوحيدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_pools', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();
            $table->string('period_type', 16);                              // daily | weekly (نفس QuestDefinition::PERIOD_TYPES)
            $table->unsignedTinyInteger('selection_count')->default(3);
            $table->unsignedSmallInteger('priority')->default(100);         // الأصغر أولًا؛ أول مجموعة مؤهِّلة تُسند للمستخدم
            $table->string('eligibility_rule', 24)->default('all');        // all | new_player | returning | experienced
            $table->boolean('personalized')->default(false);                // تفضيل شفاف قائم على قواعد فقط
            $table->boolean('is_active')->default(false);                   // تُنشأ مُعطَّلة: لا إسناد قبل تفعيل صريح
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'period_type', 'priority'], 'mission_pools_active_period_priority_idx');
        });

        Schema::create('mission_pool_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_pool_id')->constrained('mission_pools')->cascadeOnDelete();
            $table->foreignId('quest_definition_id')->constrained('quest_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['mission_pool_id', 'quest_definition_id'], 'mission_pool_quests_pool_quest_unique');
            $table->index('quest_definition_id', 'mission_pool_quests_quest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_pool_quests');
        Schema::dropIfExists('mission_pools');
    }
};
