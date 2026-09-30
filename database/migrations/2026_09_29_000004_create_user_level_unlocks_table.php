<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_level_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_definition_id')->constrained()->restrictOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamp('reward_granted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'level_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_level_unlocks');
    }
};