<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلب الدكتور رقم 2: معلومات أكثر تساعد بالبحث الأكاديمي
     *
     * تم التحقق من البنية الفعلية لجدول projects عبر:
     *   php artisan tinker --execute="print_r(Schema::getColumnListing('projects'));"
     *
     * الأعمدة التالية كانت موجودة مسبقاً ولم تتم إضافتها مجدداً:
     *   damage_type, damage_percentage, estimated_cost
     *
     * الأعمدة الجديدة الفعلية المضافة هنا فقط (تم التأكد من غيابها):
     *   damage_date, area_sqm, affected_employees_count,
     *   commercial_register_no, owner_legal_name, owner_contact_phone
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // تاريخ وقوع الضرر (للتوثيق الزمني)
            $table->date('damage_date')->nullable()->after('damage_percentage');

            // المساحة التقريبية للمنشأة بالمتر المربع
            $table->unsignedInteger('area_sqm')->nullable()->after('city');

            // عدد الموظفين/الأشخاص المتأثرين بتوقف المنشأة عن العمل
            $table->unsignedInteger('affected_employees_count')->nullable()->after('estimated_cost');

            // الرقم الضريبي / رقم السجل التجاري (للتوثيق الرسمي القانوني)
            $table->string('commercial_register_no')->nullable()->after('affected_employees_count');

            // اسم مالك المنشأة كما يظهر بالوثائق الرسمية (قد يختلف عن اسم حساب المستخدم)
            $table->string('owner_legal_name')->nullable()->after('commercial_register_no');

            // رقم هاتف إضافي للتواصل الرسمي مع المالك (غير حساب تسجيل الدخول)
            $table->string('owner_contact_phone')->nullable()->after('owner_legal_name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'damage_date',
                'area_sqm',
                'affected_employees_count',
                'commercial_register_no',
                'owner_legal_name',
                'owner_contact_phone',
            ]);
        });
    }
};