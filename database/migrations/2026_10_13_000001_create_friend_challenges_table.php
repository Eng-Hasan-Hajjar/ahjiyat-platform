<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E17-A: تحدٍّ بين صديقين على أحجية واحدة. status: pending|accepted|completed|declined|cancelled|expired.
 * active_pair_key: "min:max" للطرفين ما دام التحدي pending/accepted وNULL بعدها، وعليه UNIQUE، فلا يوجد أكثر من تحدٍّ نشط واحد
 * لكل زوج (منع إغراق + ازدواج) على مستوى قاعدة البيانات (NULL متعدد مسموح). expires_at: مهلة القبول ثم مهلة اللعب بعد القبول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friend_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('challenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opponent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained('puzzles')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('active_pair_key', 41)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_draw')->default(false);
            $table->timestamps();

            $table->index(['opponent_id', 'status']);
            $table->index(['challenger_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_challenges');
    }
};
