<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E16: علاقة صداقة واحدة لكل زوج مستخدمين (pending ثم accepted). الرفض/الإلغاء/الإزالة تحذف الصف (لا حالات زائدة).
 *
 * منع ازدواج A→B مع B→A على مستوى قاعدة البيانات: pair_key = "min:max" لمعرّفَي الطرفين (يملؤه النموذج دائمًا) وعليه UNIQUE،
 * فيستحيل وجود صفين لنفس الزوج بأي اتجاه حتى عند سباق طلبين متزامنين. (الصداقة الذاتية يمنعها الـDomain.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('addressee_id')->constrained('users')->cascadeOnDelete();
            $table->string('pair_key', 41)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['addressee_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
