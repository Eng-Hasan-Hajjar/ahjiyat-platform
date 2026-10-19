<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - حملات الدعوة (إعدادات فقط؛ لا تاريخ مالي هنا).
 *
 * حدود السلامة في قاعدة البيانات:
 *  - slug UNIQUE            ⇒ ?c=slug يشير إلى حملة واحدة.
 *  - default_slot UNIQUE    ⇒ حملة افتراضية واحدة كحدّ أقصى. القيمة 1 للحملة الافتراضية و NULL لغيرها (NULL متعدد مسموح على SQLite وMySQL)، فلا حاجة لفهرس جزئي.
 *  - لا قيم مبالغ عشرية: الجواهر أعداد صحيحة غير سالبة (أعمدة unsigned).
 * سياسة الحذف: الموسم/المنشئ SET NULL (حذفهما لا يحذف الحملة ولا يكسر الإحالات التاريخية).
 * اللقطة المالية للإحالة تُنسخ إلى user_referrals عند التأهيل، فتعديل الحملة لاحقًا لا يغيّر تاريخًا (انظر الهجرة الثالثة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_campaigns', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('draft'); // draft | active | paused | ended
            $table->boolean('is_public')->default(true);
            $table->unsignedTinyInteger('default_slot')->nullable()->unique('referral_campaigns_default_slot_unique');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();

            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // NULL = قيمة config

            $table->boolean('rewards_enabled')->default(false);
            $table->unsignedInteger('referrer_reward_gems')->default(0);
            $table->unsignedInteger('referred_reward_gems')->default(0);
            $table->unsignedSmallInteger('daily_cap')->nullable();    // عدد الإحالات المكافَأة لكل محيل؛ NULL = قيمة config
            $table->unsignedSmallInteger('monthly_cap')->nullable();
            $table->unsignedInteger('lifetime_cap')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'referral_campaigns_status_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_campaigns');
    }
};
