<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E42 (3/3) - روابط محتوى الموسم: حملات إضافية، منافسات، مجموعات مهام. **جدول واحد بمفاتيح أجنبية حقيقية** (لا morph بلا سلامة مرجعية):
 * لكل نوع عمود FK خاص به، والنوع (content_type) يحدّد أيّها غير فارغ (يفرضه SeasonContentItem + SeasonBuilderService؛ قاعدة البيانات تضمن وجود الكيان المرتبط).
 *
 *  - الحملة الأساسية تبقى seasons.campaign_id؛ هذا الجدول للحملات **الإضافية** فقط.
 *  - لا نسخ لتواريخ ولا حالات ولا نتائج: النافذة والحالة والترتيب والنتائج تُقرأ من الكيان نفسه (الحملة/المنافسة/المجموعة)، فلا تعارض ولا تقرير مزدوج.
 *  - فرادة (الموسم، الكيان) لكل نوع بقيد قاعدة بيانات (NULL لا يتعارض): ربط الكيان نفسه مرتين يُرفض حتى مع التزامن.
 *  - سياسة الحذف: الموسم cascade (الروابط ملكه)؛ حذف الكيان المرتبط يزيل الرابط فقط (عرض لا تاريخ). حذف الموسم لا يحذف أي كيان مرتبط أبدًا (احتفاظ).
 *    المرحلة اختيارية nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->string('content_type', 24);
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('competitive_event_id')->nullable()->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('mission_pool_id')->nullable()->constrained('mission_pools')->cascadeOnDelete();
            $table->foreignId('season_phase_id')->nullable()->constrained('season_phases')->nullOnDelete();
            $table->string('display_label', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->unique(['season_id', 'campaign_id'], 'season_items_campaign_unique');
            $table->unique(['season_id', 'competitive_event_id'], 'season_items_event_unique');
            $table->unique(['season_id', 'mission_pool_id'], 'season_items_pool_unique');
            $table->index(['season_id', 'content_type', 'sort_order'], 'season_items_season_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_content_items');
    }
};
