<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القيد الفريد الأصلي (user_id, puzzle_id, attempt_number) صُمِّم قبل وجود
 * Context إطلاقاً - سياقان مختلفان تمامًا لنفس (user, puzzle) كانا يتصادمان
 * على attempt_number=1 رغم استقلالهما المنطقي الكامل بعد Phase B.1. هذا
 * التصحيح يضيف context_type/context_id لنفس القيد ليعكس الواقع الجديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropUnique('puzzle_attempts_user_id_puzzle_id_attempt_number_unique');

            $table->unique(
                ['user_id', 'puzzle_id', 'context_type', 'context_id', 'attempt_number'],
                'puzzle_attempts_context_attempt_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropUnique('puzzle_attempts_context_attempt_number_unique');

            $table->unique(
                ['user_id', 'puzzle_id', 'attempt_number'],
                'puzzle_attempts_user_id_puzzle_id_attempt_number_unique'
            );
        });
    }
};