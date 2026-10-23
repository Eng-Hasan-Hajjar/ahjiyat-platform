<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E39 (2/3) - حساب أرباح المبدع (creator_earning_accounts) + طلبات الدفع (creator_payouts).
|
|  creator_earning_accounts
|     لقطة أرصدة **مشتقة** من دفتر الأرباح (available / reserved / paid_lifetime) بعملة المحاسبة؛ الحقيقة هي الدفتر وتُعيد creator-finance:reconcile مقارنتها.
|     وجود الصف = المبدع مُفعَّل للتسييل من الإدارة (بوابة منفصلة تمامًا عن حالة ملف المبدع CreatorProfile.status). monetization_status: enabled|paused.
|     التعليق (hold) أعمدة على الحساب: يمنع طلب/اعتماد/صرف الدفعات فقط ولا يمنع الاستحقاق. hold_case_id اختياري لربط حالة إشراف E38 (nullOnDelete) بلا نسخ أي تفاصيلها.
|  creator_payouts
|     طلب دفعة يدوية. open_account_id = معرّف الحساب ما دام الطلب مفتوحًا (requested|under_review|approved) وNULL بعدها:
|     unique(open_account_id) يجعل «طلب مفتوح واحد لكل حساب» قيدًا بقاعدة البيانات تحت السباق. external_reference فريد عالميًا (مرجع صرف واحد لدفعة واحدة).
|     لا وجهة تحويل ولا بيانات بنكية ولا اعتماد مزوّد في أي عمود: وسيلة الدفع وسم من إعداد مغلق فقط.
|     كل المفاتيح الأجنبية للأطراف المالية RESTRICT؛ مراجع المشغّلين nullOnDelete (يبقى السجل بلا هوية).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_earning_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_earning_accounts_public_unique');
            $table->foreignId('creator_profile_id')->unique('creator_earning_accounts_profile_unique')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->unique('creator_earning_accounts_user_unique')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);

            $table->unsignedBigInteger('available_minor')->default(0);
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->unsignedBigInteger('paid_lifetime_minor')->default(0);

            $table->string('monetization_status', 16)->default('enabled');
            $table->timestamp('monetization_enabled_at')->nullable();
            $table->timestamp('monetization_changed_at')->nullable();
            $table->foreignId('monetization_changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('hold_reason_code', 32)->nullable();
            $table->text('hold_note')->nullable();
            $table->timestamp('hold_placed_at')->nullable();
            $table->foreignId('hold_placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hold_case_id')->nullable()->constrained('moderation_cases')->nullOnDelete();

            $table->timestamps();

            $table->index('monetization_status', 'creator_earning_accounts_status_index');
        });

        Schema::create('creator_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_payouts_public_unique');
            $table->foreignId('creator_earning_account_id')->constrained('creator_earning_accounts')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method_code', 32);
            $table->string('provider', 32)->default('manual');
            $table->string('status', 16)->default('requested');
            $table->unsignedBigInteger('open_account_id')->nullable()->unique('creator_payouts_open_account_unique');

            $table->timestamp('requested_at');
            $table->foreignId('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('review_note')->nullable();
            $table->string('decision_message', 300)->nullable();
            $table->string('external_reference', 120)->nullable()->unique('creator_payouts_reference_unique');

            $table->timestamps();

            $table->index(['status', 'requested_at'], 'creator_payouts_status_requested_index');
            $table->index(['creator_earning_account_id', 'status'], 'creator_payouts_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_payouts');
        Schema::dropIfExists('creator_earning_accounts');
    }
};
