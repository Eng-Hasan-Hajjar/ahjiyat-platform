<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id')->nullable()->after('id');
            $table->string('profile_visibility')->default('private')->after('public_id');
        });

        DB::table('users')->whereNull('public_id')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'profile_visibility']);
        });
    }
};