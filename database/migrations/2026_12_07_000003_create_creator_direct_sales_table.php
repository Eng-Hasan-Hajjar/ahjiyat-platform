<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E43 (3/3) - بيع مباشر لمبدع (creator_direct_sales): اللقطة المحاسبية **الثابتة** التي تربط شراء E41 بالمبدع والسلسلة والمبلغ الإجمالي وحصتي المبدع والمنصة وقيد دفتر E39. صف واحد لكل شراء.
|
|  المال أعداد صحيحة بالوحدة الصغرى (لا float/decimal) وبنقاط أساس: creator_bps + platform_bps = 10000، و creator_share = floor(gross * creator_bps / 10000)، و platform_share = gross - creator_share،
|     فمجموع الحصتين يساوي الإجمالي دائمًا. لقطة السياسة (النقاط وقاعدة الباقي) تُحفظ وقت التحقق فلا يغيّرها تعديل الإعداد لاحقًا.
|  عمود المنصة للتحليل والتدقيق فقط: لا محفظة سحب للمنصة.
|  الأعمدة التجارية ثابتة؛ المتغير الوحيد حالة الاسترداد/المراجعة (status وreversal_entry_id وrefunded_at وأعمدة review_*): credited ← refunded | needs_finance_review ← refunded | written_off.
|  credit_entry_id (فريد) يربط قيد دفتر E39 الدائن (direct_sale_credit، source_key فريد في الدفتر)؛ reversal_entry_id (فريد، يقبل NULL) قيد العكس الإلحاقي (direct_sale_reversal). القيد الأصلي لا يُحذف ولا يُعدَّل أبدًا.
|  لا بيانات مشترٍ هنا (لا بريد ولا هاتف ولا إثبات دفع ولا بيانات مزوّد): الربط بالمشتري عبر digital_purchase_id لمن يملك صلاحية المالية فقط.
|  الحذف: كل المفاتيح الأجنبية RESTRICT (التاريخ المالي يبقى)، والمراجِع (review_resolved_by) فقط SET NULL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_direct_sales', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_direct_sales_public_unique');
            $table->foreignId('digital_purchase_id')->constrained('digital_purchases')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // مستخدم المبدع (البائع) — اسم العمود user_id كبقية سجلات التاريخ المالي المحروسة
            $table->foreignId('creator_series_id')->constrained('creator_series')->restrictOnDelete();
            $table->foreignId('creator_series_sale_offer_id')->constrained('creator_series_sale_offers')->restrictOnDelete();
            $table->foreignId('creator_earning_account_id')->constrained('creator_earning_accounts')->restrictOnDelete();

            // لقطات الهوية وقت التحقق.
            $table->char('series_public_id', 26);
            $table->string('series_title', 160);
            $table->string('creator_display_name', 120);

            // المال (وحدة صغرى) ولقطة السياسة.
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('creator_share_minor');
            $table->unsignedBigInteger('platform_share_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('creator_bps');
            $table->unsignedSmallInteger('platform_bps');
            $table->string('rounding_rule', 24)->default('creator_floor');

            $table->string('status', 24)->default('credited');
            $table->foreignId('credit_entry_id')->constrained('creator_earning_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('creator_earning_entries')->restrictOnDelete();

            $table->timestamp('sold_at');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('review_flagged_at')->nullable();
            $table->string('review_reason', 32)->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->foreignId('review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_resolution', 24)->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();

            $table->unique('digital_purchase_id', 'creator_direct_sales_purchase_unique');
            $table->unique('credit_entry_id', 'creator_direct_sales_credit_unique');
            $table->unique('reversal_entry_id', 'creator_direct_sales_reversal_unique');
            $table->index(['creator_profile_id', 'sold_at'], 'creator_direct_sales_creator_sold_index');
            $table->index(['creator_series_id', 'sold_at'], 'creator_direct_sales_series_sold_index');
            $table->index(['status', 'sold_at'], 'creator_direct_sales_status_sold_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_direct_sales');
    }
};
