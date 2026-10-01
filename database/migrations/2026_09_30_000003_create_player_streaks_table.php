<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E13: ملخَّص مُجسَّد (Materialized) فقط - المصدر التاريخي الحقيقي هو
 * PuzzleAttempt الصحيحة نفسها (قابلة لإعادة البناء منها عبر
 * StreakService::recalculateFromHistory()). عمداً بلا أي حقل حماية/
 * تجميد مدفوع - كسر السلسلة لا يسحب أي شيء سبق اكتسابه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_active_date')->nullable();
            $table->timestamp('last_qualified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_streaks');
    }
};
