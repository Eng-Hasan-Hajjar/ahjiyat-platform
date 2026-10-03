<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E14 (بند 36-38): Many-to-Many - لا تخزين أي Selector/XPath/مسار Blade، Metadata الربط فقط. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_ad_placement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsor_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_placement_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sponsor_campaign_id', 'ad_placement_id'], 'campaign_placement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_ad_placement');
    }
};
