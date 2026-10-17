<?php

use App\Filament\Resources\SponsorshipContributionResource\Pages\ListSponsorshipContributions;
use App\Filament\Resources\SponsorshipProgramResource\Pages\ListSponsorshipPrograms;
use App\Filament\Resources\SupporterProfileResource\Pages\CreateSupporterProfile;
use App\Filament\Resources\SupporterProfileResource\Pages\EditSupporterProfile;
use App\Filament\Resources\SupporterProfileResource\Pages\ListSupporterProfiles;
use App\Filament\Resources\SupporterTierResource\Pages\EditSupporterTier;
use App\Filament\Resources\SupporterTierResource\Pages\ListSupporterTiers;
use App\Models\SponsorshipContribution;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e30_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM14-129: sponsorship admin pages respect permissions (view / manage / confirm / reward_plan are separate)', function () {
    $admin = e30_admin();
    $manager = e30_manager();
    $moderator = e28_reviewer('moderator');
    $player = e16User();

    foreach ([$admin, $manager] as $allowed) {
        $this->flushSession(); // AuthenticateSession يقارن بصمة كلمة المرور المخزنة بالجلسة: تبديل المستخدم داخل اختبار واحد يتطلب جلسة نظيفة
        $this->actingAs($allowed);
        foreach (['supporter-profiles', 'sponsorship-programs', 'sponsorship-contributions', 'supporter-tiers'] as $path) {
            $this->get("/admin/{$path}")->assertOk();
        }
    }

    foreach ([$moderator, $player] as $denied) {
        $this->flushSession();
        $this->actingAs($denied);
        expect(\App\Filament\Resources\SupporterProfileResource::canViewAny())->toBeFalse()->and(\App\Filament\Resources\SponsorshipContributionResource::canViewAny())->toBeFalse();
        foreach (['supporter-profiles', 'sponsorship-programs', 'sponsorship-contributions', 'supporter-tiers'] as $path) {
            $this->get("/admin/{$path}")->assertForbidden();
        }
    }

    expect($manager->can('sponsorships.manage'))->toBeTrue()->and($manager->can('sponsorships.confirm'))->toBeFalse()->and($manager->can('sponsorships.reward_plan'))->toBeFalse()
        ->and($admin->can('sponsorships.confirm'))->toBeTrue()->and($admin->can('sponsorships.reward_plan'))->toBeTrue();
});

test('AM14-129b: only a financial role confirms, cancels or refunds; a content manager sees those actions hidden', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '25');
    $confirmed = e30_confirmed($supporter, $program, '10');

    $this->actingAs(e30_manager());
    Livewire::test(ListSponsorshipContributions::class)->assertSuccessful()
        ->assertTableActionHidden('confirm', $pending)->assertTableActionHidden('cancel', $pending)->assertTableActionHidden('refund', $confirmed);

    auth()->logout();
    $this->actingAs(e30_admin());
    Livewire::test(ListSponsorshipContributions::class)->assertTableActionVisible('confirm', $pending)->callTableAction('confirm', $pending)->assertHasNoTableActionErrors();
    expect($pending->fresh()->status)->toBe('confirmed');

    Livewire::test(ListSponsorshipContributions::class)->callTableAction('refund', $confirmed, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(ListSponsorshipContributions::class)->callTableAction('refund', $confirmed, ['reason' => 'طلب الداعم الاسترداد'])->assertHasNoTableActionErrors();
    expect($confirmed->fresh()->status)->toBe('refunded');
});

