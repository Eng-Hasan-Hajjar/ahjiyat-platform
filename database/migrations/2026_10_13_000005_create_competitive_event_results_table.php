<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E17-C: نتيجة مشارك (واحدة): كلها قيم محسوبة بالسيرفر. UNIQUE(event, user) وUNIQUE(game_session). final_rank لقطة الترتيب النهائي
 * (تُكتب مرة عند الإنهاء). فهرس (event, score, duration_ms) لاستعلام الترتيب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_event_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_session_id')->unique()->constrained('game_sessions')->cascadeOnDelete();
            $table->boolean('is_correct');
            $table->unsignedInteger('score');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('completed_at');
            $table->unsignedInteger('final_rank')->nullable();
            $table->timestamps();

            $table->unique(['competitive_event_id', 'user_id']);
            $table->index(['competitive_event_id', 'score', 'duration_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitive_event_results');
    }
};
