<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E17-B: مشاركة مستخدم بحدث. UNIQUE(event, user) يمنع التسجيل المزدوج. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitive_event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('registered');
            $table->timestamp('registered_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['competitive_event_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitive_event_participants');
    }
};
