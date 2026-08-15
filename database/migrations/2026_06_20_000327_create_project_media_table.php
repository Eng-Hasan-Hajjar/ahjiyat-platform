<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلب الدكتور رقم 3 (جزء ثاني): صور وفيديوهات توثيقية
     * مرتبطة بالمشروع دائماً، واختيارياً بمرحلة (milestone) محددة
     * النوع before/after يسمح بعرض مقارنات بصرية واضحة لكل مرحلة
     */
    public function up(): void
    {
        Schema::create('project_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // اختياري: ربط الوسائط بمرحلة محددة من مراحل المشروع
            $table->foreignId('milestone_id')->nullable()
                  ->constrained('project_milestones')->nullOnDelete();

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->enum('media_type', ['image', 'video']);

            // قبل / أثناء / بعد — لتمكين عرض المقارنات
            $table->enum('phase', ['before', 'during', 'after'])->default('during');

            $table->string('file_path');
            $table->string('caption')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'phase']);
            $table->index(['milestone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_media');
    }
};