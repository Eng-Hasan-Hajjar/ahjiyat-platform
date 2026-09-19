<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول roles من Spatie لا يحمل Metadata عرضية (تسمية عربية، وصف،...) - هذه
 * الهجرة تضيفها كأعمدة على نفس الجدول (أبسط من جدول منفصل لهذه الحقول
 * القليلة). يجب أن تُطبَّق بعد هجرة Spatie نفسها (create_permission_tables)
 * - راجع تعليمات التركيب بـdocs/roles-permissions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label_ar')->nullable()->after('name');
            $table->text('description')->nullable()->after('label_ar');
            $table->boolean('is_system')->default(false)->after('description');
            $table->string('color')->nullable()->after('is_system');
            $table->unsignedInteger('sort_order')->default(0)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label_ar', 'description', 'is_system', 'color', 'sort_order']);
        });
    }
};