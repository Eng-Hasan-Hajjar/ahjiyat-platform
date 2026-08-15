<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('volunteer_profiles', function (Blueprint $table) {
            $table->string('other_skill_note', 255)->nullable()->after('skills');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_profiles', function (Blueprint $table) {
            $table->dropColumn('other_skill_note');
        });
    }
};