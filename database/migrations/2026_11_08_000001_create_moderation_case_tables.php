<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E38 (1/2) - حالات الإشراف: moderation_cases + مصادرها + التسلسل الزمني + الأدلة.
|
| مبادئ المخطط:
|  - البلاغات المجالية (E21/E28/E35/E36) تبقى مصدر الحقيقة. لا نسخ لمحتوى البلاغ هنا: moderation_case_sources مؤشر مقيَّد بقائمة بيضاء (source_type) + لقطة تصنيفية فقط.
|  - **لا تتتابع الحذفات مع السجل**: كل FK نحو moderation_cases هو RESTRICT (الحالات لا تُحذف أبدًا)، وFK المستخدمين nullOnDelete فيبقى التاريخ كاملًا بلا هوية.
|  - moderation_case_events و moderation_evidence **إلحاقية فقط** (النموذج يرفض التعديل والحذف؛ لا updated_at).
|  - moderation_case_sources.active_marker: 1 ما دامت الحالة فعّالة ولم يُفصل المصدر، NULL عند الإغلاق/الفصل. قيد unique (source_type, source_id, active_marker)
|    يجعل «بلاغ واحد في حالة فعّالة واحدة كحدٍّ أقصى» قيدًا بقاعدة البيانات (اصطلاح open_marker القائم)، فالإضافة المتزامنة لا تكرّر.
|  - public_id (ULID) هو المعرّف العلني الوحيد؛ case_number (SAFE-2026-000123) للتواصل الداخلي وليس مفتاح وصول.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('moderation_cases_public_unique');
            $table->string('case_number', 24)->nullable()->unique('moderation_cases_number_unique');
            $table->string('status', 16)->default('open');
            $table->string('priority', 8)->default('normal');
            $table->string('category', 24);

            // موضوع الحالة (قائمة بيضاء مغلقة في الخدمة): community_puzzle | user_collection | discussion_comment | chat_message | user
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_label', 160)->nullable();
            $table->string('summary', 500)->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            // أهداف تشغيل داخلية مقترحة (ليست وعدًا للمستخدم)
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('resolution', 24)->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'id'], 'moderation_cases_queue_index');
            $table->index(['assigned_to', 'status'], 'moderation_cases_assignee_index');
            $table->index('subject_user_id', 'moderation_cases_subject_user_index');
            $table->index(['subject_type', 'subject_id'], 'moderation_cases_subject_index');
            $table->index(['status', 'resolution_due_at'], 'moderation_cases_sla_index');
            $table->index('category', 'moderation_cases_category_index');
        });

        Schema::create('moderation_case_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained('moderation_cases')->restrictOnDelete();
            // قائمة بيضاء: community_puzzle_report | user_collection_report | discussion_comment_report | chat_message_report | fraud_flag (لا فئة من العميل أبدًا)
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 32)->nullable();
            $table->string('category', 24);
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamp('detached_at')->nullable();
            $table->foreignId('detached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_type', 'source_id', 'active_marker'], 'moderation_sources_one_active_unique');
            $table->index(['moderation_case_id', 'id'], 'moderation_sources_case_index');
            // لا فهرس (source_type, source_id) منفصل: بادئة الفهرس الفريد أعلاه تخدم بحث «أي حالة تحتجز هذا البلاغ» (فهرس زائد يكسر تدقيق E31/Q-4).
        });

        Schema::create('moderation_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained('moderation_cases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32)->nullable();
            $table->string('note', 1000)->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['moderation_case_id', 'id'], 'moderation_events_case_index');
            $table->index(['type', 'created_at'], 'moderation_events_type_index');
        });

        Schema::create('moderation_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained('moderation_cases')->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            // moderator_note (نص عادي) | content_snapshot (لقطة نصية للمحتوى المُبلَّغ عنه فقط). لا ملفات ولا روابط خارجية.
            $table->string('kind', 24);
            $table->text('body');
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['moderation_case_id', 'id'], 'moderation_evidence_case_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_evidence');
        Schema::dropIfExists('moderation_case_events');
        Schema::dropIfExists('moderation_case_sources');
        Schema::dropIfExists('moderation_cases');
    }
};
