<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E20-B: الروستر المقفَّل ونتيجة اللاعب بالمباراة. صف واحد = مقعد لاعب بفريق معيّن (لقطة الفريق والدور وقت الاختيار/القفل، لا العضوية الحالية). UNIQUE(challenge, user):
 * لاعب واحد لا يمثّل فريقين بالتحدّي نفسه بالقاعدة. نتيجته (is_correct/score/duration_ms/completed_at) يكتبها الخادم مرة واحدة فقط (UPDATE شرطي على completed_at IS NULL)،
 * وgame_session_id UNIQUE. status: selected (قبل القفل) → locked → played|missed. لا تعديل للروستر بعد locked_at (حارس النموذج).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_challenge_id')->constrained('team_challenges')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_snapshot', 8)->nullable();
            $table->string('status', 10)->default('selected');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('game_session_id')->nullable()->unique()->constrained('game_sessions')->nullOnDelete();
            $table->boolean('is_correct')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['team_challenge_id', 'user_id']);
            $table->index(['team_challenge_id', 'team_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_challenge_participants');
    }
};
