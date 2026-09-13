<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->string('context_type')->nullable()->after('submission_snapshot');
            $table->unsignedBigInteger('context_id')->nullable()->after('context_type');

            $table->foreignId('game_session_id')->nullable()->after('context_id')
                ->constrained('game_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_session_id');
            $table->dropColumn(['context_type', 'context_id']);
        });
    }
};