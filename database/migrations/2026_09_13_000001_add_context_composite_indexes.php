<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B.1: previousAttempts/hasSolvedPuzzle/GameSession lookups أصبحوا
 * يفلترون الآن بـ context_type+context_id بالإضافة لـ user/puzzle/status -
 * فهرس مركّب واحد يخدم هذا المسار الساخن أفضل من فهرسين منفصلين يحتاج
 * المخطِّط لدمجهما. إضافي بالكامل - لا يمس أي فهرس أو عمود موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->index(
                ['user_id', 'puzzle_id', 'context_type', 'context_id', 'status'],
                'game_sessions_context_lookup_idx'
            );
        });

        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->index(
                ['user_id', 'puzzle_id', 'context_type', 'context_id'],
                'puzzle_attempts_context_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropIndex('game_sessions_context_lookup_idx');
        });

        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropIndex('puzzle_attempts_context_lookup_idx');
        });
    }
};