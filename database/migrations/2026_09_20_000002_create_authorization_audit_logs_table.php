<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل تدقيق للتغييرات الإدارية على RBAC فقط (إنشاء/تعديل/حذف دور، تغيير
 * صلاحيات، تعيين/إزالة دور أو صلاحية مباشرة لمستخدم) - لا نسجّل هنا كل
 * فحص can() (سيولّد ملايين السجلات بلا فائدة). Immutable عمداً - لا
 * updated_at، ولا تعديل من الواجهة إطلاقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_audit_logs');
    }
};