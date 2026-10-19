<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - إحالة واحدة لكل حساب مُحال (المصدر الوحيد للحقيقة).
 *
 *  - referred_user_id UNIQUE ⇒ حساب جديد له محيل واحد كحدّ أقصى، ومحيل لا يتغيّر (لا مسار تحديث له في الخدمات). حارس القاعدة لا التطبيق وحده.
 *  - حالات: pending | pending_review | qualified | rewarded | disqualified (مفتاح نصي مغلق في النموذج).
 *  - لقطة المكافأة (snap_*, reward_reserved_at, *_granted_at) تُنسخ عند التأهيل: تعديل الحملة بعدها لا يغيّر تاريخ هذه الإحالة.
 *  - مفتاح فرادة المكافأة في دفتر المحفظة (currency_transactions.idempotency_key): referral-qualified:{id}:referrer|referred ⇒ لا ائتمان مزدوج حتى لو تكرر التسليم.
 *  - risk_signals: مفاتيح إشارات مغلقة فقط (لا IP ولا UA ولا بصمة خام).
 *
 * سياسة الحذف المقصودة:
 *  - المُحال محذوف  ⇒ CASCADE (الإحالة بياناته الشخصية؛ قيود المحفظة تبقى بإجراءاتها الخاصة).
 *  - المحيل محذوف   ⇒ SET NULL (يبقى تاريخ المُحال وأرقام التحليلات؛ لا مكافآت جديدة تُسلَّم لمحيل مجهول).
 *  - الرمز/الحملة/المراجِع محذوفة ⇒ SET NULL (الحملة تُنهى عادةً ولا تُحذف؛ اللقطة تحفظ الشروط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referral_code_id')->nullable()->constrained('referral_codes')->nullOnDelete();
            $table->foreignId('referral_campaign_id')->nullable()->constrained('referral_campaigns')->nullOnDelete();

            $table->string('source', 24)->default('link'); // link | share_param
            $table->string('status', 16)->default('pending');
            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // لقطة وقت الإسناد (NULL = قيمة config)

            $table->timestamp('action_at')->nullable();       // أول إجراء رسمي ذي معنى (قد يسبق التأهيل بانتظار عمر الحساب)
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->json('risk_signals')->nullable();

            // لقطة المكافأة عند التأهيل + تسليم كل جانب باستقلال.
            $table->string('reward_status', 16)->default('none'); // none | pending | granted | skipped
            $table->string('reward_skip_reason', 32)->nullable();
            $table->unsignedInteger('snap_referrer_gems')->default(0);
            $table->unsignedInteger('snap_referred_gems')->default(0);
            $table->unsignedBigInteger('snap_campaign_id')->nullable(); // معرّف الحملة وقت التأهيل (بلا FK: لقطة تاريخية)
            $table->timestamp('reward_reserved_at')->nullable();        // يُحتسب ضمن سقف المحيل
            $table->timestamp('referrer_granted_at')->nullable();
            $table->timestamp('referred_granted_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->string('disqualified_reason', 32)->nullable(); // قائمة مغلقة
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->unique('referred_user_id', 'user_referrals_referred_user_unique');
            $table->index(['referrer_user_id', 'status'], 'user_referrals_referrer_status_idx');
            $table->index(['referrer_user_id', 'reward_reserved_at'], 'user_referrals_referrer_reserved_idx');
            $table->index(['referral_campaign_id', 'status'], 'user_referrals_campaign_status_idx');
            $table->index(['status', 'action_at'], 'user_referrals_status_action_idx');
            $table->index(['status', 'created_at'], 'user_referrals_status_created_idx');
            $table->index('referral_code_id', 'user_referrals_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
    }
};
