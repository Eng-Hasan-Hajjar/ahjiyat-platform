<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * إضافة حالة "removed" لعمود status بجدول project_volunteer،
     * تُستخدم عند حذف حساب متطوع كان منضماً فعلياً لفريق مشروع،
     * بدل ما تبقى حالته "accepted" بشكل مضلّل بعد حذف حسابه.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE project_volunteer MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'completed', 'removed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // تنبيه: إن وُجدت صفوف بحالة removed، حوّلها يدوياً قبل التراجع
        DB::statement("ALTER TABLE project_volunteer MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'completed') NOT NULL DEFAULT 'pending'");
    }
};