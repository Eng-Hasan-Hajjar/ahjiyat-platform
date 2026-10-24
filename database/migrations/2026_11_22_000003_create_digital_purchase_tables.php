<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E40 (3/4) - أساس الشراء الرقمي الاستهلاكي (digital_purchases) وسجل أحداثه الثابت (digital_purchase_events).
|
|  لماذا جدول جديد؟ SponsorshipPayment وارد الرعاية/الدعم (E30/E32) ويُنشئ مساهمة؛ StorePurchase يخصم من محفظة العملات الداخلية. شراء المسار المميز نقد حقيقي لسلعة رقمية: كيان ثالث لا يلوّث دلالات أي منهما.
|  عام عمدًا لكن بلا هندسة زائدة: product_type + product_id (لا FK متعدد الأشكال) + لقطات (product_public_id/product_name/amount_minor/currency) تبقى سليمة مهما تغيّر المنتج لاحقًا.
|  المال: amount_minor عدد صحيح بالوحدة الصغرى، عملة واحدة، بلا تحويل عملات. **لا** رقم بطاقة ولا CVV ولا PIN ولا OTP ولا كلمة مرور بنكية في أي عمود: transfer_reference/payer_note نص حر قصير يُنظَّف ويُهرَّب فقط.
|  open_key (فريد، يقبل NULL) = «product_type:product_id:user_id» ما دام الطلب مفتوحًا أو محقَّقًا (awaiting_payment|submitted|verified) وNULL بعدها (rejected|cancelled|expired|refunded):
|     يجعل «طلب شراء مفتوح واحد وشراء محقَّق واحد لكل مستخدم/منتج» قيدًا بقاعدة البيانات تحت السباق. unique(user_id, request_key) لفكّ النقر المزدوج.
|  الحذف: user_id بـRESTRICT (مثل store_purchases): مستخدم له مشتريات لا يُحذف، والبديل المقصود هو التجميد. الصفوف لا تُحذف أبدًا (حارس النموذج). الأحداث ثابتة (append-only).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_purchases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('digital_purchases_public_unique');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('product_type', 32);
            $table->unsignedBigInteger('product_id');
            $table->char('product_public_id', 26);
            $table->string('product_name', 160);

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider', 32)->default('manual');
            $table->string('status', 16)->default('awaiting_payment');
            $table->string('open_key', 80)->nullable()->unique('digital_purchases_open_key_unique');
            $table->string('request_key', 64)->nullable();
            $table->string('reference_code', 32)->unique('digital_purchases_reference_unique');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('payer_note', 255)->nullable();
            $table->string('transfer_reference', 120)->nullable();

            $table->string('proof_path', 255)->nullable();
            $table->string('proof_mime', 64)->nullable();
            $table->unsignedInteger('proof_size')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reason', 32)->nullable();
            $table->unsignedSmallInteger('refund_kept_items')->nullable();

            $table->string('decision_message', 300)->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'request_key'], 'digital_purchases_user_request_unique');
            $table->index(['user_id', 'status'], 'digital_purchases_user_status_index');
            $table->index(['status', 'created_at'], 'digital_purchases_status_created_index');
            $table->index(['product_type', 'product_id', 'status'], 'digital_purchases_product_status_index');
        });

        Schema::create('digital_purchase_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_purchase_id')->constrained('digital_purchases')->restrictOnDelete();
            $table->string('event', 24);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at');

            $table->index(['digital_purchase_id', 'created_at'], 'digital_purchase_events_purchase_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_purchase_events');
        Schema::dropIfExists('digital_purchases');
    }
};
