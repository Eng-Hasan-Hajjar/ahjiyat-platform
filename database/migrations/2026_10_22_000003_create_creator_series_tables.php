<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (B) - سلاسل المبدعين: قائمة مرتّبة من أحجيات مجتمع منشورة للمبدع نفسه. ثلاثة جداول بحدّ أدنى:
 *
 *  creator_series            هوية السلسلة الثابتة (public_id + slug) + لقطة النشر الحالية + الإخفاء/الأرشفة. لا عمود is_visible مخزَّن: الظهور يُشتقّ حيًّا
 *                            (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد وحسابه سليم ∧ كل عناصر النسخة المنشورة ظاهرة الآن) في CreatorSeriesVisibilityService،
 *                            فإخفاء أحجية أو تعليق مبدع يخفي السلسلة فورًا ويستعيدها فورًا بلا مصالحة ولا حالة قديمة.
 *  creator_series_revisions  كل نسخة: العنوان/الوصف/الغلاف + سير العمل (draft → pending_review → approved/changes_requested/rejected) + المراجعة.
 *                            تعديل سلسلة منشورة = نسخة جديدة تُراجع؛ المنشورة تبقى كما هي حتى اعتماد الجديدة (لا تعديل صامت).
 *  creator_series_items      عناصر النسخة بترتيب صريح: unique(النسخة، العمل) و unique(النسخة، الموضع).
 *
 * لا اقتصاد: لا أعمدة جواهر/XP/جوائز. أعمدة latest/published_revision_id بلا FK (دورة FK)، والخدمات تملك سلامتها. أسماء الفهارس صريحة (حدّ MySQL 64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_series', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100)->unique();

            $table->unsignedBigInteger('latest_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();

            // لقطة النشر (null قبل أول نشر): بطاقات البحث والاكتشاف تقرؤها من هذا الجدول وحده بلا ضمّ للنسخ.
            $table->string('published_title')->nullable();
            $table->string('published_cover_path')->nullable();
            $table->unsignedSmallInteger('published_items_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 24)->nullable();
            $table->text('hidden_note')->nullable();
            $table->timestamps();

            $table->index(['creator_profile_id', 'id'], 'creator_series_owner_index');
            $table->index(['creator_profile_id', 'published_at'], 'creator_series_creator_public_index');
            $table->index(['hidden_at', 'archived_at', 'published_at'], 'creator_series_public_index');
            $table->index('published_title', 'creator_series_title_index');
        });

        Schema::create('creator_series_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_series_id')->constrained('creator_series')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');
            $table->string('status', 20)->default('draft');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['creator_series_id', 'revision_number'], 'creator_series_revisions_number_unique');
            $table->index(['status', 'submitted_at'], 'creator_series_revisions_queue_index');
        });

        Schema::create('creator_series_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_series_revision_id')->constrained('creator_series_revisions')->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained('creator_puzzles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['creator_series_revision_id', 'creator_puzzle_id'], 'creator_series_items_work_unique');
            $table->unique(['creator_series_revision_id', 'position'], 'creator_series_items_position_unique');
            $table->index('creator_puzzle_id', 'creator_series_items_work_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_series_items');
        Schema::dropIfExists('creator_series_revisions');
        Schema::dropIfExists('creator_series');
    }
};
