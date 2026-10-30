<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E44 (3/3) - نقاط الدعم لمشتريات المتجر.
|
|  store_item_prices.support_points      نقاط الدعم التي يمنحها مستوى السعر/الباقة هذا عند **اكتمال** شراء المتجر. الافتراضي 0 لكل صف قديم ⇒ لا مشتريات قديمة تكسب نقاطًا فجأة.
|  store_purchases.support_points_snapshot لقطة النقاط وقت إنشاء الشراء (تغيير الإعداد لاحقًا لا يعيد كتابة الترتيب التاريخي)؛ 0 للقديم.
|
| لا فهارس جديدة (لا استعلام يفلتر بهذين العمودين). المتجر يبقى بالعملة الداخلية؛ هذه النقاط ليست سعرًا ولا عملة ولا تُشترى بها.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_item_prices', function (Blueprint $table) {
            $table->unsignedInteger('support_points')->default(0)->after('amount');
        });

        Schema::table('store_purchases', function (Blueprint $table) {
            $table->unsignedInteger('support_points_snapshot')->default(0)->after('price_amount');
        });
    }

    public function down(): void
    {
        Schema::table('store_purchases', function (Blueprint $table) {
            $table->dropColumn('support_points_snapshot');
        });

        Schema::table('store_item_prices', function (Blueprint $table) {
            $table->dropColumn('support_points');
        });
    }
};
