<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E13: period_key/period_start/period_end صريحة ومُخزَّنة (لا اعتماد هش
 * على created_at=اليوم). target_value_snapshot يحفظ الهدف وقت بداية
 * الفترة تحديدًا - تعديل التعريف لاحقًا لا يُربِك سجلًا تاريخيًا قائمًا
 * (نفس فلسفة StorePurchase/الإنجازات - لا Reward Snapshot ضخمة، فالتعريف
 * الحساس نفسه يُقفَل بعد الاستخدام أصلًا، فلا حاجة لتكرار كل تفاصيله هنا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_quest_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_definition_id')->constrained()->restrictOnDelete();
            $table->string('period_type');
            $table->string('period_key');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('current_value')->default(0);
            $table->unsignedInteger('target_value_snapshot');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reward_granted_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'quest_definition_id', 'period_key']);
            $table->index('period_key');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quest_progress');
    }
};
