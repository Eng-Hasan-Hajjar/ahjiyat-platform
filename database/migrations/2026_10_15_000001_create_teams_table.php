<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E19-A: الفريق. name_key (الاسم مُطبَّعًا: أحرف صغيرة ومسافات مطوية) UNIQUE يمنع تكرار الاسم بصياغات مختلفة. slug UNIQUE ثابت بعد الإنشاء.
 * members_count عدّاد ذري يحمي السعة (UPDATE شرطي بجملة واحدة، لا count ثم insert). owner_id nullable بـnullOnDelete: حذف مالك يُعالَج قبله
 * (نقل الملكية أو أرشفة الفريق) فلا فريق يتيم بمفتاح معلَّق. لا أعمدة اقتصاد ولا محفظة ولا مكافأة: الفريق ليس مصدر قيمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('name_key', 80)->unique();
            $table->string('slug', 60)->unique();
            $table->string('description', 500)->nullable();
            $table->string('visibility', 10)->default('public');       // public | private
            $table->string('join_policy', 12)->default('request');     // open | request | invite_only
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('max_members')->nullable();
            $table->unsignedSmallInteger('members_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'visibility']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
