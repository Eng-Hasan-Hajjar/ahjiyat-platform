<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fraud_flags الحالية تحمل resolved (boolean) فقط - لا Resolver ولا وقت
 * إغلاق ولا ملاحظة. هذه الحقول مطلوبة فعلياً لواجهة "معالجة/إغلاق العلامة"
 * الجديدة (E6) - إضافة بسيطة ومبرَّرة، لا تمس أي عمود قديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fraud_flags', function (Blueprint $table) {
            $table->foreignId('resolved_by')->nullable()->after('resolved')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->text('resolution_note')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('fraud_flags', function (Blueprint $table) {
            $table->dropColumn(['resolved_by', 'resolved_at', 'resolution_note']);
        });
    }
};