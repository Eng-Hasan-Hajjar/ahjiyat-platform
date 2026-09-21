<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل منفصل تماماً عن authorization_audit_logs (E5 - RBAC فقط: أدوار/
 * صلاحيات). هذا يغطي العمليات التشغيلية اليومية: تجميد/رفع تجميد، تعديل
 * جواهر يدوي، قبول/رفض استبدال، معالجة احتيال، إنهاء جلسة. Immutable -
 * لا updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_audit_logs');
    }
};