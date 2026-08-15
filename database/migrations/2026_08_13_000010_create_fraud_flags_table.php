<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason'); // multiple_accounts | rapid_redemption | ip_shared | abnormal_earn_rate ...
            $table->string('severity')->default('low'); // low | medium | high
            $table->text('details')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
    }
};
