<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_frozen وfrozen_reason موجودان مسبقاً - ينقصهما "متى" و"من قام بذلك"
 * (مطلوبة صراحة لعرض حالة الحساب في User 360 ولسجل تجميد/رفع تجميد
 * دقيق). OperationalAuditLog يسجّل الحدث كاملاً أيضاً، لكن هذان العمودان
 * يتيحان عرضاً فورياً على صف المستخدم نفسه بلا Join إضافي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('frozen_reason');
            $table->foreignId('frozen_by')->nullable()->after('frozen_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('frozen_by');
            $table->dropColumn('frozen_at');
        });
    }
};