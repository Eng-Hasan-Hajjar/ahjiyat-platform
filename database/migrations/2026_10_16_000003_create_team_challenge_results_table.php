<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E20-C: نتيجة الفريق النهائية بتحدٍّ (صفّان كحد أقصى لكل تحدٍّ: UNIQUE(challenge, team)). تُكتب مرة واحدة بالمنفِّذ بصيغة E19 نفسها. rank: 1 للفائز وللطرفين عند التعادل، 2 للخاسر. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_challenge_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_challenge_id')->constrained('team_challenges')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedInteger('score');
            $table->unsignedTinyInteger('eligible_results_count');
            $table->unsignedBigInteger('total_duration_ms');
            $table->unsignedTinyInteger('rank');
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->unique(['team_challenge_id', 'team_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_challenge_results');
    }
};
