<?php

use App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments;
use App\Models\SponsorshipPayment;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Payments\PaymentAnalytics;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E32 (AK14 + AK15) - الخصوصية (لا بريد/إثبات/ملاحظة إدارية/مرجع خارجي علنًا) والأداء (استعلامات ثابتة، ترقيم صفحات، لا N+1).
*/

beforeEach(fn () => e32_seed());

/** عدد استعلامات قاعدة البيانات أثناء تنفيذ $fn. */
function e32_queries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ------------------------------------------------------------------ AK14: الخصوصية

test('E32-105: a contact email is never public - no email column exists, and pages never print any user email', function () {
    expect(Schema::hasColumn('sponsorship_payments', 'email'))->toBeFalse()->and(Schema::hasColumn('sponsorship_payments', 'guest_email'))->toBeFalse();

    $program = e32_program(['title' => 'برنامج الإيميل']);
    $user = e16User(['email' => 'private-contact-e32@example.test']);
    $payment = e32_verified($user, $program, '33', false);

    $pages = [route('supporters.index'), route('support.index'), route('support.program', $program->slug), route('supporters.show', SupporterProfile::query()->findOrFail($payment->supporter_profile_id)->slug)];
    foreach ($pages as $url) {
        expect($this->get($url)->assertOk()->getContent())->not->toContain('private-contact-e32@example.test');
    }

    foreach ([route('support.payments.show', $payment->public_id), route('support.history')] as $url) {
        expect($this->actingAs($user)->get($url)->assertOk()->getContent())->not->toContain('private-contact-e32@example.test');
    }
});

test('E32-106: the proof is never public - not on pages, not in serialization, not in notifications', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $fresh = $payment->fresh();
    $secrets = [$fresh->proof_path, $fresh->proof_sha256, basename((string) $fresh->proof_path)];

    $pages = [route('supporters.index'), route('support.index'), route('support.program', $payment->program->slug)];
    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($secrets as $secret) {
            expect(str_contains($html, (string) $secret))->toBeFalse("public page leaked proof detail at {$url}");
        }
    }

    $own = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->getContent();
    foreach ($secrets as $secret) {
        expect(str_contains($own, (string) $secret))->toBeFalse('owner page exposes the stored proof path/hash');
    }

    $json = json_encode($fresh->toArray());
    foreach (['proof_path', 'proof_sha256', 'review_note', 'request_key', 'provider_reference', 'failure_code'] as $hidden) {
        expect(str_contains((string) $json, $hidden))->toBeFalse("model serialization exposes {$hidden}");
    }

    e32_payments()->verify(e32_finance(), $payment);
    expect(json_encode(e32_notes($user)->all(), JSON_UNESCAPED_UNICODE))->not->toContain((string) $fresh->proof_path);
});

test('E32-107: the internal reviewer note is never shown to the owner, the public, or in notifications', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    e32_payments()->reject(e32_finance(), $payment, 'suspected_fraud', 'تعذّر قبول الطلب', 'ملاحظة-مراجع-داخلية-فريدة');

    $own = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->getContent();
    $history = $this->get(route('support.history'))->assertOk()->getContent();
    $public = $this->get(route('support.program', $payment->program->slug))->assertOk()->getContent();

    foreach ([$own, $history, $public, json_encode(e32_notes($user)->all(), JSON_UNESCAPED_UNICODE)] as $surface) {
        expect(str_contains((string) $surface, 'ملاحظة-مراجع-داخلية-فريدة'))->toBeFalse()->and(str_contains((string) $surface, 'suspected_fraud'))->toBeFalse();
    }
});

test('E32-108: the external/transfer reference is not exposed publicly even for a named supporter', function () {
    $program = e32_program(['title' => 'برنامج المرجع', 'show_public_amounts' => true]);
    $payment = e32_verified(e16User(['name' => 'داعم باسمه']), $program, '45', false);
    $contribution = e32_contribution($payment);
    $profile = SupporterProfile::query()->findOrFail($payment->supporter_profile_id);

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('support.index'), route('support.program', $program->slug), route('supporters.show', $profile->slug)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ([$payment->reference, $contribution->external_reference, $payment->public_id, $contribution->public_id] as $needle) {
            expect(str_contains($html, (string) $needle))->toBeFalse("reference leak '{$needle}' at {$url}");
        }
    }

    $this->get(route('supporters.show', $profile->slug))->assertSee('داعم باسمه');
});

