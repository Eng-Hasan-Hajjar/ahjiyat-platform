<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puzzle_category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type'); // text | image | multiple_choice
            $table->string('difficulty'); // easy | medium | hard
            $table->text('prompt');
            $table->string('image_path')->nullable();
            $table->json('choices')->nullable(); // للأسئلة متعددة الخيارات
            $table->string('answer_hash'); // hash(strtolower(trim(answer))) - ما نخزن الجواب صراحة
            $table->text('hint')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->unsignedInteger('gem_reward');
            $table->boolean('is_daily_puzzle')->default(false);
            $table->date('daily_puzzle_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_daily_puzzle', 'daily_puzzle_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzles');
    }
};
