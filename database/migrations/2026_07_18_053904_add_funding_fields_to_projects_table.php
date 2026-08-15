<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('funding_type', ['self_funded', 'individual_donations', 'international_org', 'government', 'mixed'])
                  ->nullable()->after('actual_cost');
            $table->string('funding_organization')->nullable()->after('funding_type');
            $table->decimal('funding_amount', 12, 2)->nullable()->after('funding_organization');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['funding_type', 'funding_organization', 'funding_amount']);
        });
    }
};