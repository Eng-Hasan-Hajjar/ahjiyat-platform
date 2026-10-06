<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E18-A: قواعد جوائز الحدث (Structured فقط، بلا نص حر ولا منطق قابل للتنفيذ). كل قاعدة تشير لتعريف حقيقي من الكتالوج (عملة أو عنصر متجر) بـFK
 * restrictOnDelete: لا يُحذف تعريف جائزة ما دامت قاعدة تستعمله. kind: rank (نطاق مراكز min..max) أو participation (لصاحب نتيجة صحيحة خارج كل
 * نطاقات المراكز). participation_event_id UNIQUE: قاعدة مشاركة واحدة كحد أقصى لكل حدث. منع تداخل النطاقات يفرضه المصحِّح بنطاق الحدث (الحارس بالنموذج).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->cascadeOnDelete();
            $table->string('kind', 16);                       // rank | participation
            $table->unsignedInteger('min_rank')->nullable();
            $table->unsignedInteger('max_rank')->nullable();
            $table->string('reward_type', 16);                // currency | xp | store_item
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->restrictOnDelete();
            $table->unsignedInteger('amount');                // مبلغ العملة / نقاط XP / كمية العنصر
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('participation_event_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['competitive_event_id', 'kind', 'min_rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitive_reward_rules');
    }
};
