<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_packs', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('base_amount');
            $table->unsignedInteger('bonus_amount')->default(0);
            $table->unsignedInteger('price_minor');
            $table->string('fiat_currency', 3)->default('USD');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['currency_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_packs');
    }
};