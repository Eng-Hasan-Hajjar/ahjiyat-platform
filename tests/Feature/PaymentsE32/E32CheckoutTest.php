<?php

use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipProgramService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E32 (AK1) - صفحة الدفع وإنشاء طلب الدعم: البرنامج من المسار فقط، المبلغ/العملة تُتحقَّق في الخادم، ولا حقل مالي أو حالة يأتي من المتصفح.
*/

beforeEach(fn () => e32_seed());

function e32_post(array $over = [], $program = null, $user = null)
{
    $program ??= e32_program();
    $user ??= e16User();

    return [test()->actingAs($user)->post(route('support.checkout.store', $program->slug), $over + ['amount' => '25', 'currency' => 'USD', 'visibility' => 'public']), $program, $user];
}

test('E32-1: an active program renders the three-step checkout for a verified user', function () {
    $program = e32_program(['title' => 'برنامج دفع نشط']);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertOk()->assertSee('برنامج دفع نشط')->assertSee('المبلغ والعملة')->assertSee('طريقة الدفع والخصوصية')->assertSee('التأكيد والتعليمات');
});

test('E32-1b: guests are sent to login and unverified users cannot reach the checkout', function () {
    $program = e32_program();

    $this->get(route('support.checkout', $program->slug))->assertRedirect(route('login'));
    $this->post(route('support.checkout.store', $program->slug), ['amount' => '10', 'currency' => 'USD', 'visibility' => 'public'])->assertRedirect(route('login'));
    $this->actingAs(\App\Models\User::factory()->unverified()->create())->get(route('support.checkout', $program->slug))->assertRedirect();
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-2: a draft program cannot be checked out', function () {
    $program = e32_program([], false);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    [$response] = e32_post([], $program);
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-3: a closed program cannot be checked out', function () {
    $program = e32_program();
    app(SponsorshipProgramService::class)->close(e30_admin(), $program);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    e32_post([], $program);
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-4: an expired program (ends_at in the past) cannot be checked out', function () {
    $program = e32_program();
    DB::table('sponsorship_programs')->where('id', $program->id)->update(['ends_at' => now()->subDay()]);

    $this->actingAs(e16User())->get(route('support.checkout', $program->slug))->assertNotFound();
    [$response] = e32_post([], $program->fresh());
    $response->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-5: the amount is required', function () {
    [$response] = e32_post(['amount' => '']);

    $response->assertSessionHasErrors('amount');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-6/7: zero and negative amounts are rejected', function () {
    foreach (['0', '0.00', '-5', '-0.01', 'abc', '1e3'] as $bad) {
        [$response] = e32_post(['amount' => $bad]);
        expect(session()->has('error') || session()->has('errors'))->toBeTrue("accepted: {$bad}");
    }

    expect(SponsorshipPayment::query()->count())->toBe(0);

    // الأرقام الهندية مقبولة عمدًا (مستخدم عربي) وتُحلَّل لقيمة صحيحة بلا floats.
    [$arabic] = e32_post(['amount' => '١٠']);
    $arabic->assertSessionHas('success');
    expect(SponsorshipPayment::query()->value('amount_minor'))->toBe(1000);
});

test('E32-8: the maximum amount is enforced and the boundary is accepted', function () {
    config(['payments.checkout.max_amount_minor' => 5000]);

    [$over] = e32_post(['amount' => '50.01']);
    $over->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);

    [$ok] = e32_post(['amount' => '50']);
    $ok->assertSessionHas('success');
    expect(SponsorshipPayment::query()->value('amount_minor'))->toBe(5000);
});

test('E32-8b: the minimum amount is enforced', function () {
    [$under] = e32_post(['amount' => '0.99']);
    $under->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-9: an unsupported currency is rejected (unknown code, and a sponsorship currency outside the payments allow-list)', function () {
    [$a] = e32_post(['currency' => 'XXX']);
    $a->assertSessionHas('error');

    [$b] = e32_post(['currency' => 'SAR']); // معرّفة بالرعاية لكنها خارج قائمة الدفع الافتراضية
    $b->assertSessionHas('error');

    config(['payments.allowed_currencies' => ['USD']]);
    [$c] = e32_post(['currency' => 'EUR']);
    $c->assertSessionHas('error');

    expect(SponsorshipPayment::query()->count())->toBe(0);
});

test('E32-10: supported currencies are accepted and the amount is stored exactly in minor units', function () {
    foreach (['USD' => '12.34', 'EUR' => '5', 'TRY' => '250.5'] as $currency => $amount) {
        [$response] = e32_post(['currency' => $currency, 'amount' => $amount]);
        $response->assertSessionHas('success');
    }

    expect(SponsorshipPayment::query()->orderBy('id')->get(['currency', 'amount_minor'])->map(fn ($p) => [$p->currency, $p->amount_minor])->all())
        ->toBe([['USD', 1234], ['EUR', 500], ['TRY', 25050]]);
});

