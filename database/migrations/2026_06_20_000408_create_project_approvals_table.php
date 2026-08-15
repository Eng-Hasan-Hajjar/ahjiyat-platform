<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلب الدكتور رقم 4: موافقة خطية رسمية من مالك المنشأة
     * يرفع المالك ملف PDF موقّعاً يدوياً (Scan)، ويراجعه المشرف لاعتماده
     * هذا يجعل بدء العمل على المشروع "رسمياً" وموثقاً قانونياً
     */
    public function up(): void
    {
        Schema::create('project_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // صاحب المشروع الذي رفع الموافقة
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();

            // مسار ملف PDF الموقّع يدوياً والممسوح ضوئياً
            $table->string('document_path');

            // رقم الهوية الوطنية لمالك المنشأة (للتوثيق فقط، يُعرض جزئياً)
            $table->string('owner_national_id')->nullable();

            $table->enum('status', ['pending_review', 'approved', 'rejected'])->default('pending_review');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_approvals');
    }
};