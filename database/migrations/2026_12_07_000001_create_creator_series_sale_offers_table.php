<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E43 (1/3) - عرض بيع سلسلة مبدع (creator_series_sale_offers): الحالة التجارية لسلسلة مميزة بدورة حياة منفصلة عن محتواها (لا أعمدة تجارية في نسخ السلسلة).
|
|  status مجموعة مغلقة: draft | active | paused | retired. السعر عدد صحيح بالوحدة الصغرى وعملة واحدة (يجب أن تطابق عملة حساب أرباح المبدع: لا تحويل عملات).
|  live_series_id (فريد، يقبل NULL) = creator_series_id ما دام العرض غير مُسحَب (draft|active|paused) وNULL بعد retired: يجعل «عرض حيّ واحد لكل سلسلة» قيدًا بقاعدة البيانات تحت السباق،
|     والعروض المسحوبة تبقى تاريخًا (لا تُحذف) ويُنشأ بعدها عرض جديد إن أراد المبدع.
|  first_activated_at يُكتب مرة واحدة عند أول تفعيل ولا يتغير: هو حدّ «التجميد المتقادم» (من لعب العمل قبله يحتفظ بحقّه في العمل نفسه؛ لا صفوف وصول جماعية).
|  تغيير السعر يسري على المشتريات **المستقبلية** فقط (لقطة DigitalPurchase ثابتة).
|  الحذف: كل المفاتيح الأجنبية RESTRICT (التاريخ التجاري يبقى مهما تغيّر المحتوى)، والعارض الموقِف (paused_by) فقط SET NULL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_series_sale_offers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_series_sale_offers_public_unique');
            $table->foreignId('creator_series_id')->constrained('creator_series')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();

            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('live_series_id')->nullable()->unique('creator_series_sale_offers_live_unique');

            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);

            $table->timestamp('first_activated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pause_reason', 24)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('price_changed_at')->nullable();
            $table->timestamps();

            $table->index(['creator_series_id', 'status'], 'creator_series_sale_offers_series_status_index');
            $table->index(['creator_profile_id', 'status'], 'creator_series_sale_offers_creator_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_series_sale_offers');
    }
};
