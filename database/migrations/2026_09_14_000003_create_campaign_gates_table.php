<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_stage_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('narrative_intro')->nullable();
            $table->unsignedInteger('sort_order');

            // null = تأهّل غير مشروط (كل من يكمل خطوات البوابة يتأهّل تلقائياً).
            // لا نجعل first_n افتراضياً أبداً - قرار صريح لكل بوابة على حدة.
            $table->string('qualification_rule')->nullable();
            $table->json('qualification_config')->nullable();

            $table->timestamps();

            $table->index(['campaign_stage_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_gates');
    }
};