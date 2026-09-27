<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->string('cosmetic_slot')->nullable()->after('entitlement_duration_days');
            $table->string('cosmetic_text', 60)->nullable()->after('cosmetic_slot');
            $table->string('cosmetic_color', 7)->nullable()->after('cosmetic_text');

            $table->index('cosmetic_slot');
        });
    }

    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->dropIndex(['cosmetic_slot']);
            $table->dropColumn(['cosmetic_slot', 'cosmetic_text', 'cosmetic_color']);
        });
    }
};