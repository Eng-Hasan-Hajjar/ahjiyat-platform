<?php

use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipRewardBonus;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterLeaderboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E32 (AK6 + AK7 + AK8) - الرفض والإلغاء، الاسترداد (بلا سحب مكافآت تاريخية)، وخصوصية الدعم المجهول.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

function e32_gems(User $user): int
{
    return (int) $user->fresh()->wallet->pending_balance;
}

function e32_play(?User $user = null): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 200]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

test('E32-56/57: finance rejects with a whitelisted code; the visible message is shown to the owner, the internal note never is', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    e32_payments()->reject(e32_finance(), $payment, 'not_received', 'لم يصلنا تحويل بهذا المرجع', 'ملاحظة داخلية سرّية للمراجع');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('rejected')->and($fresh->failure_code)->toBe('not_received')->and($fresh->rejected_by)->not->toBeNull()->and(e32_audit('payment_rejected'))->toBe(1);

    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('لم يصلنا تحويل بهذا المرجع')->assertDontSee('ملاحظة داخلية سرّية للمراجع')->assertDontSee('not_received');
    expect(json_encode($fresh->toArray()))->not->toContain('ملاحظة داخلية')->not->toContain('failure_code');
});

test('E32-57b: an unknown rejection code and markup in messages are refused; a second reject is idempotent', function () {
    $payment = e32_submitted();

    expect(fn () => e32_payments()->reject(e32_finance(), $payment, 'made-up'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->reject(e32_finance(), $payment, 'other', '<script>alert(1)</script>'))->toThrow(PaymentException::class)
        ->and($payment->fresh()->status)->toBe('submitted');

    e32_payments()->reject(e32_finance(), $payment, 'duplicate');
    e32_payments()->reject(e32_finance(), $payment->fresh(), 'duplicate');
    expect(e32_audit('payment_rejected'))->toBe(1)->and(e32_notes($payment->user, 'support_rejected'))->toHaveCount(1);
});

test('E32-58: the owner is notified of a rejection once, without amount or reference text', function () {
    $user = e16User();
    $payment = e32_submitted($user, null, '77.25');

    e32_payments()->reject(e32_finance(), $payment, 'proof_unreadable');

    $notes = e32_notes($user, 'support_rejected');
    expect($notes)->toHaveCount(1);
    $raw = json_encode($notes->first(), JSON_UNESCAPED_UNICODE);
    foreach (['77.25', '7725', $payment->reference, 'proof_unreadable'] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("leaked: {$secret}");
    }
});

test('E32-59: rejected, cancelled and expired payments never fund, tier or boost anything', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_payments()->reject(e32_finance(), e32_submitted(null, $program, '900'), 'not_received');
    e32_payments()->cancel(e32_finance(), e32_submitted(null, $program, '900'), 'طلب مكرر');
    e32_request(null, $program, '900');
    $this->travel(49)->hours();
    e32_payments()->expireStale();

    expect(e32_funding($program))->toBe(0)->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->count())->toBe(0)
        ->and(SponsorshipPayment::query()->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'expired', 'rejected']);
    [$result] = e32_play();
    expect($result['sponsor_bonus'])->toBe(0);
});

test('E32-60: the owner can cancel an awaiting request but not after sending the transfer', function () {
    $user = e16User();
    $awaiting = e32_request($user);

    $this->actingAs($user)->post(route('support.payments.cancel', $awaiting->public_id))->assertRedirect(route('support.history'));
    expect($awaiting->fresh()->status)->toBe('cancelled')->and($awaiting->fresh()->cancelled_by)->toBe($user->id);

    $this->post(route('support.payments.cancel', $awaiting->public_id))->assertRedirect(); // مكرر: لا أثر
    expect(e32_audit('payment_cancelled'))->toBe(1);

    $sent = e32_submitted($user);
    $this->post(route('support.payments.cancel', $sent->public_id))->assertSessionHas('error');
    expect($sent->fresh()->status)->toBe('submitted');
});

