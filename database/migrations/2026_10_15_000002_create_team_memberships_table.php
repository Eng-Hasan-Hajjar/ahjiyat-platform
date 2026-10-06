<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E19-A: العضوية. UNIQUE(user_id): **فريق واحد لكل مستخدم على مستوى قاعدة البيانات** (يحميه من سباق انضمامين متزامنين). UNIQUE(team_id, user_id) كما طُلب.
 * owner_team_id UNIQUE ولا يُملأ إلا لصف المالك (= team_id): مالك واحد لكل فريق بقيد حقيقي. role: owner|admin|member (تعداد مغلق، لا محرك صلاحيات).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 8)->default('member');
            $table->unsignedBigInteger('owner_team_id')->nullable()->unique();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['team_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
    }
};
