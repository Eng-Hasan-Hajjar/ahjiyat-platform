<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E18-B: سجل منح الجوائز التنافسية = سجل **التوزيع** فقط. الأصل نفسه (رصيد، XP، مخزون، امتياز) مصدر حقيقته الدفتر الاقتصادي الحالي، لا هذا الجدول
 * (لا محاسبة مزدوجة). UNIQUE(event, user): جائزة واحدة لكل لاعب بكل حدث، فلا منح مكرر أبدًا بأي مسار أو سباق. اللقطة (النوع، المرجع، المبلغ، الوصف،
 * المركز النهائي) محفوظة بالسجل فلا يمحو حذف/تعديل تعريف تاريخ المنح. reward_rule_id restrictOnDelete: قاعدة لها منح لا تُحذف.
 * status: pending | granted | failed (فشل فرد لا يُرجع الحدث ولا غيره، ويُعاد بإجراء صريح).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_reward_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitive_event_result_id')->constrained('competitive_event_results')->cascadeOnDelete();
            $table->foreignId('competitive_reward_rule_id')->constrained('competitive_reward_rules')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('final_rank');
            $table->string('reward_type', 16);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('reward_label', 160);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->unique(['competitive_event_id', 'user_id']);
            $table->index(['competitive_event_id', 'status']);
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitive_reward_grants');
    }
};