test('E32-61: a verified payment cannot be cancelled or rejected arbitrarily - only refunded', function () {
    $user = e16User();
    $verified = e32_verified($user);

    $this->actingAs($user)->post(route('support.payments.cancel', $verified->public_id))->assertSessionHas('error');
    expect(fn () => e32_payments()->cancel(e32_finance(), $verified->fresh(), 'محاولة إلغاء'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->reject(e32_finance(), $verified->fresh(), 'other'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->requestClarification(e32_finance(), $verified->fresh(), 'توضيح مطلوب'))->toThrow(PaymentException::class);

    expect($verified->fresh()->status)->toBe('verified')->and(e32_contribution($verified)->status)->toBe('confirmed');
});

test('E32-61b: clarification returns a submitted request to the owner with a visible message, a fresh deadline and a notification; the owner can resubmit', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    e32_payments()->requestClarification(e32_finance(), $payment, 'أرسل صورة أوضح للإيصال');

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('awaiting_payment')->and($fresh->user_message)->toBe('أرسل صورة أوضح للإيصال')->and($fresh->expires_at)->not->toBeNull()->and(e32_notes($user, 'support_action_required'))->toHaveCount(1);
    $this->actingAs($user)->get(route('support.payments.show', $payment->public_id))->assertOk()->assertSee('أرسل صورة أوضح للإيصال');

    $again = e32_payments()->markTransferred($user, $fresh, 'صورة جديدة', e32_proof('jpg'));
    expect($again->status)->toBe('submitted')->and(e32_funding($payment->program))->toBe(0);
});

test('E32-62/63: finance refunds a verified payment and the linked contribution becomes refunded in the same step', function () {
    $program = e32_program();
    $payment = e32_verified(null, $program, '40');
    $contribution = e32_contribution($payment);

    $refunded = e32_payments()->refund(e32_finance(), $payment, 'استرداد بطلب الداعم');

    expect($refunded->status)->toBe('refunded')->and($refunded->refunded_at)->not->toBeNull()->and($refunded->refunded_by)->not->toBeNull()
        ->and($contribution->fresh()->status)->toBe('refunded')->and($contribution->fresh()->refunded_at)->not->toBeNull();
    expect(fn () => e32_payments()->refund(e32_finance(), e32_submitted(), 'غير محقق'))->toThrow(PaymentException::class)
        ->and(fn () => e32_payments()->refund(e32_finance(), $payment->fresh(), 'x'))->toThrow(SponsorshipException::class);
});

test('E32-64/69/70: a duplicate refund is safe, audited once and notifies the owner once', function () {
    $user = e16User();
    $payment = e32_verified($user);

    e32_payments()->refund(e32_finance(), $payment, 'استرداد أول');
    e32_payments()->refund(e32_finance(), $payment->fresh(), 'استرداد ثانٍ');

    expect(e32_audit('payment_refunded'))->toBe(1)->and(DB::table('operational_audit_logs')->where('action', 'like', '%contribution%refund%')->count())->toBeGreaterThanOrEqual(1)
        ->and(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1);
    $row = DB::table('operational_audit_logs')->where('action', 'payment_refunded')->first();
    expect($row->actor_user_id)->not->toBeNull();
});

test('E32-65/66/67: a refund lowers the program funding, recalculates the tier and drops the supporter from the leaderboard', function () {
    $program = e32_program();
    $user = e16User();
    $big = e32_verified($user, $program, '300');
    $small = e32_verified($user, $program, '10');
    $profileId = $big->supporter_profile_id;

    $tier = fn () => app(\App\Services\Sponsorship\SupporterTierService::class)->forSupporter($profileId)?->slug;
    expect(e32_funding($program))->toBe(31000)->and($tier())->toBe('gold');

    e32_payments()->refund(e32_finance(), $big, 'استرداد كبير');

    expect(e32_funding($program))->toBe(1000)->and($tier())->toBe('supporter');
    e32_payments()->refund(e32_finance(), $small->fresh(), 'استرداد صغير');
    expect(e32_funding($program))->toBe(0)->and($tier())->toBeNull()
        ->and(app(SupporterLeaderboardService::class)->top('all')['entries'])->toBe([]);
});

test('E32-68: a refund never claws back rewards already granted to players; later solves just lose the boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    $payment = e32_verified(null, $program, '50'); // 5%

    [$before, $player] = e32_play();
    expect($before['sponsor_bonus'])->toBe(10)->and(e32_gems($player))->toBe(210);
    $bonusRows = SponsorshipRewardBonus::query()->count();

    e32_payments()->refund(e32_finance(), $payment, 'استرداد بعد المكافأة');

    expect(e32_gems($player))->toBe(210)->and(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->and(SponsorshipRewardBonus::query()->first()->bonus_amount ?? 10)->toBe(10);
    [$after] = e32_play();
    expect($after['sponsor_bonus'])->toBe(0);
});

test('E32-62b: refunding through the E30 contribution screen syncs the payment to refunded and notifies once; a later payment refund is a no-op', function () {
    $user = e16User();
    $payment = e32_verified($user);

    e30_refund(e32_contribution($payment), 'استرداد من شاشة المساهمات');

    expect($payment->fresh()->status)->toBe('refunded')->and(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(e32_audit('payment_refunded'))->toBe(1);
    e32_payments()->refund(e32_finance(), $payment->fresh(), 'مكرر');
    expect(e32_notes($user, 'support_refunded'))->toHaveCount(1)->and(e32_audit('payment_refunded'))->toBe(1);
});

test('E32-71/72: an anonymous verified payment funds the program and counts for the reward boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    $payment = e32_verified(null, $program, '30', true);

    expect(e32_funding($program))->toBe(3000)->and(e32_contribution($payment)->is_anonymous)->toBeTrue();
    [$result] = e32_play();
    expect($result['sponsor_bonus'])->toBe(6); // 3% من 200
});

test('E32-73/74/75/109: an anonymous supporter is not identifiable on any public page, search or API route', function () {
    $program = e32_program(['title' => 'برنامج الخصوصية', 'show_public_amounts' => true]);
    $user = e16User(['name' => 'غامض-سري-فريد', 'email' => 'secret-anon-e32@example.test']);
    $payment = e32_verified($user, $program, '77', true);
    $profile = SupporterProfile::query()->findOrFail($payment->supporter_profile_id);

    // صفحات الرعاية والدعم لا يجوز أن تحمل هوية الداعم المجهول. (البحث يجد اللاعب العام باسمه كلاعب - هذا سلوك E27 المستقل - فنفحص فيه ما يخص الدعم فقط.)
    $support = [route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('support.index'), route('support.program', $program->slug)];
    $search = [route('search.index', ['q' => 'غامض']), route('search.suggest', ['q' => 'غامض'])];
    $supportNeedles = ['غامض-سري-فريد', 'secret-anon-e32@example.test', $profile->slug, $profile->public_id, $payment->public_id, $payment->reference, 'data-supporter-recognition'];
    $searchNeedles = ['secret-anon-e32@example.test', $profile->slug, $profile->public_id, $payment->public_id, $payment->reference, 'data-supporter-recognition', 'برنامج الخصوصية'];

    foreach ([[$support, $supportNeedles], [$search, $searchNeedles]] as [$urls, $needles]) {
        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ($needles as $needle) {
                expect(str_contains($html, (string) $needle))->toBeFalse("anonymous leak '{$needle}' at {$url}");
            }
        }
    }

    $this->get(route('supporters.show', $profile->slug))->assertNotFound();
    $viewer = e16User();
    $this->actingAs($viewer)->get(route('players.show', $user))->assertOk()->assertDontSee('data-supporter-recognition', false);

    foreach (Route::getRoutes() as $route) {
        // الاستثناء الوحيد: Webhook المزوّدين المستقبلي (POST فقط، مغلق افتراضيًا، لا يعرض أي بيانات داعمين - انظر E32-S4/E32-WH).
        if (str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'api/payments/webhooks/')) {
            expect($route->uri())->not->toContain('support')->and($route->uri())->not->toContain('payment');
        }
    }
});

test('E32-76: finance can still resolve the internal request behind an anonymous contribution', function () {
    $user = e16User(['name' => 'صاحب الدعم المجهول']);
    $payment = e32_verified($user, null, '25', true);
    $contribution = e32_contribution($payment);

    $resolved = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->with('user')->first();
    expect($resolved->user->id)->toBe($user->id)->and($resolved->reference)->toBe($contribution->external_reference);

    \Livewire\Livewire::actingAs(e32_finance())->test(\App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments::class)
        ->assertCanSeeTableRecords([$payment])->assertSee('صاحب الدعم المجهول');
});
