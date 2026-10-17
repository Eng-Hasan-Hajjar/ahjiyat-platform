<?php

use App\Models\Campaign;
use App\Models\SponsorshipProgramRole;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM4-31: a supporter with a confirmed contribution can become the main sponsor', function () {
    $program = e30_program();
    $company = e30_company();
    e30_confirmed($company, $program, '500');

    $role = e30_role($program, $company, 'main_sponsor');

    expect($role->role)->toBe('main_sponsor')->and($role->main_marker)->toBe($program->id);
});

test('AM4-32: a supporter without a confirmed contribution (pending, refunded or none) cannot be a sponsor', function () {
    $program = e30_program();
    $none = e30_company();
    $pending = e30_company();
    e30_pending($pending, $program, '900');
    $refunded = e30_company();
    e30_refund(e30_confirmed($refunded, $program, '900'));

    foreach ([$none, $pending, $refunded] as $supporter) {
        expect(fn () => e30_role($program, $supporter, 'main_sponsor'))->toThrow(SponsorshipException::class, 'مؤكَّدة');
        expect(fn () => e30_role($program, $supporter, 'sponsor'))->toThrow(SponsorshipException::class);
    }

    expect(SponsorshipProgramRole::query()->count())->toBe(0);
});

test('AM4-33: only one main sponsor per program (service demotes the previous one; the database also enforces it)', function () {
    $program = e30_program();
    $a = e30_company();
    $b = e30_company();
    e30_confirmed($a, $program, '100');
    e30_confirmed($b, $program, '900');

    e30_role($program, $a, 'main_sponsor');
    e30_role($program, $b, 'main_sponsor');

    expect(SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(1)
        ->and(SponsorshipProgramRole::query()->where('supporter_profile_id', $a->id)->value('role'))->toBe('sponsor');

    expect(fn () => DB::table('sponsorship_program_roles')->insert([
        'sponsorship_program_id' => $program->id, 'supporter_profile_id' => $a->id + 1000, 'role' => 'main_sponsor', 'main_marker' => $program->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('AM4-34: role assignment and removal are audited', function () {
    $program = e30_program();
    $a = e30_company();
    $b = e30_company();
    e30_confirmed($a, $program, '100');
    e30_confirmed($b, $program, '100');

    e30_role($program, $a, 'main_sponsor');
    e30_role($program, $b, 'sponsor');
    app(SponsorshipProgramService::class)->removeRole(e30_admin(), $program, $b);

    $actions = DB::table('operational_audit_logs')->pluck('action')->all();
    expect($actions)->toContain('main_sponsor_assigned')->toContain('sponsorship_role_assigned')->toContain('sponsorship_role_removed');
});

test('AM4-35: the main sponsor appears on the target page (season) with the Main Sponsor label', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $company = e30_company(['display_name' => 'شركة الراعي الذهبية']);
    e30_confirmed($company, $program, '800');
    e30_role($program, $company, 'main_sponsor');

    $this->get(route('seasons.show', $season))->assertOk()->assertSee('data-main-sponsor', false)->assertSee('شركة الراعي الذهبية')->assertSee('الراعي الرئيسي')->assertSee('برعاية');
});

test('AM4-36: supporting sponsors appear alongside the main sponsor, on campaign pages too', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $program = e30_program(['target_type' => 'campaign', 'target_id' => $campaign->id]);
    $main = e30_company(['display_name' => 'الراعي الأول']);
    $second = e30_company(['display_name' => 'الراعي الثاني']);
    e30_confirmed($main, $program, '800');
    e30_confirmed($second, $program, '80');
    e30_role($program, $main, 'main_sponsor');
    e30_role($program, $second, 'sponsor');

    $this->get(route('campaigns.show', $campaign))->assertOk()->assertSee('الراعي الأول')->assertSee('الراعي الثاني')->assertSee('data-sponsor', false);
});

test('AM4-37: hidden, suspended, anonymous-only or refunded sponsors are excluded; a refund also drops the role', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $private = e30_company(['display_name' => 'راعٍ خاص']);
    $suspended = e30_company(['display_name' => 'راعٍ موقوف']);
    $anonymous = e30_company(['display_name' => 'راعٍ مجهول الهوية']);
    $refunded = e30_company(['display_name' => 'راعٍ مسترد']);
    $refundContribution = e30_confirmed($refunded, $program, '200');

    foreach ([$private, $suspended, $refunded] as $s) {
        e30_confirmed($s, $program, '300');
    }
    e30_confirmed($anonymous, $program, '300', ['is_anonymous' => true]);
    foreach ([$private, $suspended, $anonymous, $refunded] as $s) {
        e30_role($program, $s, 'sponsor');
    }

    app(SupporterService::class)->update(e30_admin(), $private, ['is_public' => false]);
    app(SupporterService::class)->setStatus(e30_admin(), $suspended, 'suspended');
    foreach (\App\Models\SponsorshipContribution::query()->where('supporter_profile_id', $refunded->id)->get() as $c) {
        e30_refund($c);
    }

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    foreach (['راعٍ خاص', 'راعٍ موقوف', 'راعٍ مجهول الهوية', 'راعٍ مسترد'] as $name) {
        expect($html)->not->toContain($name);
    }
    expect(SponsorshipProgramRole::query()->where('supporter_profile_id', $refunded->id)->exists())->toBeFalse();
    expect($refundContribution->fresh()->status)->toBe('refunded');
});
