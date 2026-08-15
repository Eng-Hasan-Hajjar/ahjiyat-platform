<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلب الدكتور رقم 1: متابعة مالية شاملة (تبرعات + مصروفات)
     * جدول موحّد لكل الحركات المالية على المشروع مع نوعها (وارد/صادر)
     */
    public function up(): void
    {
        Schema::create('project_finances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // من أدخل الحركة (صاحب المشروع غالباً، أو المشرف)
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();

            // نوع الحركة: تبرع وارد أم مصروف صادر
            $table->enum('entry_type', ['donation', 'expense'])->index();

            // تصنيف الحركة لأغراض التقارير والتحليل
            // donation: cash / in_kind / volunteer_hours_value
            // expense: materials / labor / equipment / transport / other
            $table->string('category');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('SYP');

            $table->string('title');
            $table->text('description')->nullable();

            // اسم المتبرع (إن وُجد ورغب بالظهور) - فقط لحركات النوع donation
            $table->string('donor_name')->nullable();
            $table->boolean('donor_anonymous')->default(false);

            // مرفق إثبات الحركة (فاتورة، إيصال تبرع، صورة استلام مواد...)
            $table->string('attachment_path')->nullable();

            // تاريخ الحركة الفعلي (قد يختلف عن تاريخ الإدخال بالنظام)
            $table->date('entry_date');

            // حالة اعتماد الحركة من قبل المشرف (لضمان الشفافية والتدقيق)
            $table->enum('status', ['pending_review', 'verified', 'rejected'])->default('pending_review');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_finances');
    }
};