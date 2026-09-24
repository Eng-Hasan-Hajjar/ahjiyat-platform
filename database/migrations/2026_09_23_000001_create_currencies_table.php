<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('internal_key')->unique();
            $table->string('code', 10);
            $table->string('name');
            $table->string('short_name', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('icon_path')->nullable();
            $table->string('color', 20)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_earnable')->default(false);
            $table->boolean('is_spendable')->default(false);
            $table->boolean('is_purchasable')->default(false);
            $table->boolean('is_redeemable')->default(false);
            $table->boolean('is_system')->default(false);

            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};