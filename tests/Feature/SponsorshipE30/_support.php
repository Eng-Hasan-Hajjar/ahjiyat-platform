<?php

use App\Models\Campaign;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;

/*
| E30 (الرعاية) - مساعدات مشتركة. كل شيء على SQLite وكاش array؛ لا شبكة ولا بوابة دفع. كل تهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات).
| تعتمد على مساعدات E28 (e28_*) و E16 (e16User).
*/

require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e30_seed')) {
    function e30_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** مدير (كل صلاحيات الرعاية). */
    function e30_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    /** مدير محتوى (عرض + إدارة بلا تأكيد مالي ولا خطة مكافآت). */
    function e30_manager(): User
    {
        $user = e16User();
        $user->assignRole('content-manager');

        return $user;
    }

    function e30_supporter(array $over = [], ?User $actor = null): SupporterProfile
    {
        return app(SupporterService::class)->create($actor ?? e30_admin(), $over + ['display_name' => 'داعم '.fake()->unique()->numerify('####'), 'type' => 'individual']);
    }

    function e30_company(array $over = []): SupporterProfile
    {
        return e30_supporter($over + ['display_name' => 'شركة '.fake()->unique()->numerify('####'), 'type' => 'organization']);
    }

    /** برنامج منصة (أو غيره) بالخدمة الرسمية؛ $activate يفعّله. */
    function e30_program(array $over = [], bool $activate = true, ?User $actor = null): SponsorshipProgram
    {
        $actor ??= e30_admin();
        $program = app(SponsorshipProgramService::class)->create($actor, $over + ['title' => 'برنامج '.fake()->unique()->numerify('####'), 'target_type' => 'platform']);

        return $activate ? app(SponsorshipProgramService::class)->activate($actor, $program) : $program;
    }

    /** تفعيل خطة التعزيز على برنامج (الخطوة بعملة المحاسبة). */
    function e30_boost(SponsorshipProgram $program, string|int $step = '100', int $perStep = 100, int $max = 1000, ?User $actor = null): SponsorshipProgram
    {
        return app(SponsorshipProgramService::class)->updateRewardPlan($actor ?? e30_admin(), $program->fresh(), ['enabled' => true, 'step' => $step, 'per_step_bps' => $perStep, 'max_bps' => $max]);
    }

    /** مساهمة معلّقة. */
    function e30_pending(SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount = '10', array $options = [], string $currency = 'USD'): SponsorshipContribution
    {
        return app(ContributionService::class)->record(e30_admin(), $supporter, $program, $amount, $currency, $options);
    }

    /** مساهمة مؤكَّدة (تسجيل ثم تأكيد). */
    function e30_confirmed(SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount = '10', array $options = []): SponsorshipContribution
    {
        $admin = e30_admin();
        $contribution = app(ContributionService::class)->record($admin, $supporter, $program, $amount, 'USD', $options);

        return app(ContributionService::class)->confirm($admin, $contribution);
    }

    function e30_refund(SponsorshipContribution $contribution, string $reason = 'استرداد تجريبي للاختبار'): SponsorshipContribution
    {
        return app(ContributionService::class)->refund(e30_admin(), $contribution->fresh(), $reason);
    }

    function e30_season(): Season
    {
        $campaign = Campaign::factory()->create(['is_active' => true]);
    
        return Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    }
    
    function e30_role(\App\Models\SponsorshipProgram $program, \App\Models\SupporterProfile $supporter, string $role)
    {
        return app(SponsorshipProgramService::class)->assignRole(e30_admin(), $program, $supporter, $role);
    }
}
