<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_item_price_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('price_amount');

            $table->string('status');

            $table->string('request_key')->unique();

            $table->json('item_snapshot');

            $table->string('fulfillment_type');

            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();

            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();

            $table->text('admin_note')->nullable();
            $table->string('user_message')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('store_item_id');
            $table->index('currency_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_purchases');
    }
};