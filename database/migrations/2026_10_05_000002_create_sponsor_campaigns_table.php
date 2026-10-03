<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E14 (بند 19/20/323/579): status هي مصدر الحقيقة الوحيد - عمدًا بلا
 * is_active/approved منفصلَين (يمنع تناقضًا بنيويًا). الانتهاء الزمني
 * يُشتَق من starts_at/ends_at وقت العرض نفسه - لا حالة "ended" يدوية ولا
 * Cron (درس E13.1 بالضبط: الصحة لا تعتمد على جدولة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsor_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('internal_key')->unique();
            $table->string('sponsor_name');
            $table->string('campaign_name');
            $table->string('status')->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->text('notes')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor_campaigns');
    }
};
