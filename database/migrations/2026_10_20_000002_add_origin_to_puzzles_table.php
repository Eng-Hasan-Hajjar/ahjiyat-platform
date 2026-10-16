<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E28 - مصدر الأحجية (official | community) كحقيقة سيرفرية على الصف نفسه، لا من الرابط ولا من علاقة جانبية.
 * الصفوف الحالية رسمية بالافتراضي (لا تعبئة لاحقة). أحجيات المجتمع المنشورة صفوف مشتقَّة بالمحرك نفسه بـorigin=community وis_active=false دائمًا،
 * فكل مسار رسمي يفلتر is_active (القوائم، البحث، الرائج، التوصيات، اليومية، API) يستبعدها تلقائيًا (رفض افتراضي).
 * بلا فهرس: الفلترة بـorigin مقتصرة على لوحة الإدارة (جدول صغير) وفحوص نقطة واحدة بالمفتاح.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('puzzles', 'origin')) {
            Schema::table('puzzles', function (Blueprint $table) {
                $table->string('origin', 16)->default('official');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('puzzles', 'origin')) {
            Schema::table('puzzles', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
