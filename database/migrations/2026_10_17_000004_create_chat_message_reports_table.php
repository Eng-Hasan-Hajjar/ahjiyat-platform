<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E21: بلاغات الرسائل. تصنيف مغلق، وبلاغ واحد لكل (رسالة، مُبلِّغ) UNIQUE، والحالة: pending|reviewed|dismissed|actioned. الرسالة لا تُحذف فعليًا فتبقى البلاغات صالحة للمراجعة. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 16);
            $table->string('details', 500)->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_message_id', 'reporter_id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_reports');
    }
};