test('E32-109: another user cannot learn that a payment exists - ids are not enumerable and responses are identical', function () {
    $payment = e32_request();
    $this->actingAs(e16User());

    $real = $this->get(route('support.payments.show', $payment->public_id))->getStatusCode();
    $fake = $this->get(route('support.payments.show', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->getStatusCode();
    $bad = $this->get('/support/payments/zzz')->getStatusCode();

    expect($real)->toBe(404)->and($fake)->toBe(404)->and($bad)->toBe(404);
});

test('E32-101b: community puzzles and creator series are not sponsorship targets - there is no checkout for them', function () {
    $work = e28_published(e28_creator(), 'text');

    foreach (['community_puzzle', 'creator_series', 'community'] as $target) {
        expect(fn () => app(SponsorshipProgramService::class)->create(e30_admin(), ['title' => 'برنامج '.$target, 'target_type' => $target, 'target_id' => $work->id]))->toThrow(SponsorshipException::class);
    }

    expect(\App\Models\SponsorshipProgram::query()->count())->toBe(0);
});

test('E32-110: sensitive settings are refused - credentials and card numbers cannot be stored as payment instructions', function () {
    foreach (['كلمة المرور: abc123', 'password=hunter2', 'PIN 4321', 'CVV 123', 'secret key sk_live_abcdef', '4111 1111 1111 1111', 'private key -----BEGIN'] as $bad) {
        expect(\App\Services\Payments\PaymentSettingsGuard::violations(['manual_account' => $bad]))->not->toBe([], "accepted: {$bad}");
    }

    expect(\App\Services\Payments\PaymentSettingsGuard::violations(['manual_account' => 'TR00 0000 0000 0000 0000 0000 00', 'manual_beneficiary' => 'جمعية أحجيات']))->toBe([]);
});

// ------------------------------------------------------------------ AK15: الأداء

test('E32-111: the support page is bounded - constant query count and at most 24 program cards', function () {
    $few = e32_program();
    e32_verified(null, $few, '10');
    $this->get(route('support.index'))->assertOk();   // تسخين: كاش الإعدادات/الأدوار يُحمَّل مرة واحدة
    $small = e32_queries(fn () => $this->get(route('support.index'))->assertOk());

    for ($i = 0; $i < 30; $i++) {
        e32_program(['title' => 'برنامج '.$i]);
    }
    $big = e32_queries(fn () => $this->get(route('support.index'))->assertOk());
    $html = $this->get(route('support.index'))->getContent();

    expect($big)->toBe($small)->and(substr_count($html, 'href="'.url('/support/').'/'))->toBeLessThanOrEqual(24 * 3);
});

test('E32-112: the checkout page is bounded - query count does not grow with existing payments', function () {
    $user = e16User();
    $program = e32_program();
    $this->actingAs($user);
    $this->get(route('support.checkout', $program->slug))->assertOk();   // تسخين
    $before = e32_queries(fn () => $this->get(route('support.checkout', $program->slug))->assertOk());

    config(['payments.checkout.max_open_requests_per_user' => 100]);
    for ($i = 0; $i < 25; $i++) {
        e32_request($user, $program, (string) (5 + $i));
    }
    $after = e32_queries(fn () => $this->get(route('support.checkout', $program->slug))->assertOk());

    expect($after)->toBe($before);
});

test('E32-113: the history is paginated (15 per page) and has no N+1 on the program relation', function () {
    config(['payments.checkout.max_open_requests_per_user' => 100]);
    $user = e16User();
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_request($user, e32_program(['title' => 'برنامج '.$i]), (string) (5 + $i));
    }
    $this->actingAs($user);
    $this->get(route('support.history'))->assertOk();   // تسخين
    $small = e32_queries(fn () => $this->get(route('support.history'))->assertOk());

    for ($i = 0; $i < 37; $i++) {
        e32_request($user, e32_program(['title' => 'برنامج إضافي '.$i]), (string) (5 + $i));
    }
    $big = e32_queries(fn () => $this->get(route('support.history'))->assertOk());
    $page1 = $this->get(route('support.history'))->getContent();
    $page3 = $this->get(route('support.history', ['page' => 3]))->assertOk()->getContent();

    expect($big)->toBe($small)->and(preg_match_all('/SUP-[A-Z2-9]{8}/', $page1))->toBeLessThanOrEqual(15 * 2)->and(preg_match_all('/SUP-[A-Z2-9]{8}/', $page3))->toBeGreaterThan(0);
    expect(SponsorshipPayment::query()->where('user_id', $user->id)->count())->toBe(40);
});

test('E32-114: the admin queue is paginated and the finance dashboard uses a bounded number of queries', function () {
    config(['payments.checkout.max_open_requests_per_user' => 100]);
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_submitted(null, $program, (string) (5 + $i));
    }
    $analytics = app(PaymentAnalytics::class);
    $small = e32_queries(fn () => $analytics->summary(true));

    for ($i = 0; $i < 45; $i++) {
        e32_request(null, $program, (string) (5 + $i));
    }
    $big = e32_queries(fn () => $analytics->summary(true));
    expect($big)->toBe($small)->and($big)->toBeLessThanOrEqual(6);

    $summary = $analytics->summary(true);
    expect($summary['awaiting_verification'])->toBe(3)->and($summary['pending'])->toBe(45)->and($summary['confirmed_total_minor'])->toBe(0);

    $component = Livewire::actingAs(e32_finance())->test(ListSponsorshipPayments::class)->assertSee('طلبات الدعم');
    expect($component->instance()->getAllTableRecordsCount())->toBe(48)->and($component->instance()->getTableRecords())->toHaveCount(20);
});

test('E32-115: no N+1 for the supporter/program/user relations in the admin queue (query count is flat in rows)', function () {
    $finance = e32_finance();
    $program = e32_program();
    for ($i = 0; $i < 3; $i++) {
        e32_verified(null, $program, (string) (5 + $i), $i % 2 === 0);
    }
    Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful();   // تسخين
    $small = e32_queries(fn () => Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful());

    config(['payments.checkout.max_open_requests_per_user' => 100]);
    for ($i = 0; $i < 15; $i++) {
        e32_verified(null, e32_program(['title' => 'برنامج '.$i]), (string) (5 + $i), $i % 2 === 0);
    }
    $big = e32_queries(fn () => Livewire::actingAs($finance)->test(ListSponsorshipPayments::class)->assertSuccessful());

    expect($big)->toBeLessThanOrEqual($small + 3);
});
