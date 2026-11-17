<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E46: سجل نتائج تدقيق «سلامة الجواهر» (additive فقط: جدولان جديدان، لا تعديل على wallets ولا currency_transactions).
 *
 * - gem_integrity_runs: ملخّص كل فحص (عدّادات + نطاق + حالة). هو مصدر «آخر نتيجة فحص» في بطاقة مركز العمليات؛ لا يُشغَّل فحص عند زيارة اللوحة.
 * - gem_integrity_findings: الحالات غير المطابقة فقط (فرق مثبت / لا يمكن الجزم) بأرقام ومعرّفات داخلية؛ لا أسماء ولا بريد.
 *
 * عمدًا بلا مفتاح أجنبي على users/wallets (سجل تدقيق تاريخي يجب ألا يتأثر بحذف/تجميد مستخدم، ولا يدخل في حارس التاريخ المالي FinancialHistoryGuard).
 * المفتاح الأجنبي الوحيد من findings إلى runs (داخلي لهذه الوحدة) لتنظيف النتائج مع حذف الفحوص القديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_integrity_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('running'); // running | completed | failed
            $table->string('scope_kind', 24);                 // all_currencies | currencies | user
            $table->json('scope_currency_ids')->nullable();
            $table->unsignedBigInteger('scope_user_id')->nullable();
            $table->unsignedInteger('chunk');
            $table->unsignedInteger('grace_seconds');
            $table->unsignedInteger('scanned')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('proven_differences')->default(0);
            $table->unsignedInteger('undetermined')->default(0);
            $table->json('reason_counts')->nullable();
            $table->json('by_currency')->nullable();
            $table->unsignedInteger('findings_stored')->default(0);
            $table->boolean('findings_truncated')->default(false);
            $table->unsignedBigInteger('triggered_by')->nullable(); // معرّف المشغّل؛ null = سطر الأوامر
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });

        Schema::create('gem_integrity_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('gem_integrity_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('verdict', 24);                    // proven_difference | undetermined
            $table->bigInteger('stored_available');
            $table->bigInteger('derived_available');
            $table->bigInteger('stored_pending');
            $table->bigInteger('derived_pending');
            $table->unsignedInteger('ledger_rows')->default(0);
            $table->json('reasons')->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['run_id', 'verdict', 'id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_integrity_findings');
        Schema::dropIfExists('gem_integrity_runs');
    }
};
