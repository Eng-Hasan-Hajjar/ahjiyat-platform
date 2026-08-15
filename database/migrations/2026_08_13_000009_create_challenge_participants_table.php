<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('bonus_gems_awarded')->default(0);
            $table->timestamps();

            $table->unique(['challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_participants');
    }
};
