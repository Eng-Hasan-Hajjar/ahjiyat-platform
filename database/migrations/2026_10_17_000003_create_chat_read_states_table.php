<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E21: حالة القراءة صف واحد لكل (غرفة، مستخدم): مؤشر آخر رسالة مقروءة (لا صف لكل رسالة × مستخدم). UNIQUE(thread, user). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_read_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_thread_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_read_states');
    }
};
