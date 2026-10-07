<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E20-D: الترتيب النهائي للبطولة (يُكتب مرة واحدة عند الاعتماد: UNIQUE(championship, team)) فيبقى ثابتًا تاريخيًا. المصدر: نتائج الأحداث المعتمَدة وقت الاعتماد ولقطة النقاط. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_championship_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_championship_id')->constrained('team_championships')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedInteger('points');
            $table->unsignedSmallInteger('events_count');
            $table->unsignedSmallInteger('event_wins');
            $table->unsignedSmallInteger('top3_count');
            $table->unsignedInteger('best_rank');
            $table->unsignedInteger('rank');
            $table->timestamps();

            $table->unique(['team_championship_id', 'team_id'], 'tcr_champ_team_unique');
            $table->index(['team_championship_id', 'rank']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_championship_results');
    }
};
