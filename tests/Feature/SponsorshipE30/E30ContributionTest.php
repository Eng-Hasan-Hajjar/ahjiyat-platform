<?php

use App\Models\SponsorshipContribution;
use App\Models\SupporterTier;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterTierService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_net(\App\Models\SupporterProfile $s): int
{
    return app(SupportTotals::class)->net($s->id);
}

test('AM2-11: a pending contribution does not count toward totals or funding', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    e30_pending($supporter, $program, '100');

    expect(e30_net($supporter))->toBe(0)->and(app(SupportTotals::class)->programFunding($program->id))->toBe(0);
});

test('AM2-12: a confirmed contribution counts (integer minor units)', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    e30_confirmed($supporter, $program, '12.50');

    expect(e30_net($supporter))->toBe(1250)->and(app(SupportTotals::class)->programFunding($program->id))->toBe(1250);
});

test('AM2-13: a cancelled contribution does not count and cannot be confirmed afterwards', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '40');
    $cancelled = app(ContributionService::class)->cancel(e30_admin(), $contribution, 'تعذّر التحقق من الدفع');

    expect($cancelled->status)->toBe('cancelled')->and(e30_net($supporter))->toBe(0);
    expect(fn () => app(ContributionService::class)->confirm(e30_admin(), $cancelled->fresh()))->toThrow(SponsorshipException::class);
});

test('AM2-14: a refunded contribution is removed from net totals but kept as history', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $a = e30_confirmed($supporter, $program, '30');
    e30_confirmed($supporter, $program, '20');
    expect(e30_net($supporter))->toBe(5000);

    e30_refund($a);

    expect(e30_net($supporter))->toBe(2000)->and(SponsorshipContribution::query()->count())->toBe(2)->and($a->fresh()->status)->toBe('refunded');
});

test('AM2-15: the amount must be a positive integer number of minor units', function (string $amount) {
    expect(fn () => e30_pending(e30_supporter(), e30_program(), $amount))->toThrow(SponsorshipException::class);
})->with(['0', '-5', '1.234', 'abc', '', '99999999999999999999']);

test('AM2-16: unsupported currencies are rejected', function () {
    expect(fn () => e30_pending(e30_supporter(), e30_program(), '10', [], 'XYZ'))->toThrow(SponsorshipException::class, 'عملة');
});

test('AM2-17: a foreign-currency contribution needs an accounting-currency equivalent on confirmation', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '100', [], 'EUR');
    $service = app(ContributionService::class);

    expect(fn () => $service->confirm(e30_admin(), $pending))->toThrow(SponsorshipException::class, 'المعادل');

    $confirmed = $service->confirm(e30_admin(), $pending->fresh(), '108.00');
    expect($confirmed->accounting_amount_minor)->toBe(10800)->and($confirmed->accounting_currency)->toBe('USD')->and($confirmed->original_amount_minor)->toBe(10000)->and($confirmed->currency)->toBe('EUR')
        ->and(e30_net($supporter))->toBe(10800);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_contribution_normalized']);
});

test('AM2-18: a public user or a content manager cannot confirm, cancel or refund', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '25');
    $service = app(ContributionService::class);
    $status = fn () => $pending->fresh()->status;

    foreach ([e16User(), e30_manager()] as $actor) {
        expect(fn () => $service->confirm($actor, $pending))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(fn () => $service->cancel($actor, $pending, 'سبب كافٍ'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    expect($status())->toBe('pending')->and(e30_net($supporter))->toBe(0);
});

test('AM2-19: confirmation is audited with actor and amounts', function () {
    $admin = e30_admin();
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = app(ContributionService::class)->record($admin, $supporter, $program, '15', 'USD');
    app(ContributionService::class)->confirm($admin, $pending);

    $row = DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_confirmed')->first();
    expect($row)->not->toBeNull()->and($row->actor_user_id)->toBe($admin->id);
    $meta = json_decode($row->metadata, true);
    expect($meta['accounting_amount_minor'])->toBe(1500)->and($meta['program'])->toBe($program->slug);
    expect(DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_recorded')->count())->toBe(1);
});

test('AM2-20: refund is audited with its reason, and requires a real reason', function () {
    $contribution = e30_confirmed(e30_supporter(), e30_program(), '15');

    expect(fn () => app(ContributionService::class)->refund(e30_admin(), $contribution, 'x'))->toThrow(SponsorshipException::class);
    e30_refund($contribution, 'طلب المتبرع الاسترداد');

    $row = DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_refunded')->first();
    expect($row)->not->toBeNull()->and(json_decode($row->metadata, true)['reason'])->toBe('طلب المتبرع الاسترداد');
});

test('AM2-21: confirmed financial fields are protected; correction is by refund, and rows are never deleted', function () {
    $contribution = e30_confirmed(e30_supporter(), e30_program(), '50');

    foreach (['original_amount_minor' => 999999, 'accounting_amount_minor' => 1, 'currency' => 'EUR', 'supporter_profile_id' => e30_supporter()->id] as $field => $value) {
        expect(fn () => $contribution->fresh()->forceFill([$field => $value])->save())->toThrow(SponsorshipException::class, 'محمية');
    }

    expect(fn () => $contribution->fresh()->delete())->toThrow(SponsorshipException::class);
    expect(fn () => $contribution->fresh()->forceFill(['status' => 'pending'])->save())->toThrow(SponsorshipException::class);
    expect($contribution->fresh()->accounting_amount_minor)->toBe(5000);
});

test('AM2-22: an anonymous contribution never leaks the supporter identity publicly, yet still counts', function () {
    $supporter = e30_supporter(['display_name' => 'اسم سري للغاية']);
    $program = e30_program();
    e30_confirmed($supporter, $program, '70', ['is_anonymous' => true]);

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();
    expect($html)->not->toContain('اسم سري للغاية')->not->toContain($supporter->slug)->toContain('داعم مجهول');
    // الدعم المجهول يُحتسب في المجموع/المستوى لكن لا يُنسب علنًا.
    expect(e30_net($supporter))->toBe(7000)->and(app(SupporterTierService::class)->forSupporter($supporter)?->slug)->toBe('silver');
    expect(SupporterTier::query()->count())->toBe(5);
});
