<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type'); // weekly | tournament
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('bonus_gem_pool')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('challenge_puzzle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['challenge_id', 'puzzle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_puzzle');
        Schema::dropIfExists('challenges');
    }
};