test('AM14-129c: a content manager records a pending contribution from the page; amounts are validated', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $this->actingAs(e30_manager());

    Livewire::test(ListSponsorshipContributions::class)->callAction('record', ['supporter_id' => $supporter->id, 'program_id' => $program->id, 'amount' => '25.50', 'currency' => 'USD', 'is_anonymous' => true, 'notes' => 'ملاحظة خاصة'])->assertHasNoActionErrors();
    $row = SponsorshipContribution::query()->first();
    expect($row->status)->toBe('pending')->and($row->original_amount_minor)->toBe(2550)->and($row->is_anonymous)->toBeTrue();

    Livewire::test(ListSponsorshipContributions::class)->callAction('record', ['supporter_id' => $supporter->id, 'program_id' => $program->id, 'amount' => '-3', 'currency' => 'USD']);
    expect(SponsorshipContribution::query()->count())->toBe(1);
});

test('AM14-129d: programs - activate, roles and the reward plan are gated by their own permissions', function () {
    $program = e30_program([], false);
    $supporter = e30_company();

    $this->actingAs(e30_manager());
    Livewire::test(ListSponsorshipPrograms::class)->assertSuccessful()->assertTableActionVisible('activate', $program)->assertTableActionHidden('reward_plan', $program)->assertTableActionHidden('lock_plan', $program);

    auth()->logout();
    $this->actingAs(e30_admin());
    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('activate', $program)->assertHasNoTableActionErrors();
    expect($program->fresh()->status)->toBe('active');
    e30_confirmed($supporter, $program->fresh(), '90');

    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('assign_role', $program, ['supporter_id' => $supporter->id, 'role' => 'main_sponsor'])->assertHasNoTableActionErrors();
    expect(\App\Models\SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(1);

    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('reward_plan', $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500])->assertHasNoTableActionErrors();
    expect($program->fresh())->reward_boost_enabled->toBeTrue()->funding_step_minor->toBe(1000);
});

test('AM1-admin: a content manager creates and edits a supporter through the service (validation, audit, safe URL)', function () {
    $this->actingAs(e30_manager());

    Livewire::test(CreateSupporterProfile::class)->fillForm(['display_name' => 'شركة المثال', 'type' => 'organization', 'website_url' => 'https://example.com', 'is_public' => true, 'status' => 'active'])->call('create')->assertHasNoFormErrors();
    $profile = SupporterProfile::query()->where('display_name', 'شركة المثال')->first();
    expect($profile)->not->toBeNull()->and($profile->type)->toBe('organization');

    Livewire::test(CreateSupporterProfile::class)->fillForm(['display_name' => 'سيئ', 'type' => 'individual', 'website_url' => 'javascript:alert(1)'])->call('create')->assertHasFormErrors(['website_url']);
    expect(SupporterProfile::query()->where('display_name', 'سيئ')->exists())->toBeFalse();

    Livewire::test(EditSupporterProfile::class, ['record' => $profile->getRouteKey()])->fillForm(['display_name' => 'شركة المثال المعدّلة', 'is_public' => false])->call('save')->assertHasNoFormErrors();
    expect($profile->fresh())->display_name->toBe('شركة المثال المعدّلة')->is_public->toBeFalse();
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_updated']);

    Livewire::test(ListSupporterProfiles::class)->assertSuccessful()->assertSee('شركة المثال المعدّلة');
});

test('AM3-admin: tiers are editable by managers, audited, and an edit takes effect immediately', function () {
    $this->actingAs(e30_manager());
    $gold = SupporterTier::query()->where('slug', 'gold')->first();

    Livewire::test(ListSupporterTiers::class)->assertSuccessful()->assertSee('ذهبي');
    Livewire::test(EditSupporterTier::class, ['record' => $gold->getKey()])->fillForm(['name' => 'ذهبي مميز', 'minimum_decimal' => '1000', 'is_active' => true])->call('save')->assertHasNoFormErrors();

    expect($gold->fresh())->name->toBe('ذهبي مميز')->minimum_accounting_amount_minor->toBe(100000);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_tier_saved']);

    $supporter = e30_supporter();
    e30_confirmed($supporter, e30_program(), '300');
    expect(app(\App\Services\Sponsorship\SupporterTierService::class)->forSupporter($supporter)?->slug)->toBe('silver');
});
