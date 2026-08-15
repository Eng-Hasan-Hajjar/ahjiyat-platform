<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * إضافة دور "committee" (عضو لجنة) لعمود role بجدول users.
     * ملاحظة: العملية مكتوبة لـ MySQL مباشرة عبر DB::statement لأن
     * doctrine/dbal غير مثبت بالمشروع (مطلوب لاستخدام ->change() على enum).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('volunteer', 'project_owner', 'admin', 'committee') NOT NULL DEFAULT 'volunteer'");
    }

    public function down(): void
    {
        // تنبيه: إذا كان هناك مستخدمون بدور committee، حوّل دورهم يدوياً قبل التراجع
        // مثال: UPDATE users SET role='admin' WHERE role='committee';
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('volunteer', 'project_owner', 'admin') NOT NULL DEFAULT 'volunteer'");
    }
};