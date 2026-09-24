<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });

        $legacyCurrencyId = DB::table('currencies')->where('internal_key', 'platform-earned')->value('id');

        DB::table('wallets')->whereNull('currency_id')->update(['currency_id' => $legacyCurrencyId]);

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'currency_id']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
            $table->unique('user_id');
        });
    }
};