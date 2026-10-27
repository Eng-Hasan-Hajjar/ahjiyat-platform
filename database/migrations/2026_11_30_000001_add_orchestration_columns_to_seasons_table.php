<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E42 (1/3) - الموسم منسّقًا: نافذة زمنية صريحة اختيارية + ظهور مجدول + أرشفة + ربط اختياري بمسار الأحجيات.
 *
 *  starts_at / ends_at: **اختياريتان**. NULL = «يتبع الحملة الأساسية» (campaigns.starts_at/ends_at)، فكل موسم قائم (ومنه أصيل) يبقى بسلوكه الحرفي
 *    بلا أي تعبئة رجعية ولا نسخ تواريخ: التعبئة الرجعية الحتمية هنا هي «لا تغيير» عمدًا (تحقّقه اختبارات التوافق). عند ضبط تاريخ صريح يصير هو السلطة لذلك الطرف.
 *    الحالة (draft/scheduled/live/ended/archived) **تُشتقّ** من التواريخ وعلم النشر في SeasonLifecycleService، لا عمود حالة يدوي.
 *  announcement_at: لحظة ظهور الموسم المنشور للجمهور كـ«قريبًا» (NULL = فور النشر).
 *  archive_at: لحظة الأرشفة (NULL = لم يُؤرشف). الأرشفة اليدوية تُجمّد النافذة الفعلية في التاريخ فلا تتغيّر بتعديل الحملة لاحقًا.
 *  ahjiyat_pass_id: مسار أحجيات «مميّز» اختياري. الربط **من جهة الموسم** عمدًا (جدول المسارات لا يعرف الموسم: E40 يختبر ذلك) ودورتا الحياة مستقلتان تمامًا.
 *    nullOnDelete: المسار المنشور لا يُحذف أصلًا (حارس نموذج E40)؛ حذف مسودة مربوطة يفكّ الربط فقط.
 *
 * seasons.campaign_id (الحملة الأساسية) يبقى كما هو: unique + cascade (لا تعديل على الترحيلات القديمة). فهرس (is_published, starts_at, ends_at) لاستعلامات الفهرس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('went_live_at');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->timestamp('announcement_at')->nullable()->after('ends_at');
            $table->timestamp('archive_at')->nullable()->after('announcement_at');
            $table->foreignId('ahjiyat_pass_id')->nullable()->after('archive_at')->constrained('ahjiyat_passes')->nullOnDelete();

            $table->index(['is_published', 'starts_at', 'ends_at'], 'seasons_publication_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropIndex('seasons_publication_window_idx');
            $table->dropForeign(['ahjiyat_pass_id']);
            $table->dropColumn(['ahjiyat_pass_id', 'starts_at', 'ends_at', 'announcement_at', 'archive_at']);
        });
    }
};
