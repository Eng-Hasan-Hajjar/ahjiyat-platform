<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('gem_transactions', 'currency_transactions');

        Schema::table('currency_transactions', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable()->after('reference_id');
            $table->string('idempotency_key')->nullable()->unique()->after('metadata');
        });

        $legacyCurrencyId = DB::table('currencies')->where('internal_key', 'platform-earned')->value('id');

        DB::table('currency_transactions')->whereNull('currency_id')->update(['currency_id' => $legacyCurrencyId]);

        Schema::table('currency_transactions', function (Blueprint $table) {
            $table->index(['currency_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('currency_transactions', function (Blueprint $table) {
            $table->dropIndex(['currency_id', 'type']);
            $table->dropColumn(['metadata', 'idempotency_key']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });

        Schema::rename('currency_transactions', 'gem_transactions');
    }
};