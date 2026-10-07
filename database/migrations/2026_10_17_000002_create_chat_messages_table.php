<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E21: الرسائل (نص عادي). لا حذف فعليًا أبدًا: deleted_at = حذف المرسل (Tombstone) و hidden_* = إخفاء إشرافي، والنص يبقى بالقاعدة لتبقى مراجعة البلاغات ممكنة.
 * الفهارس: (thread,id) للصفحات بالمؤشر، sender، و(thread,sender,created_at) لحماية التكرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['chat_thread_id', 'id']);
            $table->index('sender_id');
            $table->index(['chat_thread_id', 'sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