test('E32-11: the anonymous choice is stored and no display name is kept for it', function () {
    [$response, , $user] = e32_post(['visibility' => 'anonymous', 'display_name' => 'اسم يجب ألا يُحفظ']);
    $payment = SponsorshipPayment::query()->firstOrFail();

    $response->assertRedirect(route('support.payments.show', $payment->public_id));
    expect($payment->is_anonymous)->toBeTrue()->and($payment->display_name)->toBeNull()->and($payment->user_id)->toBe($user->id)->and($payment->status)->toBe('awaiting_payment');

    [$named] = e32_post(['visibility' => 'public', 'display_name' => 'اسم عام'], null, e16User());
    expect(SponsorshipPayment::query()->latest('id')->first()->display_name)->toBe('اسم عام');
});

test('E32-12: the browser cannot set a confirmed status or link a contribution', function () {
    [$response] = e32_post(['status' => 'verified', 'verified_at' => now()->toDateTimeString(), 'sponsorship_contribution_id' => 1, 'verified_by' => 1, 'confirmed' => true]);
    $payment = SponsorshipPayment::query()->firstOrFail();

    expect($payment->status)->toBe('awaiting_payment')->and($payment->verified_at)->toBeNull()->and($payment->sponsorship_contribution_id)->toBeNull()
        ->and(\App\Models\SponsorshipContribution::query()->count())->toBe(0);
});

test('E32-13: the browser cannot set the accounting amount or any server-owned financial field', function () {
    e32_post(['accounting_amount' => '1', 'accounting_amount_minor' => 1, 'accounting_currency' => 'EUR', 'user_id' => 999, 'sponsorship_program_id' => 999, 'reference' => 'HACKED', 'public_id' => 'X', 'expires_at' => now()->addYears(5)->toDateTimeString()]);
    $payment = SponsorshipPayment::query()->firstOrFail();

    expect($payment->accounting_amount_minor)->toBeNull()->and($payment->accounting_currency)->toBeNull()->and($payment->reference)->not->toBe('HACKED')->and($payment->reference)->toStartWith('SUP-')
        ->and($payment->user_id)->not->toBe(999)->and($payment->expires_at->lessThan(now()->addDays(3)))->toBeTrue();
});

test('E32-14: the browser cannot set a supporter tier; tiers are derived only from confirmed support', function () {
    [$response, , $user] = e32_post(['tier' => 'diamond', 'supporter_tier_id' => 5, 'supporter_tier' => 'gold']);

    expect(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()->and(SponsorshipPayment::query()->firstOrFail()->supporter_profile_id)->toBeNull()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('sponsorship_payments', 'tier'))->toBeFalse();
});

test('E32-15: CSRF protects the checkout and every payment mutation route (419 without a token, nothing changes)', function () {
    $user = e16User();
    $program = e32_program();
    $payment = e32_request($user, $program);
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($user);
        $this->post(route('support.checkout.store', $program->slug), ['amount' => '10', 'currency' => 'USD', 'visibility' => 'public'])->assertStatus(419);
        $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'x'])->assertStatus(419);
        $this->post(route('support.payments.proof.upload', $payment->public_id))->assertStatus(419);
        $this->post(route('support.payments.cancel', $payment->public_id))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect(SponsorshipPayment::query()->count())->toBe(1)->and($payment->fresh()->status)->toBe('awaiting_payment');
});

test('E32-16: the checkout rate limit bites (10 per minute per user) while reads stay available', function () {
    $user = e16User();
    $program = e32_program();
    $this->actingAs($user);

    $statuses = e26_hammer($this, 'post', route('support.checkout.store', $program->slug), 12, ['amount' => '', 'currency' => 'USD', 'visibility' => 'public']);
    expect(e26_first_429($statuses))->toBe(11);

    $this->get(route('support.program', $program->slug))->assertOk();
});

test('E32-16b: a double-click with the same request key creates exactly one request', function () {
    $user = e16User();
    $program = e32_program();
    $key = (string) \Illuminate\Support\Str::ulid();

    e32_post(['request_key' => $key], $program, $user);
    e32_post(['request_key' => $key], $program, $user);

    expect(SponsorshipPayment::query()->count())->toBe(1);
});

test('E32-16c: the open-request cap blocks flooding and checkout stays closed when payments are disabled', function () {
    config(['payments.checkout.max_open_requests_per_user' => 2]);
    $user = e16User();
    $program = e32_program();

    e32_post([], $program, $user);
    e32_post([], $program, $user);
    [$third] = e32_post([], $program, $user);
    $third->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(2);

    config(['payments.enabled' => false]);
    [$off] = e32_post([], $program, e16User());
    $off->assertSessionHas('error');
    expect(SponsorshipPayment::query()->count())->toBe(2);
});
