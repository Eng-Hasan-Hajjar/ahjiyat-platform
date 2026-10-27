<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E42 (2/3) - مراحل الموسم (فصول) وإعلاناته. بيانات تحريرية/جدولة فقط: ليست مهمة ولا مرحلة حملة ولا لوحة صدارة ولا محرك مكافآت.
 *
 *  season_phases
 *    فترة نصف مفتوحة [starts_at, ends_at). unique(season_id, internal_key). لا تداخل افتراضيًا ولا خروج عن نافذة الموسم: تفرضهما SeasonBuilderService
 *    داخل معاملة بقفل صف الموسم (يسلسل تعديلات المراحل المتزامنة). went_live_at = علامة معالجة بدء المرحلة (UPDATE شرطي ذري) فلا يتكرر إشعار البدء.
 *    cascade من الموسم: المرحلة ملك الموسم وحده (لا تاريخ مالي ولا نتائج). حذف موسم بدأ فعلًا يمنعه حارس النموذج (يُؤرشف بدلًا من ذلك).
 *  season_announcements
 *    نص عادي فقط (يُنقّى بـPlainText عند الحفظ ويُهرَّب عند العرض). published_at NULL = مسودة؛ مستقبلي = مجدول. notify_audience NULL = لا إشعار (قرار إداري صريح)؛
 *    notified_at علامة إرسال ذرية (مرة واحدة). المرحلة اختيارية؛ nullOnDelete: حذف مرحلة لا يحذف إعلانًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->char('public_id', 26)->unique('season_phases_public_unique');
            $table->string('internal_key', 64);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('theme_config')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('went_live_at')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'internal_key'], 'season_phases_key_unique');
            $table->index(['season_id', 'starts_at'], 'season_phases_season_start_idx');
        });

        Schema::create('season_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('season_phase_id')->nullable()->constrained('season_phases')->nullOnDelete();
            $table->char('public_id', 26)->unique('season_announcements_public_unique');
            $table->string('title', 160);
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('notify_audience', 16)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['season_id', 'published_at'], 'season_announcements_season_pub_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_announcements');
        Schema::dropIfExists('season_phases');
    }
};
