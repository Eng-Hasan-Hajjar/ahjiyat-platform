<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E41 (2/2) - سجل أحداث المزوّد (commerce_provider_events): **إضافة فقط**، حاجز idempotency الأول لـwebhook، وأداة تدقيق وتشخيص وحماية من إعادة التشغيل.
|
|  unique(provider, provider_event_id): حدث المزوّد نفسه لا يُطبَّق مرتين مهما تكرّر أو تزامن (السباق يحسمه القيد لا فحص التطبيق).
|  **لا حمولة خام**: لا نخزّن جسم webhook ولا ترويساته ولا أي بيانات دفع حساسة. للتشخيص فقط بصمة SHA-256 (payload_sha256) من الجسم الخام — لا يمكن استرجاع الحمولة منها.
|  digital_purchase_id nullable (حدث لشراء غير معروف يُسجَّل كي تراه المالية) وبـRESTRICT: الشراء لا يُحذف أصلًا فلا يضيع التاريخ.
|  processing_result: applied | ignored | needs_review | rejected  (duplicate لا يُدرَج: القيد الفريد يمنعه ويُرجَع duplicate للمتصل).
|  الصف يُدرَج **أخيرًا** في معاملة الأثر نفسها: فشل الأثر ⇒ تراجع كامل ⇒ المزوّد يعيد الإرسال؛ لا حدث «مُستهلَك» بلا أثر.
|  فهارس: البحث بالمزوّد+المرجع، وبالشراء، وبالمزوّد+الزمن (الصحة والترقيم)، وبالنتيجة+الزمن (الأحداث غير المطابقة).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_event_id', 128);
            $table->string('event_type', 64);
            $table->string('normalized_state', 16);
            $table->string('provider_reference', 128)->nullable();
            $table->foreignId('digital_purchase_id')->nullable()->constrained('digital_purchases')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('processing_result', 16);
            $table->string('result_detail', 48)->nullable();
            $table->char('payload_sha256', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider', 'provider_event_id'], 'commerce_provider_events_event_unique');
            $table->index(['provider', 'provider_reference'], 'commerce_provider_events_reference_index');
            $table->index(['digital_purchase_id', 'received_at'], 'commerce_provider_events_purchase_index');
            $table->index(['provider', 'received_at'], 'commerce_provider_events_provider_time_index');
            $table->index(['processing_result', 'received_at'], 'commerce_provider_events_result_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_provider_events');
    }
};
