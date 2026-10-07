<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E21: غرفة دردشة موحَّدة بثلاثة أنواع مغلقة فقط: direct | team | global.
 *  - direct: ثنائي **قانوني** (المعرّف الأصغر = one، الأكبر = two) و direct_key "one:two" UNIQUE ← A↔B وB↔A غرفة واحدة بالقاعدة. (المستخدم المحذوف: nullOnDelete، يبقى تاريخ الطرف الآخر.)
 *  - team: team_id UNIQUE ← غرفة رئيسية واحدة لكل فريق. restrictOnDelete: التاريخ لا يُمحى بحذف فريق.
 *  - global: slug="global" UNIQUE ← غرفة عالمية واحدة.
 * last_message_id/at مخزَّنان لقوائم سريعة بلا استعلام فرعي لكل غرفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('type', 8);
            $table->foreignId('team_id')->nullable()->unique()->constrained('teams')->restrictOnDelete();
            $table->foreignId('direct_user_one_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('direct_user_two_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direct_key', 40)->nullable()->unique();
            $table->string('slug', 20)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
            $table->index('direct_user_one_id');
            $table->index('direct_user_two_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};
