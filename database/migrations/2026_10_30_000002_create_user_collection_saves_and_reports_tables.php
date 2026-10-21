<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E35 - حفظ المجموعات العامة وبلاغاتها.
|
|  user_collection_saves     (المستخدم، المجموعة) فريد. هوية من حفظ **خاصة** (لا واجهة عامة تعرضها)؛ العدّاد المجمَّع على المجموعة (saves_count) وحده علني.
|                            حذف المستخدم أو المجموعة ← cascade (وتُنقص العدّادات خدميًا قبل حذف المستخدم عبر EngagementCleanup).
|  user_collection_reports   بلاغ مفتوح واحد لكل (مجموعة، مبلِّغ) بقيد قاعدة بيانات: open_marker=1 عند الفتح وNULL عند الإغلاق (نفس اصطلاح E28). السبب من قائمة مغلقة؛ لا نص حر.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_collection_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_collection_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'user_collection_id'], 'collection_saves_user_collection_unique');
            $table->index(['user_id', 'id'], 'collection_saves_user_recent_index');
            $table->index(['user_collection_id', 'created_at'], 'collection_saves_collection_index');
        });

        Schema::create('user_collection_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 16);
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('open_marker')->nullable()->default(1);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('handler_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_collection_id', 'reporter_id', 'open_marker'], 'collection_reports_one_open_unique');
            $table->index(['status', 'created_at'], 'collection_reports_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_collection_reports');
        Schema::dropIfExists('user_collection_saves');
    }
};
