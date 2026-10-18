<?php

use App\Models\SponsorshipPayment;
use App\Services\Payments\PaymentException;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| E32 (AK2 + AK3) - ملكية الطلب (لا IDOR) والمزوّد اليدوي: مرجع فريد، تعليمات، «حوّلت المبلغ»، وإثبات خاص بقواعد MIME صارمة.
*/

beforeEach(fn () => e32_seed());

function e32_settings(array $values): void
{
    app(PlatformSettingsService::class)->setMany('payments', $values, e30_admin());
}

test('E32-17: a user sees their own payment page (reference and status, never "paid" before verification)', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee($payment->reference)->assertDontSee('تم الدفع');
    $this->actingAs($user)->get(route('support.history'))->assertOk()->assertSee($payment->reference);

    e32_payments()->markTransferred($user, $payment, 'حوّلت');
    $this->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('طلب الدعم بانتظار التحقق')->assertDontSee('تم الدفع')->assertDontSee('تم التحقق وتأكيد دعمك');
});

test('E32-18: another user (even finance staff) cannot see, submit, upload to or cancel someone else\'s payment - always 404', function () {
    $owner = e16User();
    $payment = e32_request($owner);
    $intruder = e16User();

    foreach ([$intruder, e32_finance()] as $who) {
        $this->actingAs($who);
        $this->get(route('support.payments.show', $payment->public_id))->assertNotFound();
        $this->get(route('support.payments.proof', $payment->public_id))->assertNotFound();
        $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'اختراق'])->assertNotFound();
        $this->post(route('support.payments.proof.upload', $payment->public_id), ['proof' => e32_proof()])->assertNotFound();
        $this->post(route('support.payments.cancel', $payment->public_id))->assertNotFound();
    }

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and(fn () => e32_payments()->markTransferred($intruder, $payment, 'x'))->toThrow(PaymentException::class);

    $this->actingAs($intruder)->get(route('support.history'))->assertOk()->assertDontSee($payment->reference);
});

test('E32-19: proof ownership is enforced - only the owner downloads their proof; others get 404', function () {
    $owner = e16User();
    $payment = e32_submitted($owner);

    $this->actingAs(e16User())->get(route('support.payments.proof', $payment->public_id))->assertNotFound();

    $response = $this->actingAs($owner)->get(route('support.payments.proof', $payment->public_id))->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('E32-20: payments are addressed by a public ULID', function () {
    $payment = e32_request();

    expect($payment->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i');
    $this->actingAs($payment->user)->get('/support/payments/'.$payment->public_id)->assertOk();
});

test('E32-21: the sequential internal id is not publicly routable (owner, staff and proof routes)', function () {
    $owner = e16User();
    $payment = e32_submitted($owner);
    $this->actingAs($owner);

    $this->get('/support/payments/'.$payment->id)->assertNotFound();
    $this->get('/support/payments/'.$payment->id.'/proof')->assertNotFound();
    $this->post('/support/payments/'.$payment->id.'/cancel')->assertNotFound();
    $this->actingAs(e32_finance())->get('/payments/staff/'.$payment->id.'/proof')->assertNotFound();
    $this->get('/support/payments/not-a-ulid')->assertNotFound();
});

test('E32-22: every request gets a unique, human-safe transfer reference', function () {
    $program = e32_program();
    $refs = [];

    for ($i = 0; $i < 25; $i++) {
        $refs[] = e32_request(e16User(), $program)->reference;
    }

    expect(array_unique($refs))->toHaveCount(25);

    foreach ($refs as $ref) {
        expect($ref)->toMatch('/^SUP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/');
    }
});

test('E32-23: manual transfer instructions render for an open request only, with escaped text and no secrets', function () {
    e32_settings(['manual_beneficiary' => 'جمعية أحجيات', 'manual_bank_name' => 'بنك تجريبي', 'manual_account' => 'TEST-IBAN-0000-0000', 'manual_extra_note' => 'ملاحظة <b>غير مهرّبة</b>']);
    $user = e16User();
    $payment = e32_request($user);

    $html = $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('جمعية أحجيات')->assertSee('بنك تجريبي')->assertSee('TEST-IBAN-0000-0000')->assertSee($payment->reference)->getContent();
    expect($html)->not->toContain('<b>غير مهرّبة</b>')->toContain('&lt;b&gt;');

    e32_payments()->cancelByOwner($user, $payment);
    $this->get(route('support.payments.show', $payment->public_id))->assertOk()->assertDontSee('TEST-IBAN-0000-0000');
});

test('E32-24: a disabled manual provider is rejected (deployment flag and admin switch) and checkout shows it is unavailable', function () {
    $user = e16User();
    $program = e32_program();

    config(['payments.providers.manual.enabled' => false]);
    expect(fn () => e32_request($user, $program))->toThrow(PaymentException::class);
    $this->actingAs($user)->get(route('support.checkout', $program->slug))->assertOk()->assertSee('الدفع غير متاح حاليًا');

    config(['payments.providers.manual.enabled' => true]);
    e32_settings(['manual_enabled' => false]);
    expect(fn () => e32_request($user, $program))->toThrow(PaymentException::class)->and(SponsorshipPayment::query()->count())->toBe(0);

    e32_settings(['manual_enabled' => true]);
    expect(e32_request($user, $program)->status)->toBe('awaiting_payment');
});

test('E32-25: the user marks the transfer as sent; the request becomes submitted and never expires; a double submit has no second effect', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'من حساب أحمد'])->assertRedirect(route('support.payments.show', $payment->public_id))->assertSessionHas('success');
    $this->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => 'من حساب أحمد'])->assertRedirect();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('submitted')->and($fresh->payer_note)->toBe('من حساب أحمد')->and($fresh->submitted_at)->not->toBeNull()->and($fresh->expires_at)->toBeNull()
        ->and(e32_audit('payment_proof_submitted'))->toBe(1);
});

