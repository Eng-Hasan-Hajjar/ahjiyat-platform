<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzle_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->boolean('is_correct');
            $table->boolean('used_hint')->default(false);
            $table->unsignedInteger('time_taken_seconds')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'puzzle_id', 'attempt_number']);
            $table->index(['user_id', 'puzzle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_attempts');
    }
};
