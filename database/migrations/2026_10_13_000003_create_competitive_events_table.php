<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E17-B: حدث تنافسي محدود زمنيًا على أحجية واحدة. status يدوي مغلق: draft|published|completed|cancelled فقط؛ "قادم/مباشر/منتهٍ" مشتقة
 * من الوقت (لا حالات يدوية متعارضة). participants_count عدّاد ذري يحمي السعة (UPDATE شرطي بجملة واحدة). الأحجية restrictOnDelete: لا يُحذف
 * هدف حدث يحتفظ بتاريخ منافسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('puzzle_id')->constrained('puzzles')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('registration_starts_at')->nullable();
            $table->timestamp('registration_ends_at')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('participants_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index('ends_at');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitive_events');
    }
};
