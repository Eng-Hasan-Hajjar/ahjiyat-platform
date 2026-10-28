<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E43 (2/3) - وصول مدفوع إلى سلسلة مبدع (creator_series_accesses): **السلطة** على ملكية السلسلة المميزة. تُنشأ حصرًا من التحقق المالي لشراء E41 (معالج المنتج)؛ لا يوجد مسار لإنشائها من العميل.
|
|  unique(user_id, creator_series_id): صف واحد لكل (مشتري، سلسلة) مهما تكرر التحقق (idempotent). الاسترداد يضع revoked_at (لا حذف)، وإعادة شراء بعد استرداد تعيد تفعيل الصف نفسه بمصدر الشراء الجديد.
|  unique(source_digital_purchase_id): شراء واحد يمنح وصولًا واحدًا فقط. وصول بلا شراء محقَّق = حالة تُكتشف بأمر المصالحة.
|  معاينة المبدع نفسه أو المشرف **لا** تُنشئ صفًا هنا (ولا شراء): تُحسب من سياسة الوصول مباشرة.
|  الحذف: كل المفاتيح الأجنبية RESTRICT؛ المستخدم صاحب وصول لا يُحذف (FinancialHistoryGuard) والبديل المقصود هو التجميد.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_series_accesses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_series_accesses_public_unique');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('creator_series_id')->constrained('creator_series')->restrictOnDelete();
            $table->foreignId('source_digital_purchase_id')->constrained('digital_purchases')->restrictOnDelete();

            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 24)->nullable();
            $table->unsignedSmallInteger('grant_count')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'creator_series_id'], 'creator_series_accesses_owner_unique');
            $table->unique('source_digital_purchase_id', 'creator_series_accesses_purchase_unique');
            $table->index(['creator_series_id', 'revoked_at'], 'creator_series_accesses_series_revoked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_series_accesses');
    }
};
