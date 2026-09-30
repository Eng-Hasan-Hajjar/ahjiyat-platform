<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('level_number')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('xp_required_total');

            $table->foreignId('reward_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->unsignedInteger('reward_currency_amount')->nullable();
            $table->foreignId('reward_store_item_id')->nullable()->constrained('store_items')->restrictOnDelete();
            $table->unsignedInteger('reward_item_quantity')->nullable();

            $table->string('icon_path')->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('xp_required_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_definitions');
    }
};