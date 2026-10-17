<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (A) - منظومة الرعاة والداعمين وتمويل المكافآت. ستة جداول بحدّ أدنى لما يلزم فعلًا (أسماء جديدة كي لا تتصادم مع محرّك إعلانات E14: sponsor_campaigns/sponsor_creatives):
 *
 *  supporter_tiers              مستويات الداعمين (تُدار من قاعدة البيانات، تُشتقّ منها مستويات الداعم من صافي دعمه المؤكَّد؛ لا قيمة مخزَّنة بالملف).
 *  supporter_profiles           ملف داعم (فرد/مؤسسة) علني اختياريًا؛ user_id اختياري (فريد). لا تفاصيل دفع هنا أبدًا.
 *  sponsorship_programs         برنامج رعاية لهدف رسمي (platform/season/campaign/competitive_event) + خطة تعزيز المكافآت.
 *  sponsorship_program_roles    دور الداعم ضمن البرنامج (supporter/sponsor/main_sponsor). main_marker فريد ⇒ راعٍ رئيسي واحد لكل برنامج بقيد قاعدة البيانات.
 *  sponsorship_contributions    المساهمة: المبلغ الأصلي + عملته + المعادل المحاسبي المسجَّل عند التأكيد. المبالغ أعداد صحيحة (وحدة صغرى)؛ لا floats.
 *  sponsorship_reward_bonuses   دفتر مكافآت الرعاية الممنوحة فعلًا (مفتاح مصدر فريد ⇒ لا منح مزدوج) بلقطة (الأساس/النقاط الأساسية/المكافأة/البرنامج).
 *  sponsorship_event_reward_plans  خطة تعزيز منافسة مقفلة قبل بدئها (لقطة التمويل والنسبة): المساهمات المتأخرة لا تغيّرها.
 *
 * لا حذف تتابعي للتاريخ المالي: contributions → profiles/programs restrictOnDelete. أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supporter_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 40)->unique();
            $table->unsignedBigInteger('minimum_accounting_amount_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('badge_key', 24)->default('supporter');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'minimum_accounting_amount_minor'], 'supporter_tiers_active_min_index');
        });

        Schema::create('supporter_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('individual');
            $table->string('display_name', 120);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('website_url')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_public')->default(true);
            $table->boolean('anonymous_by_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'is_public', 'type'], 'supporter_profiles_public_index');
        });

        Schema::create('sponsorship_programs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('goal_amount_minor')->nullable();
            $table->char('accounting_currency', 3);
            $table->boolean('show_public_amounts')->default(false);
            $table->boolean('show_goal_progress')->default(false);

            // خطة تعزيز المكافآت (منطقها كله في SponsorshipRewardService). 100 bps = 1%.
            $table->boolean('reward_boost_enabled')->default(false);
            $table->unsignedBigInteger('funding_step_minor')->nullable();
            $table->unsignedInteger('boost_per_step_bps')->default(0);
            $table->unsignedInteger('max_boost_bps')->default(0);
            $table->timestamp('reward_plan_locked_at')->nullable();
            $table->foreignId('reward_plan_locked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'target_type', 'target_id'], 'sponsorship_programs_target_index');
            $table->index(['target_type', 'target_id'], 'sponsorship_programs_target_lookup_index');
            $table->index(['status', 'reward_boost_enabled', 'target_type', 'target_id'], 'sponsorship_programs_boost_index');
        });

        Schema::create('sponsorship_program_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->cascadeOnDelete();
            $table->foreignId('supporter_profile_id')->constrained('supporter_profiles')->cascadeOnDelete();
            $table->string('role', 16)->default('supporter');
            // = معرّف البرنامج للراعي الرئيسي وإلا NULL: فريد ⇒ راعٍ رئيسي واحد لكل برنامج (NULL لا تتعارض).
            $table->unsignedBigInteger('main_marker')->nullable()->unique('sponsorship_roles_main_unique');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sponsorship_program_id', 'supporter_profile_id'], 'sponsorship_roles_pair_unique');
            $table->index('supporter_profile_id', 'sponsorship_roles_supporter_index');
        });

        Schema::create('sponsorship_contributions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('supporter_profile_id')->constrained('supporter_profiles')->restrictOnDelete();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();

            $table->unsignedBigInteger('original_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('accounting_amount_minor')->nullable(); // يُسجَّل عند التأكيد
            $table->char('accounting_currency', 3)->nullable();

            $table->string('status', 16)->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->string('reference', 120)->nullable();
            $table->string('provider', 32)->nullable();            // مستقبلًا: مزوّد دفع خارجي (غير مستعمل الآن)
            $table->string('external_reference', 120)->nullable(); // مستقبلًا: مرجع المزوّد
            $table->text('notes')->nullable();                     // ملاحظات إدارية خاصة: لا تُعرض للعامة أبدًا

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_note', 255)->nullable();
            $table->timestamps();

            // لوحات الصدارة/المجاميع: صافي المؤكَّد بتجميع واحد مغطّى بالفهرس.
            $table->index(['status', 'supporter_profile_id', 'is_anonymous', 'accounting_amount_minor'], 'sponsorship_contrib_leaderboard_index');
            $table->index(['sponsorship_program_id', 'status', 'supporter_profile_id'], 'sponsorship_contrib_program_index');
            $table->index(['status', 'confirmed_at'], 'sponsorship_contrib_period_index');
            $table->index(['supporter_profile_id', 'status'], 'sponsorship_contrib_supporter_index');
            $table->unique(['provider', 'external_reference'], 'sponsorship_contrib_provider_ref_unique');
        });

        Schema::create('sponsorship_reward_bonuses', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 160)->unique();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_type', 24)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedBigInteger('base_amount');
            $table->unsignedInteger('boost_bps');
            $table->unsignedBigInteger('calculated_bonus_amount'); // قبل قصّ السقف اليومي
            $table->unsignedBigInteger('bonus_amount');            // ما مُنح فعلًا
            $table->unsignedBigInteger('final_amount');            // الأساس + المكافأة
            $table->unsignedBigInteger('funding_snapshot_minor');
            $table->unsignedBigInteger('currency_transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sponsorship_program_id', 'created_at'], 'sponsorship_bonus_program_index');
            $table->index(['user_id', 'created_at'], 'sponsorship_bonus_user_index');
        });

        Schema::create('sponsorship_event_reward_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->unique('sponsorship_event_plans_event_unique')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('sponsorship_program_id')->nullable()->constrained('sponsorship_programs')->nullOnDelete();
            $table->unsignedBigInteger('funding_snapshot_minor')->default(0);
            $table->unsignedBigInteger('funding_step_minor')->default(0);
            $table->unsignedInteger('boost_bps')->default(0);
            $table->timestamp('locked_at');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // المستويات الافتراضية (قابلة للتعديل كليًا من الإدارة). العتبات بالوحدة الصغرى لعملة المحاسبة.
        $now = now();
        foreach ([
            ['داعم', 'supporter', 500, 10, 'supporter'],
            ['فضي', 'silver', 5000, 20, 'silver'],
            ['ذهبي', 'gold', 25000, 30, 'gold'],
            ['بلاتيني', 'platinum', 100000, 40, 'platinum'],
            ['ماسي', 'diamond', 500000, 50, 'diamond'],
        ] as [$name, $slug, $min, $sort, $badge]) {
            DB::table('supporter_tiers')->insertOrIgnore([
                'name' => $name, 'slug' => $slug, 'minimum_accounting_amount_minor' => $min, 'sort_order' => $sort,
                'badge_key' => $badge, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_event_reward_plans');
        Schema::dropIfExists('sponsorship_reward_bonuses');
        Schema::dropIfExists('sponsorship_contributions');
        Schema::dropIfExists('sponsorship_program_roles');
        Schema::dropIfExists('sponsorship_programs');
        Schema::dropIfExists('supporter_profiles');
        Schema::dropIfExists('supporter_tiers');
    }
};
