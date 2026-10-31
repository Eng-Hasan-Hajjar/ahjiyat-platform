<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E45 - تطوّر AI (المساعد الذكي لمنصة الأحجيات): أقل مخطط لازم، كله جداول جديدة بلا مساس بأي جدول قائم.
|
|  ai_knowledge_articles   مقالات معرفة **منقّحة يدويًا** موجَّهة للمستخدم (لا استيراد آلي لوثائق المستودع). الحالات: draft | published | retired؛ الجمهور: public | member فقط (لا معرفة إدارية).
|                          version يزداد مع كل نشر/تعديل جوهري؛ content_hash بصمة النص المنشور. وحدها المنشورة تصل للاسترجاع.
|  ai_knowledge_chunks     مقاطع النص الآمن المفهرَسة (مشتقة من المقال المنشور): article_version يربط كل مقطع بنسخة المقال التي أنتجته فلا يُخدَم مقطع متقادم بعد التعديل.
|  ai_conversations        محادثات الأعضاء فقط (الضيف محادثة جلسة مؤقتة بلا صفوف). expires_at = الاحتفاظ القابل للضبط (افتراضي 30 يومًا). تُحذف تتابعيًا مع المستخدم.
|  ai_messages             رسائل المحادثة؛ الأدوار مغلقة (user | assistant)، جسم الرسالة **مشفَّر** (cast: encrypted)، والمصادر روابط داخلية آمنة فقط. (conversation_id, seq) فريد ⇒ ترتيب حتمي ومنع تزاحم الإرسال.
|  ai_feedback             تقييم 👍/👎 لردّ مساعد: معرّف الرسالة + التقييم + سبب مغلق اختياري فقط. تبقى مجهولة الهوية (SET NULL) بعد حذف الرسالة أو المستخدم لتغذية تحسين المعرفة دون نص محادثة.
|  ai_request_metrics      مقاييس تشغيلية خالية من المحتوى: لا نص ولا هوية مستخدم (الجمهور member|guest فقط). تغذّي الميزانية اليومية العامة ولوحة الاستخدام وفجوات المعرفة.
|
| ليست جداول مالية: لا تُسجَّل في FinancialHistoryGuard، ولا أعمدة مبالغ. أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا). لا فهارس بادئة مكرِّرة (حارس Q-4). لا float/decimal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('ai_knowledge_articles_public_unique');
            $table->string('slug', 120);
            $table->string('locale', 8)->default('ar');
            $table->string('title', 160);
            $table->string('summary', 400);
            $table->text('body');
            $table->string('audience', 8)->default('public');      // public | member
            $table->string('status', 10)->default('draft');        // draft | published | retired
            $table->string('area', 24)->default('general');        // قائمة بيضاء مغلقة (AiKnowledgeArticle::AREAS)
            $table->string('tags', 300)->nullable();               // كلمات مفتاحية للاسترجاع (مفصولة بفاصلة)
            $table->string('source_label', 60)->default('دليل المنصة');
            $table->string('canonical_path', 190)->nullable();     // مسار داخلي نسبي فقط (يبدأ بـ/)
            $table->string('source_type', 20)->default('curated'); // curated | distilled
            $table->string('source_reference', 190)->nullable();   // مرجع إداري فقط (لا يُرسَل للنموذج ولا يُعرَض للمستخدم)
            $table->unsignedInteger('version')->default(1);
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'slug'], 'ai_knowledge_articles_slug_unique');
            $table->index(['status', 'audience', 'locale'], 'ai_knowledge_articles_lookup_index');
            $table->index('created_by', 'ai_knowledge_articles_created_by_index');
            $table->index('reviewed_by', 'ai_knowledge_articles_reviewed_by_index');
        });

        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('ai_knowledge_articles')->cascadeOnDelete();
            $table->unsignedInteger('article_version');
            $table->unsignedSmallInteger('position');
            $table->string('section', 160)->nullable();
            $table->text('body');
            $table->string('url', 190)->nullable();               // مسار داخلي نسبي فقط
            $table->string('source_label', 60);
            $table->char('content_hash', 64);
            $table->unsignedSmallInteger('char_count');
            $table->timestamp('created_at')->nullable();

            $table->unique(['article_id', 'position'], 'ai_knowledge_chunks_position_unique');
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('ai_conversations_public_unique');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'last_message_at'], 'ai_conversations_user_recent_index');
            $table->index('expires_at', 'ai_conversations_expires_index');
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('ai_messages_public_unique');
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('role', 10);                            // user | assistant (مجموعة مغلقة)
            $table->mediumText('body');                            // مشفَّر بـcast: encrypted
            $table->json('sources')->nullable();                   // [{label,url}] روابط داخلية نسبية فقط
            $table->string('outcome', 16)->nullable();             // للردود: answered | abstained | refused | fallback | out_of_scope | error
            $table->timestamp('created_at')->nullable();

            $table->unique(['conversation_id', 'seq'], 'ai_messages_conversation_seq_unique');
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('ai_messages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rating', 4);                           // up | down
            $table->string('reason', 16)->nullable();              // inaccurate | incomplete | unsafe | off_topic | other
            $table->string('area', 24)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id'], 'ai_feedback_message_user_unique');
            $table->index('user_id', 'ai_feedback_user_index');
            $table->index('created_at', 'ai_feedback_created_index');
        });

        Schema::create('ai_request_metrics', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('ai_request_metrics_public_unique');
            $table->string('audience', 8);                         // member | guest
            $table->string('provider', 24)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('outcome', 16);                         // answered | abstained | refused | fallback | out_of_scope | error | limited
            $table->string('reason', 24)->nullable();              // missing_knowledge | provider_failure | puzzle_secret_refusal | out_of_scope | privacy_refusal | budget | output_blocked | rate_limited
            $table->string('area', 24)->nullable();
            $table->boolean('grounded')->default(false);
            $table->boolean('streamed')->default(false);
            $table->unsignedTinyInteger('tool_calls')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error_category', 24)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at', 'ai_request_metrics_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_metrics');
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_knowledge_chunks');
        Schema::dropIfExists('ai_knowledge_articles');
    }
};
