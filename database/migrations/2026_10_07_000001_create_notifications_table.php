<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E15: جدول إشعارات Laravel القياسي (User يستخدم Notifiable أصلًا لكن الجدول لم يكن
 * موجودًا قط) + ثلاثة أعمدة مخصَّصة:
 *  - type_key / category: مفتاحا السجل المغلق (لا نص حر) - للفلترة والتحليلات بلا JSON search.
 *  - idempotency_key: المفتاح الدلالي الحتمي للحدث؛ قيد فريد (user + key) يمنع التكرار
 *    بنيويًا حتى لو تكرر الطلب/المجدوِل/إعادة المحاولة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->string('type_key', 60);
            $table->string('category', 30);
            $table->string('idempotency_key', 191);

            $table->unique(['notifiable_type', 'notifiable_id', 'idempotency_key'], 'notifications_idempotency_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_index');
            $table->index(['type_key', 'created_at'], 'notifications_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
