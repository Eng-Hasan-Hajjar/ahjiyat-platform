<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - رموز الإحالة الشخصية.
 *
 *  - code UNIQUE              ⇒ رمز واحد لا يخص مستخدمَين (حتى بعد التدوير؛ الرموز القديمة تبقى صفوفًا معطّلة لتفسير روابط قديمة).
 *  - active_for_user_id UNIQUE ⇒ رمز نشط واحد كحدّ أقصى لكل مستخدم: يساوي user_id للرمز النشط وNULL للمدوَّر/المعطّل (NULL متعدد مسموح). الحارس الأخير ضد سباق إنشاء رمزين.
 * سياسة الحذف: حذف المستخدم يحذف رموزه (CASCADE)؛ الإحالات التاريخية تحتفظ بالمحيل SET NULL ولا تنكسر (انظر user_referrals).
 * العدّادات (clicks/shares) تقريبية بالتصميم (نقرات فريدة بالساعة، بلا IP/UA مخزَّن)؛ ليست مصدرًا للمكافآت أبدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->unsignedBigInteger('active_for_user_id')->nullable()->unique('referral_codes_active_for_user_unique');
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'referral_codes_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
