<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();

            // سياق عام (نفس نمط GemTransaction.reference_type/reference_id الموجود
            // فعلياً بالمشروع) - null دائماً بالوضع المستقل، وجاهز لاحقاً لخطوة حملة
            // أو تحدٍّ راعٍ بدون أي تعديل على هذا الجدول.
            $table->string('context_type')->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            $table->string('status')->default('active'); // active | completed | expired | abandoned

            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // الحالة الحية المؤقتة فقط (مثلاً: مؤشرات الفروق المكتشفة حتى الآن) -
            // لا يوجد فيها أي حل سرّي - الحل الحقيقي يبقى بـ puzzles.solution_data دائماً.
            $table->json('server_state')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'puzzle_id', 'status']);
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};