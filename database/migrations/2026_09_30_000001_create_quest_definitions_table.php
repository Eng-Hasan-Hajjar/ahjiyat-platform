<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E13: تعريف المهمة نفسها ("حل 3 أحجيات اليوم") - منفصلة تمامًا عن
 * Achievement (معلَم طويل الأمد) وعن Challenge (منافسة بنتيجة/ترتيب).
 * بنفس فلسفة Achievement البنيوية (internal_key ثابت بعد الاستخدام،
 * condition_type من Registry مغلقة) + period_type الجديد الخاص بـQuest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('internal_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('period_type');
            $table->string('condition_type');
            $table->unsignedInteger('target_value');
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->unsignedInteger('xp_reward')->default(0);
            $table->foreignId('reward_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->unsignedInteger('reward_currency_amount')->nullable();
            $table->foreignId('reward_store_item_id')->nullable()->constrained('store_items')->restrictOnDelete();
            $table->unsignedInteger('reward_item_quantity')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('period_type');
            $table->index('condition_type');
            $table->index('is_active');
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_definitions');
    }
};
