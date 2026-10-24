<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E41 (1/2) - امتداد digital_purchases لتنسيق الدفع الإلكتروني المستضاف (hosted checkout). ترحيل جديد؛ ترحيلات E40 لا تُعدَّل.
|
|  DigitalPurchase يبقى **سلطة** الأعمال. هذه الأعمدة بيانات تعريف مزوّد خارجي فقط (مراجع/حالة مطبَّعة/طوابع) ولا تحمل أي بيانات بطاقة ولا أسرارًا ولا حمولة webhook.
|  المراجع: provider_session_id (جلسة الدفع المستضافة) / provider_payment_reference (معرّف الدفعة عند التأكيد) / provider_refund_reference (معرّف الاسترداد). كلها قابلة للـNULL
|     (المزوّد اليدوي لا يملكها) وفريدة لكل مزوّد: (provider, ref) ⇒ لا يشير مرجعان محليان إلى الدفعة نفسها. NULL لا يتعارض في MySQL/SQLite.
|  provider_status حالة مطبَّعة صغيرة (pending|paid|failed|cancelled|refunded) تتقدّم صعودًا فقط (انظر CommerceProviderState): الحدث القديم لا يُرجعها.
|  review_flag **علم تشغيلي** لا حالة دفع (لا حالة جديدة تخترق آلة حالات E40): سبب يحتاج قرارًا ماليًا (عدم تطابق مبلغ/عملة، دفعة متأخرة…). يُحلّ بقرار صريح (review_resolution).
|  refund_*: وسم طلب الاسترداد عند المزوّد (يُحفظ قبل النداء الخارجي لمنع التكرار) — الشراء لا يصير refunded قبل تأكيد المزوّد.
|  الحذف: مفاتيح المستخدمين الجديدة nullOnDelete (التاريخ المالي يبقى؛ الصف نفسه لا يُحذف بحارس النموذج).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_purchases', function (Blueprint $table) {
            $table->string('provider_session_id', 128)->nullable();
            $table->string('provider_payment_reference', 128)->nullable();
            $table->string('provider_refund_reference', 128)->nullable();
            $table->string('provider_status', 16)->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->unsignedTinyInteger('checkout_generation')->default(0);
            $table->timestamp('checkout_created_at')->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->timestamp('provider_paid_at')->nullable();
            $table->timestamp('provider_last_synced_at')->nullable();

            $table->timestamp('refund_requested_at')->nullable();
            $table->foreignId('refund_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_requested_reason', 32)->nullable();
            $table->string('refund_requested_note', 300)->nullable();
            $table->boolean('refund_claims_confirmed')->default(false);
            $table->unsignedSmallInteger('refund_attempts')->default(0);
            $table->string('refund_error', 48)->nullable();

            $table->string('review_flag', 32)->nullable();
            $table->timestamp('review_flagged_at')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->foreignId('review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_resolution', 24)->nullable();

            $table->unique(['provider', 'provider_session_id'], 'digital_purchases_provider_session_unique');
            $table->unique(['provider', 'provider_payment_reference'], 'digital_purchases_provider_payment_unique');
            $table->unique(['provider', 'provider_refund_reference'], 'digital_purchases_provider_refund_unique');
            $table->index(['review_flag', 'review_resolved_at'], 'digital_purchases_review_index');
            $table->index(['provider', 'status', 'created_at'], 'digital_purchases_provider_status_index');
            $table->index(['status', 'expires_at'], 'digital_purchases_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::table('digital_purchases', function (Blueprint $table) {
            $table->dropUnique('digital_purchases_provider_session_unique');
            $table->dropUnique('digital_purchases_provider_payment_unique');
            $table->dropUnique('digital_purchases_provider_refund_unique');
            $table->dropIndex('digital_purchases_review_index');
            $table->dropIndex('digital_purchases_provider_status_index');
            $table->dropIndex('digital_purchases_status_expires_index');
            $table->dropForeign(['refund_requested_by']);
            $table->dropForeign(['review_resolved_by']);
            $table->dropColumn([
                'provider_session_id', 'provider_payment_reference', 'provider_refund_reference', 'provider_status', 'checkout_url', 'checkout_generation',
                'checkout_created_at', 'checkout_expires_at', 'provider_paid_at', 'provider_last_synced_at',
                'refund_requested_at', 'refund_requested_by', 'refund_requested_reason', 'refund_requested_note', 'refund_claims_confirmed', 'refund_attempts', 'refund_error',
                'review_flag', 'review_flagged_at', 'review_resolved_at', 'review_resolved_by', 'review_resolution',
            ]);
        });
    }
};
