<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->string('xp_mode')->default('inherit')->after('reward_currency_id');
            $table->unsignedInteger('xp_override_amount')->nullable()->after('xp_mode');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->dropColumn(['xp_mode', 'xp_override_amount']);
        });
    }
};