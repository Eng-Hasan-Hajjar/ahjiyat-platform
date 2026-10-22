<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E36 - إعجابات التعليقات وبلاغاتها.
|
|  discussion_comment_likes    إعجاب واحد لكل (مستخدم، تعليق) بقيد فريد. من أعجب **خاص** (لا واجهة تعرضه)؛ العدّاد المجمَّع likes_count على التعليق وحده علني.
|                              حذف المستخدم أو التعليق ← cascade (والعدّاد يُنقَص خدميًا قبل حذف المستخدم عبر DiscussionCleanup).
|  discussion_comment_reports  بلاغ مفتوح واحد لكل (تعليق، مبلِّغ) بقيد قاعدة بيانات: open_marker=1 عند الفتح وNULL عند الإغلاق (اصطلاح E28/E35).
|                              السبب من قائمة مغلقة. body_snapshot = نص التعليق لحظة البلاغ (دليل للمشرف فقط؛ لا يُعرض للعامة) فلا يضيع الدليل بتعديل
|                              التعليق أو حذفه. reporter_id يصير NULL عند حذف حساب المبلِّغ (البلاغ نفسه يبقى للإشراف دون هوية)؛ والبلاغ يُحذف
|                              مع التعليق فقط (حذف النقاش كله)، لا بالحذف الناعم.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discussion_comment_id')->constrained('discussion_comments')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'discussion_comment_id'], 'discussion_likes_user_comment_unique');
            $table->index(['discussion_comment_id', 'created_at'], 'discussion_likes_comment_index');
        });

        Schema::create('discussion_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_comment_id')->constrained('discussion_comments')->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 24);
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('open_marker')->nullable()->default(1);
            $table->string('body_snapshot', 1000);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('handler_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['discussion_comment_id', 'reporter_id', 'open_marker'], 'discussion_reports_one_open_unique');
            $table->index(['status', 'created_at'], 'discussion_reports_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_comment_reports');
        Schema::dropIfExists('discussion_comment_likes');
    }
};
