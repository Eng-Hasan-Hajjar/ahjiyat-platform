<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E17-A: نتيجة كل طرف (واحدة فقط): UNIQUE(challenge, user) وUNIQUE(game_session) يمنعان تكرار النتيجة أو إعادة استعمال جلسة. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friend_challenge_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('friend_challenge_id')->constrained('friend_challenges')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_session_id')->unique()->constrained('game_sessions')->cascadeOnDelete();
            $table->boolean('is_correct');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('score');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['friend_challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_challenge_results');
    }
};
