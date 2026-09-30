<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('internal_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('general');
            $table->string('condition_type');
            $table->unsignedInteger('target_value')->nullable();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->unsignedInteger('xp_reward')->default(0);
            $table->foreignId('reward_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->unsignedInteger('reward_currency_amount')->nullable();
            $table->foreignId('reward_store_item_id')->nullable()->constrained('store_items')->restrictOnDelete();
            $table->unsignedInteger('reward_item_quantity')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('icon_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('condition_type');
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};