<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E38 (2/2) - سجل الإجراءات التنفيذية (moderation_actions) + الاعتراضات (moderation_appeals).
|
|  moderation_actions
|     سجل مُحكَم لما فعله المشرف عبر **خدمات المجال الرسمية** (لا تغيير مباشر): الإجراء، الهدف، التصنيف، نسخة السياسة، السبب الداخلي (للمشرفين فقط) والرسالة
|     العامة للمستخدم المعني (public_note، نص عادي). الصف لا يُعدَّل إلا في ثلاثة أعمدة مشتقّة فقط (active_marker, reversed_at, reversed_by) عبر النموذج.
|     active_marker=1 للإجراءات القابلة للعكس (إخفاء/تجميد/تعليق/قفل) ما دامت فعّالة وNULL بعد عكسها (أو دائمًا لـ warn/no_action/الإجراءات العاكسة):
|     قيد unique (action, target_key, active_marker) يمنع تكرار الإجراء الفعّال نفسه على الهدف نفسه تحت التزامن (يُرجِع معاملة الخدمة كلها).
|  moderation_appeals
|     اعتراض المستخدم على إجراء. active_marker=1 ما دام pending|in_review وNULL بعد القرار/السحب: unique (moderation_action_id, active_marker) = اعتراض فعّال واحد لكل إجراء.
|     إعادة الاعتراض بعد قرار نهائي ممنوعة افتراضيًا (config trust_safety.appeals.allow_reappeal) وتُفحص بالخدمة.
|     **لا تتتابع الحذفات**: FK الإجراء RESTRICT (لا يُحذف إجراء له اعتراض)، وFK المستخدمين nullOnDelete فيبقى السجل بلا هوية.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('moderation_actions_public_unique');
            $table->foreignId('moderation_case_id')->constrained('moderation_cases')->restrictOnDelete();
            $table->string('action', 24);

            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // مفتاح الهدف المنطقي للقيد الفريد: user:12 | creator_profile:7 | discussion_comment:55 | discussion_thread:9 ...
            $table->string('target_key', 48);

            $table->string('category', 24);
            $table->unsignedSmallInteger('policy_version')->default(1);
            $table->string('reason', 400);
            $table->string('public_note', 500)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('user_visible')->default(false);
            $table->timestamp('appealable_until')->nullable();

            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['action', 'target_key', 'active_marker'], 'moderation_actions_one_active_unique');
            $table->index(['target_user_id', 'id'], 'moderation_actions_user_index');
            $table->index(['moderation_case_id', 'id'], 'moderation_actions_case_index');
            $table->index(['subject_type', 'subject_id'], 'moderation_actions_subject_index');
            $table->index(['action', 'created_at'], 'moderation_actions_report_index');
        });

        Schema::create('moderation_appeals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('moderation_appeals_public_unique');
            $table->foreignId('moderation_action_id')->constrained('moderation_actions')->restrictOnDelete();
            $table->foreignId('appellant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);

            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            // تضارب مصلحة: المراجِع هو من نفّذ الإجراء الأصلي (استثناء المشرف الأعلى فقط، ويُوسَم ويُسجَّل)
            $table->boolean('conflict_flag')->default(false);
            $table->string('decision_note', 800)->nullable();
            $table->string('decision_message', 800)->nullable();
            $table->timestamps();

            $table->unique(['moderation_action_id', 'active_marker'], 'moderation_appeals_one_active_unique');
            $table->index(['status', 'id'], 'moderation_appeals_queue_index');
            $table->index(['appellant_id', 'id'], 'moderation_appeals_user_index');
            $table->index(['reviewer_id', 'status'], 'moderation_appeals_reviewer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_appeals');
        Schema::dropIfExists('moderation_actions');
    }
};
