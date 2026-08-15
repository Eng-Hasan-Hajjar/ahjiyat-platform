<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount'); // موجب = إضافة، سالب = خصم
            $table->string('type'); // earn_pending | release_available | redeem | expire | admin_adjustment
            $table->string('reason'); // solved_puzzle:#id, daily_streak, hint_purchase, redemption:#id ...
            $table->nullableMorphs('reference'); // يربط المعاملة بالسجل المصدر (لغز، طلب استبدال...)
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_transactions');
    }
};
