<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cosmetic_loadouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slot');
            $table->foreignId('store_item_id')->constrained()->restrictOnDelete();
            $table->timestamp('equipped_at');
            $table->timestamps();

            $table->unique(['user_id', 'slot']);
            $table->index('store_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cosmetic_loadouts');
    }
};