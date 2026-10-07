<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E21: كتم الدردشة العامة (منفصل عن تجميد الحساب): مؤقّت دائمًا (expires_at بمدد مغلقة)، بسبب إلزامي، ويُرفع بـlifted_at. المكتوم يقرأ ولا يرسل. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 200);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index('lifted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_mutes');
    }
};
