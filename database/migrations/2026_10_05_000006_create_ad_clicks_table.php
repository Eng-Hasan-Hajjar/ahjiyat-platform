<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E14 (بند 58-62): Direct Sponsor فقط - Append-Only، صفر IP خام، صفر User Agent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_placement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_creative_id')->constrained()->cascadeOnDelete();
            $table->timestamp('clicked_at');

            $table->index(['sponsor_campaign_id', 'clicked_at']);
            $table->index(['ad_placement_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_clicks');
    }
};
