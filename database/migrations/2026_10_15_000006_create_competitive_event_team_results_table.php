<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E19-D: ترتيب الفرق **النهائي** لحدث معتمَد، يُكتب مرة واحدة عند الاعتماد (UNIQUE(event, team)) فيبقى ثابتًا تاريخيًا: لا يتغير بانتقال لاعبين ولا بتعطيل فريق لاحقًا.
 * score = مجموع أفضل N نتائج صحيحة لأعضاء الفريق (لقطة التسجيل). team restrictOnDelete: التاريخ لا يُمحى.
 * وعلامة team_rankings_finalized_at على الحدث تجعل الأمر الدوري Idempotent (حتى لحدث بلا فرق).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_event_team_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedInteger('score');
            $table->unsignedTinyInteger('counted_members');
            $table->unsignedBigInteger('total_duration_ms');
            $table->unsignedInteger('rank');
            $table->timestamps();

            $table->unique(['competitive_event_id', 'team_id']);
            $table->index(['competitive_event_id', 'rank']);
            $table->index('team_id');
        });

        Schema::table('competitive_events', function (Blueprint $table) {
            $table->timestamp('team_rankings_finalized_at')->nullable()->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('competitive_events', function (Blueprint $table) {
            $table->dropColumn('team_rankings_finalized_at');
        });

        Schema::dropIfExists('competitive_event_team_results');
    }
};
