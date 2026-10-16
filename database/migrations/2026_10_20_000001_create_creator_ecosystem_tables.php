<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E28 - منظومة المبدعين وأحجيات المجتمع. خمسة جداول بحدّ أدنى لما يلزم فعلًا:
 *
 *  creator_profiles           ملف مبدع واحد لكل مستخدم (unique user_id) + slug فريد + حالة الطلب/الاعتماد.
 *  creator_puzzles            العمل الإبداعي (الهوية الثابتة + لقطة النشر الحالية + الإخفاء/الأرشفة). is_visible مشتقّة آليًا بالنموذج وهي أساس فهارس البحث/الاكتشاف.
 *  creator_puzzle_revisions   كل نسخة: حمولة التأليف (مشفَّرة) + حالة سير العمل + المراجعة + ربط الأحجية القانونية (puzzle_id فريد) عند الاعتماد. هي سجل التاريخ.
 *  community_puzzle_attempts  سجل لعب المجتمع **منفصل عمدًا عن puzzle_attempts**: لا يغذّي الاقتصاد/XP/المهام/السلسلة/الإنجازات/لوحة الصدارة/التأهيل بالبناء.
 *  community_puzzle_reports   بلاغات الأحجيات المنشورة. open_marker يجعل «بلاغ مفتوح واحد لكل مستخدم/عمل» قيدًا بقاعدة البيانات (NULL عند الإغلاق فلا يتعارض).
 *
 * مؤشرات latest_revision_id / published_revision_id مجرد أعمدة بلا FK (دورة FK بين العمل والنسخ)، والخدمات تملك سلامتها؛ كل علاقات الملكية الأخرى قيود FK فعلية.
 * لا حذف تتابعي للأحجيات القانونية: puzzle_id بالنسخ وpublished_puzzle_id بالعمل restrictOnDelete (التاريخ لا يُمحى).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->text('bio')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('approved_at')->nullable(); // أول اعتماد (تاريخ الانضمام كمبدع) ولا يُكتب فوقه
            $table->timestamps();

            $table->index(['status', 'submitted_at'], 'creator_profiles_status_submitted_index');
        });

        Schema::create('creator_puzzles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('latest_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();

            // لقطة النشر الحالية (null قبل أول نشر). البحث والاكتشاف يقرأونها من هذا الجدول وحده بلا ضمّ.
            $table->foreignId('published_puzzle_id')->nullable()->constrained('puzzles')->restrictOnDelete();
            $table->string('published_title')->nullable();
            $table->foreignId('published_category_id')->nullable()->constrained('puzzle_categories')->nullOnDelete();
            $table->string('published_difficulty', 16)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 24)->nullable();
            $table->text('hidden_note')->nullable();

            $table->boolean('is_visible')->default(false);
            $table->unsignedInteger('plays_count')->default(0); // لاعبون مميَّزون للعمل (أول محاولة للمستخدم على أي نسخة): عرض فقط
            $table->timestamps();

            $table->index(['creator_profile_id', 'id'], 'creator_puzzles_owner_index');
            $table->index(['creator_profile_id', 'is_visible', 'published_at'], 'creator_puzzles_public_creator_index');
            $table->index(['is_visible', 'published_at'], 'creator_puzzles_visible_new_index');
            $table->index(['is_visible', 'published_title'], 'creator_puzzles_visible_title_index');
        });

        Schema::create('creator_puzzle_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');
            $table->string('status', 20)->default('draft');

            $table->string('kind', 24);
            $table->string('title');
            $table->foreignId('puzzle_category_id')->nullable()->constrained('puzzle_categories')->nullOnDelete();
            $table->string('difficulty', 16);
            $table->text('content'); // encrypted:array - الحمولة المهيكلة (prompt/hint/answer/choices/items/faces). لا تصل لأي عرض عام.
            $table->string('image_path')->nullable(); // يضعه المخدّم وحده من رفع صالح؛ لا قيمة من العميل

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            $table->foreignId('puzzle_id')->nullable()->unique()->constrained('puzzles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['creator_puzzle_id', 'revision_number'], 'creator_revisions_number_unique');
            $table->index(['status', 'submitted_at'], 'creator_revisions_queue_index');
        });

        Schema::create('community_puzzle_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->boolean('is_correct')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'puzzle_id'], 'community_attempts_user_version_index');
            $table->index(['creator_puzzle_id', 'user_id'], 'community_attempts_work_user_index');
            $table->index('created_at', 'community_attempts_created_index');
        });

        Schema::create('community_puzzle_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->nullable()->constrained('puzzles')->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 16);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('open_marker')->nullable()->default(1);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('handler_note')->nullable();
            $table->timestamps();

            $table->unique(['creator_puzzle_id', 'reporter_id', 'open_marker'], 'community_reports_one_open_unique');
            $table->index(['status', 'created_at'], 'community_reports_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_puzzle_reports');
        Schema::dropIfExists('community_puzzle_attempts');
        Schema::dropIfExists('creator_puzzle_revisions');
        Schema::dropIfExists('creator_puzzles');
        Schema::dropIfExists('creator_profiles');
    }
};
