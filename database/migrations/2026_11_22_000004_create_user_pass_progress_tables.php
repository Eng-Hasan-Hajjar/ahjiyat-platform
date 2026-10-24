<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E40 (4/4) - تقدّم اللاعب في المسار (user_pass_progress)، دفتر نقاط المسار (pass_point_grants)، ومطالبات الجوائز (user_pass_reward_claims).
|
|  user_pass_progress
|     صف كسول واحد لكل (مستخدم، مسار): لا إنشاء جماعي عند تفعيل المسار. pass_points وhighest_tier **لقطة مشتقة** من دفتر النقاط وعتبات الشرائح وتُعيد ahjiyat-pass:reconcile مقارنتها.
|     **سلطة المميز الوحيدة** = premium_unlocked_at IS NOT NULL (تُكتب فقط من خدمة التحقق تحت قفل، وتُمحى فقط من خدمة الاسترداد)، وpremium_purchase_id يشير للشراء المحقَّق (RESTRICT).
|  pass_point_grants
|     دفتر نقاط **إضافة فقط**. source_key فريد = «pass:{pass}:u:{user}:uqp:{user_quest_progress}»: إكمال المهمة نفسه لا يمنح مرتين مهما تكرر الاستدعاء أو تزامن. لا تعديل ولا حذف.
|  user_pass_reward_claims
|     مطالبة واحدة لكل (مستخدم، مكافأة شريحة): unique يجعل النقر المزدوج/التزامن آمنًا. لقطات الاسم والشريحة والمسار تبقى سليمة مهما تغيّر التعريف. يربط المنح الفعلي (حركة مخزون أو امتياز) للمطابقة.
|  الحذف: بيانات اللاعب الشخصية (تقدّم/دفتر/مطالبات) تُحذف تتابعيًا مع حذف المستخدم (حق المحو) ما لم يمنعه سجل مالي (digital_purchases بـRESTRICT)؛ التعريفات (المسار/الشرائح/المكافآت) RESTRICT من الجهة التاريخية فلا تختفي بتغيير الإعداد.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_pass_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->restrictOnDelete();
            $table->unsignedInteger('pass_points')->default(0);
            $table->unsignedSmallInteger('highest_tier')->default(0);
            $table->timestamp('premium_unlocked_at')->nullable();
            $table->foreignId('premium_purchase_id')->nullable()->constrained('digital_purchases')->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ahjiyat_pass_id'], 'user_pass_progress_user_pass_unique');
            $table->index(['ahjiyat_pass_id', 'highest_tier'], 'user_pass_progress_pass_tier_index');
            $table->index(['ahjiyat_pass_id', 'premium_unlocked_at'], 'user_pass_progress_pass_premium_index');
        });

        Schema::create('pass_point_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_pass_progress_id')->constrained('user_pass_progress')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->restrictOnDelete();
            $table->foreignId('ahjiyat_pass_mission_id')->nullable()->constrained('ahjiyat_pass_missions')->restrictOnDelete();
            $table->foreignId('user_quest_progress_id')->nullable()->constrained('user_quest_progress')->nullOnDelete();
            $table->string('source_type', 16)->default('mission');
            $table->string('source_key', 120)->unique('pass_point_grants_source_key_unique');
            $table->unsignedInteger('points');
            $table->timestamp('created_at');

            $table->index(['user_pass_progress_id', 'created_at'], 'pass_point_grants_progress_created_index');
            $table->index(['ahjiyat_pass_id', 'ahjiyat_pass_mission_id'], 'pass_point_grants_pass_mission_index');
        });

        Schema::create('user_pass_reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_pass_progress_id')->constrained('user_pass_progress')->cascadeOnDelete();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->restrictOnDelete();
            $table->foreignId('ahjiyat_pass_tier_reward_id')->constrained('ahjiyat_pass_tier_rewards')->restrictOnDelete();
            $table->foreignId('store_item_id')->constrained('store_items')->restrictOnDelete();
            $table->string('track', 8);
            $table->unsignedSmallInteger('tier_number');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('item_name', 160);
            $table->string('source', 12)->default('manual');
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->foreignId('user_entitlement_id')->nullable()->constrained('user_entitlements')->nullOnDelete();
            $table->timestamp('claimed_at');

            $table->unique(['user_id', 'ahjiyat_pass_tier_reward_id'], 'user_pass_claims_user_reward_unique');
            $table->index(['user_pass_progress_id', 'claimed_at'], 'user_pass_claims_progress_index');
            $table->index(['ahjiyat_pass_id', 'track'], 'user_pass_claims_pass_track_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_pass_reward_claims');
        Schema::dropIfExists('pass_point_grants');
        Schema::dropIfExists('user_pass_progress');
    }
};
