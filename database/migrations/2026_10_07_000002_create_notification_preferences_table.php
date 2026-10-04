<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E15: تفضيلات إشعارات اللاعب - صف واحد لكل مستخدم (unique)، غياب الصف = الافتراضيات
 * (الكل مفعَّل). أعمدة صريحة لا JSON ضخم. إشعارات الأمان إلزامية فلا عمود لها عمدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quest_enabled')->default(true);
            $table->boolean('streak_enabled')->default(true);
            $table->boolean('achievement_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
