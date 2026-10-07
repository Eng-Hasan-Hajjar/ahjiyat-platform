<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E20-A: تحدّي فريق ضد فريق (غير متزامن). active_key ("minTeam:maxTeam:puzzle" ما دام pending/accepted وNULL بعدها) UNIQUE: تحدّيان نشطان لنفس الفريقين والأحجية
 * (بأي اتجاه A→B وB→A) مستحيلان بالقاعدة. لا درجات هنا: النتيجة في team_challenge_results. الفرق والأحجية restrictOnDelete: التاريخ لا يُمحى بتعطيل/حذف.
 * expires_at مهلة القبول، play_ends_at مهلة اللعب (تبدأ بالقبول وقفل الروستر). winner_team_id وis_draw يشتقهما الخادم فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('challenger_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('opponent_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('puzzle_id')->constrained('puzzles')->restrictOnDelete();
            $table->string('status', 12)->default('pending');           // pending|accepted|completed|declined|cancelled|expired
            $table->string('active_key', 80)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('play_ends_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('winner_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->boolean('is_draw')->default(false);
            $table->timestamps();

            $table->index(['challenger_team_id', 'status']);
            $table->index(['opponent_team_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'play_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_challenges');
    }
};