test('E32-25b: the payer note rejects markup and an expired awaiting request cannot be submitted', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['payer_note' => '<script>alert(1)</script>'])->assertSessionHas('error');
    expect($payment->fresh()->status)->toBe('awaiting_payment');

    $this->travel(49)->hours();
    expect(fn () => e32_payments()->markTransferred($user, $payment, 'متأخر'))->toThrow(PaymentException::class);
});

test('E32-25c: when the admin requires proof, submitting without it fails', function () {
    e32_settings(['proof_required' => true]);
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), [])->assertSessionHas('error');
    expect($payment->fresh()->status)->toBe('awaiting_payment');

    $this->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof('png')])->assertSessionHas('success');
    expect($payment->fresh()->status)->toBe('submitted');
});

test('E32-26/27/28/29: JPEG, PNG, WEBP and PDF proofs are accepted and stored with the detected type', function (string $kind, string $mime, string $ext) {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof($kind)])->assertSessionHas('success');

    $fresh = $payment->fresh();
    expect($fresh->proof_mime)->toBe($mime)->and($fresh->proof_path)->toEndWith('.'.$ext)->and($fresh->proof_sha256)->toHaveLength(64)->and(Storage::disk('local')->exists($fresh->proof_path))->toBeTrue();
})->with([
    'jpeg' => ['jpg', 'image/jpeg', 'jpg'],
    'png' => ['png', 'image/png', 'png'],
    'webp' => ['webp', 'image/webp', 'webp'],
    'pdf' => ['pdf', 'application/pdf', 'pdf'],
]);

test('E32-30: an executable disguised as an image or an HTML page disguised as a PDF is rejected and nothing is stored', function () {
    $user = e16User();
    $payment = e32_request($user);
    $this->actingAs($user);

    foreach (['exe', 'html'] as $kind) {
        $this->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof($kind)])->assertSessionHas('error');
    }

    expect($payment->fresh()->status)->toBe('awaiting_payment')->and($payment->fresh()->proof_path)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('E32-31: SVG is rejected as proof (active content)', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.proof.upload', $payment->public_id), ['proof' => e32_proof('svg')])->assertSessionHas('error');
    expect($payment->fresh()->proof_path)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('E32-32: an oversized proof is rejected', function () {
    $user = e16User();
    $payment = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.transferred', $payment->public_id), ['proof' => e32_proof('big')]);
    expect(session()->has('error') || session()->has('errors'))->toBeTrue();
    expect($payment->fresh()->status)->toBe('awaiting_payment')->and($payment->fresh()->proof_path)->toBeNull();

    config(['payments.proof.max_kb' => 1]);
    expect(fn () => e32_payments()->attachProof($user, $payment, \Illuminate\Http\UploadedFile::fake()->image('big.png', 400, 400)->size(10)))->toThrow(PaymentException::class);
});

test('E32-33: the proof is stored on a private disk under a random server-generated name', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $path = $payment->fresh()->proof_path;

    expect($path)->toStartWith('payment-proofs/')->and($path)->not->toContain('proof.png')->and(basename($path))->toMatch('/^[0-9a-z]{26}\.png$/')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()->and(Storage::disk('public')->exists($path))->toBeFalse();

    config(['payments.proof.disk' => 'public']);
    expect(fn () => e32_payments()->attachProof($user, e32_request($user), e32_proof('png')))->toThrow(PaymentException::class);
});

test('E32-34: no direct public URL reaches the proof', function () {
    $payment = e32_submitted();
    $path = $payment->fresh()->proof_path;

    // القرص الخاص: لا symlink عام. مسار خدمة القرص المحلي (إن وُجد) يشترط توقيعًا ولا نولّد روابط موقّعة للإثبات أبدًا ⇒ 403/404.
    foreach (['/storage/'.$path, '/'.$path, '/storage/payment-proofs/'.basename($path), '/public/'.$path] as $url) {
        expect($this->get($url)->getStatusCode())->toBeIn([403, 404]);
    }

    expect(public_path($path))->not->toBeFile();
});

test('E32-35: unauthorized staff proof download is denied (guest, ordinary user, content manager) and finance succeeds with an audit row', function () {
    $payment = e32_submitted();
    $url = route('payments.staff.proof', $payment->public_id);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(e16User())->get($url)->assertForbidden();
    $this->actingAs(e32_manager())->get($url)->assertForbidden();
    expect(e32_audit('payment_proof_downloaded'))->toBe(0);

    $response = $this->actingAs(e32_finance())->get($url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->and(e32_audit('payment_proof_downloaded'))->toBe(1);
});

test('E32-35b: replacing a proof removes the old private file and a final request freezes the proof', function () {
    $user = e16User();
    $payment = e32_submitted($user);
    $old = $payment->fresh()->proof_path;

    e32_payments()->attachProof($user, $payment, e32_proof('jpg'));
    $new = $payment->fresh()->proof_path;
    expect($new)->not->toBe($old)->and(Storage::disk('local')->exists($old))->toBeFalse()->and(Storage::disk('local')->exists($new))->toBeTrue();

    e32_payments()->reject(e32_finance(), $payment->fresh(), 'not_received');
    expect(fn () => e32_payments()->attachProof($user, $payment->fresh(), e32_proof('png')))->toThrow(PaymentException::class);
});
