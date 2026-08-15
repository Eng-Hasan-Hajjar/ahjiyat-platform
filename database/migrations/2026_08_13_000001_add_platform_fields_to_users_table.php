<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email'); // user | admin
            $table->boolean('is_frozen')->default(false)->after('role');
            $table->string('frozen_reason')->nullable()->after('is_frozen');
            $table->timestamp('last_seen_at')->nullable()->after('frozen_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_frozen', 'frozen_reason', 'last_seen_at']);
        });
    }
};
