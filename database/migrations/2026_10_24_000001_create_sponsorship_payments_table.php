<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E32 - طلبات الدفع/الدعم (checkout). كيان مستقل عن SponsorshipContribution: يتتبّع مسار الدفع فقط؛ المساهمة تبقى سجل الدعم المالي المؤكَّد الوحيد.
 *
 * حدود السلامة المالية في قاعدة البيانات (النهائية؛ المنطق يحاول أولًا لكن هذه هي الحدّ الأخير):
 *  - sponsorship_contribution_id UNIQUE      ⇒ طلب واحد = مساهمة مجسَّدة واحدة كحدّ أقصى (تحقق مزدوج/إعادة محاولة لا تُنتج ثانية).
 *  - (provider, provider_reference) UNIQUE   ⇒ مرجع المزوّد الخارجي فريد داخل المزوّد (استجابة/استدعاء مكرّر لا يُنشئ طلبًا ثانيًا). NULL متعدد مسموح (اليدوي بلا مرجع مزوّد).
 *  - reference UNIQUE                        ⇒ رمز التحويل البشري (SUP-XXXXXXXX) فريد.
 *  - (user_id, request_key) UNIQUE           ⇒ نقر مزدوج على «إنشاء الطلب» ينتج طلبًا واحدًا.
 *
 * لا حذف تتابعي للتاريخ المالي: كل مفاتيح البرنامج/الداعم/المساهمة/المستخدم RESTRICT (حذف المستخدم محجوب أيضًا بـFinancialHistoryGuard).
 * المبالغ أعداد صحيحة بالوحدة الصغرى؛ لا بريد ضيف ولا بيانات بطاقة ولا أسرار مزوّد ولا أجسام webhook خام في أي عمود (الضيف مؤجَّل P1: يحتاج عمودًا مشفّرًا).
 * أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('reference', 24)->unique();

            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supporter_profile_id')->nullable()->constrained('supporter_profiles')->restrictOnDelete();
            $table->foreignId('sponsorship_contribution_id')->nullable()->constrained('sponsorship_contributions')->restrictOnDelete();

            $table->string('provider', 32);
            $table->string('provider_reference', 120)->nullable();
            $table->string('request_key', 64)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('accounting_amount_minor')->nullable(); // يُسجَّل عند التحقق
            $table->char('accounting_currency', 3)->nullable();

            $table->string('status', 24)->default('awaiting_payment');
            $table->boolean('is_anonymous')->default(false);
            $table->string('display_name', 80)->nullable();   // الاسم الذي اختاره الداعم للعرض العلني إن لم يكن مجهولًا (نص عادي)

            $table->string('payer_note', 255)->nullable();    // ملاحظة المحوِّل (اسم المحوِّل/تاريخ التحويل): يراها المالك والمراجع فقط
            $table->string('user_message', 255)->nullable();  // رسالة مرئية لصاحب الطلب (سبب رفض/طلب توضيح)
            $table->string('review_note', 500)->nullable();   // ملاحظة مراجع داخلية: لا تُعرض لصاحب الطلب ولا للعامة أبدًا
            $table->string('failure_code', 32)->nullable();   // رمز سبب الرفض من قائمة بيضاء

            // إثبات التحويل (خاص): المسار على قرص غير علني، اسم عشوائي يولّده الخادم، وبصمة لاكتشاف إعادة استخدام نفس الإثبات بين طلبات.
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_mime', 40)->nullable();
            $table->unsignedInteger('proof_size')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->timestamp('proof_purged_at')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('clarification_requested_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // حدود السلامة المالية (انظر أعلى الملف).
            $table->unique('sponsorship_contribution_id', 'sponsorship_payments_contribution_unique');
            $table->unique(['provider', 'provider_reference'], 'sponsorship_payments_provider_ref_unique');
            $table->unique(['user_id', 'request_key'], 'sponsorship_payments_request_key_unique');

            // طابور المراجعة (الحالة + الأقدم أولًا) / مجاميع البرنامج / سجل المستخدم / الانتهاء المجدول / كشف تكرار الإثبات / FK الداعم.
            $table->index(['status', 'created_at'], 'sponsorship_payments_status_created_index');
            $table->index(['sponsorship_program_id', 'status'], 'sponsorship_payments_program_status_index');
            $table->index(['user_id', 'created_at'], 'sponsorship_payments_user_created_index');
            $table->index(['status', 'expires_at'], 'sponsorship_payments_expiry_index');
            $table->index('proof_sha256', 'sponsorship_payments_proof_hash_index');
            $table->index('supporter_profile_id', 'sponsorship_payments_supporter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_payments');
    }
};
