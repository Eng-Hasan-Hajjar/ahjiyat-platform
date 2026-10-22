<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E36 - النقاشات المرتبطة بكيان عام (أحجية مجتمع / سلسلة مبدع / مجموعة عامة).
|
|  discussion_threads   نقاش واحد لكل كيان: UNIQUE(subject_type, subject_id) بقيد قاعدة بيانات (إنشاء كسول؛ السباق يخسر بالقيد ويعيد قراءة الصف).
|                       subject_type من قائمة بيضاء بالكود (DiscussionSubjectRegistry) لا اسم صنف PHP. subject_id معرّف رقمي داخلي بلا FK (متعدد الأنواع)؛
|                       الكيان المحذوف يترك نقاشًا يتيمًا خامدًا (لا يُعرض: الكيان لا يُحلّ) يكشفه discussions:reconcile ويُنظَّف عند حذف المجموعة.
|                       comments_count = التعليقات الحيّة الظاهرة (visible ∧ غير محذوفة، جذور وردود) عدّاد مشتقّ تحدّثه خدمة واحدة ذريًا.
|  discussion_comments  جذر + مستوى ردود واحد. public_id ULID للمسارات. status: visible | moderation_hidden؛ الحذف الناعم = deleted_at (الجسم يُفرَّغ).
|                       سلامة (النقاش، الأب) بقيد مفتاح أجنبي مركّب إلى UNIQUE(discussion_thread_id, id): لا ردّ يتبع أبًا من نقاش آخر حتى لو كُسرت الخدمة.
|                       عمق الرد (≤1) تفرضه الخدمة ويكشفه discussions:reconcile (لا يُعبَّر عنه بقيد مرجعي).
|                       user_id يصير NULL عند حذف الحساب (والتنظيف الخدمي يحوّل تعليقاته إلى لحود محذوفة قبل ذلك فلا يبقى نص منسوب).
|                       الجسم VARCHAR(1000) عمدًا (السقف صلب بالخدمة) ولا فهرس على النص.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_threads', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lock_reason', 255)->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id'], 'discussion_threads_subject_unique');
            $table->index(['is_locked', 'id'], 'discussion_threads_locked_index');
        });

        Schema::create('discussion_comments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('discussion_thread_id')->constrained('discussion_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('body', 1000);
            $table->string('status', 20)->default('visible');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 24)->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestamps();

            // هدف المفتاح الأجنبي المركّب (يضمن انتماء الأب لنفس النقاش).
            $table->unique(['discussion_thread_id', 'id'], 'discussion_comments_thread_id_unique');
            $table->index(['discussion_thread_id', 'parent_id', 'created_at'], 'discussion_comments_list_index');
            $table->index(['parent_id', 'created_at'], 'discussion_comments_replies_index');
            $table->index(['user_id', 'created_at'], 'discussion_comments_author_index');
            $table->index(['status', 'created_at'], 'discussion_comments_status_index');

            $table->foreign(['discussion_thread_id', 'parent_id'], 'discussion_comments_parent_thread_foreign')
                ->references(['discussion_thread_id', 'id'])->on('discussion_comments')->cascadeOnDelete();
        });

        $this->addStatusCheck();
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_comments');
        Schema::dropIfExists('discussion_threads');
    }

    /** قيد قيم الحالة بمستوى القاعدة (دفاع إضافي؛ الخدمة تتحقق أولًا). SQLite لا يدعم ADD CONSTRAINT؛ فشل الإضافة لا يكسر الترحيل. */
    protected function addStatusCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement("ALTER TABLE discussion_comments ADD CONSTRAINT discussion_comments_status_check CHECK (status IN ('visible','moderation_hidden'))");
        } catch (Throwable) {
            // الحارس الفعلي هو DiscussionComment::STATUSES عبر الخدمة.
        }
    }
};
