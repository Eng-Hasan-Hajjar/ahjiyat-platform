<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->foreignId('reward_currency_id')->nullable()->after('reward_override_amount')->constrained('currencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->dropForeign(['reward_currency_id']);
            $table->dropColumn('reward_currency_id');
        });
    }
};