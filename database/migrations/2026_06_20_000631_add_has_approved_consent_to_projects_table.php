<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * عمود مساعد سريع: هل المشروع لديه موافقة خطية معتمدة؟
     * (Denormalized flag لتسريع الاستعلامات والعرض، يُحدَّث تلقائياً
     * عبر ProjectApprovalController عند اعتماد/رفض الموافقة)
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('has_approved_consent')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('has_approved_consent');
        });
    }
};