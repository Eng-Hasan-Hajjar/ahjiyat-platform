<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/Value عامة - لا عمود منفصل لكل إعداد (لا Migration جديدة لإضافة
 * إعداد لاحقًا). القيمة الافتراضية لكل مفتاح تعيش بـconfig/platform.php؛
 * هذا الجدول يخزّن فقط ما "تجاوز" Admin به الافتراضي - صف غائب = استخدم
 * الافتراضي. لا Secrets هنا إطلاقًا (تبقى بـ.env دائمًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->string('key');
            $table->longText('value')->nullable();
            $table->string('type')->default('string');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};