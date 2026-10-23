<?php

use App\Filament\Pages\CreatorEarningAccountsPage;
use App\Filament\Pages\CreatorFinanceAnalyticsPage;
use App\Filament\Pages\CreatorPayoutsPage;
use App\Filament\Pages\CreatorRevenuePeriodsPage;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorPayout;
use App\Models\CreatorRevenuePeriod;
use App\Models\User;
use App\Services\TrustSafety\RestrictedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SecurityE26/_support.php';

beforeEach(fn () => e39_seed());

/** يبدّل المستخدم داخل الاختبار نفسه دون أن تُسقِطه AuthenticateSession بسبب بصمة كلمة مرور الجلسة السابقة. */
function e39_as($test, User $user)
{
    auth()->logout();
    $test->flushSession();

    return $test->actingAs($user);
}

const E39_PAGES = ['/admin/creator-revenue-periods', '/admin/creator-payouts', '/admin/creator-earning-accounts', '/admin/creator-finance-analytics'];

// ───────────────────────────── BA15: مسارات المبدع ─────────────────────────────

test('BA15-01: guests are sent to login on every earnings route and nothing is created', function () {
    $this->get('/creator/earnings')->assertRedirect(route('login'));
    $this->get('/creator/earnings/statements/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertRedirect(route('login'));
    $this->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'bank_transfer'])->assertRedirect(route('login'));
    $this->post('/creator/earnings/requests/01HZZZZZZZZZZZZZZZZZZZZZZZ/cancel')->assertRedirect(route('login'));

    expect(CreatorPayout::query()->count())->toBe(0);
});

test('BA15-02: a player, an unverified creator, a pending, suspended or frozen creator never reach the earnings screen', function () {
    [$creator, $profile] = e39_funded_creator(3);
    $player = e16User();

    $this->actingAs($player)->get('/creator/earnings')->assertRedirect(route('creator.apply'));
    $this->actingAs($player)->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'bank_transfer'])->assertRedirect(route('creator.apply'));

    $creator->forceFill(['email_verified_at' => null])->save();
    expect($this->actingAs($creator->refresh())->get('/creator/earnings')->getStatusCode())->not->toBe(200);
    $creator->forceFill(['email_verified_at' => now()])->save();

    foreach (['pending', 'suspended'] as $status) {
        DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => $status]);
        $this->actingAs($creator->refresh())->get('/creator/earnings')->assertRedirect(route('creator.apply'));
    }

    DB::table('creator_profiles')->where('id', $profile->id)->update(['status' => 'approved']);
    $creator->forceFill(['is_frozen' => true])->save();
    expect($this->actingAs($creator->refresh())->get('/creator/earnings')->getStatusCode())->not->toBe(200)
        ->and($this->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'bank_transfer'])->getStatusCode())->not->toBe(200)
        ->and(CreatorPayout::query()->count())->toBe(0);
});

test('BA15-03: the restricted safety context of a frozen account opens nothing in the earnings area', function () {
    [$creator] = e39_funded_creator(3);
    $creator->forceFill(['is_frozen' => true])->save();
    $context = [RestrictedAccess::KEY => ['user_id' => $creator->id, 'until' => now()->addMinutes(20)->getTimestamp()]];

    $this->withSession($context)->get('/creator/earnings')->assertRedirect(route('login'));
    $this->withSession($context)->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'bank_transfer'])->assertRedirect(route('login'));
    expect(CreatorPayout::query()->count())->toBe(0);
});

test('BA15-04: an enrolled creator sees balances, statement rows and payout rows and nothing internal', function () {
    [$creator, $profile, $account, $period] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_payouts()->reject(e39_request($creator, '100.00'), $admin, 'تعذّر الصرف بسبب نقص بيانات التواصل', 'ملاحظة داخلية سرّية');
    $html = $this->actingAs($creator)->get('/creator/earnings')->assertOk()->getContent();

    expect($html)->toContain('$1,000.00')->toContain($period->label)->toContain('data-statement-row')->toContain('data-payout-row')->toContain('تعذّر الصرف بسبب نقص بيانات التواصل')
        ->not->toContain('ملاحظة داخلية سرّية')->not->toContain($admin->email)->not->toContain($admin->name)->not->toContain('payout:')->not->toContain('revenue:')
        ->and(substr_count($html, 'name="_token"'))->toBeGreaterThanOrEqual(1);
});

test('BA15-05: a creator who is not enrolled sees the empty state and no balances', function () {
    [$creator] = e39_creator(null, false);

    $this->actingAs($creator)->get('/creator/earnings')->assertOk()->assertSee('التسييل غير مُفعَّل لحسابك بعد')->assertDontSee('رصيد متاح');
});

test('BA15-06: the page reflects pause and hold without exposing the hold reason or note', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_monetization()->placeHold($account, $admin, 'compliance_review', 'ملاحظة حساسة عن المراجعة الداخلية');
    $held = $this->actingAs($creator)->get('/creator/earnings')->assertOk()->assertSee('data-earnings-hold', false)->getContent();

    expect($held)->not->toContain('ملاحظة حساسة عن المراجعة الداخلية')->not->toContain('compliance_review')->not->toContain('مراجعة امتثال');

    e39_monetization()->pause($account->refresh(), $admin, 'إيقاف مؤقت للمراجعة');
    $this->actingAs($creator)->get('/creator/earnings')->assertOk()->assertSee('data-earnings-paused', false);
});

test('BA15-07: a finalized statement shows only the creator own allocation and an unrelated, draft, review or unknown period is a 404', function () {
    [$creator, $profile, , $period] = e39_funded_creator(3);
    [$otherUser] = e39_creator();
    $draft = e39_period(null, ['label' => 'مسودة مخفية', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-05 00:00:00']);

    $html = $this->actingAs($creator)->get('/creator/earnings/statements/'.$period->public_id)->assertOk()->getContent();
    expect($html)->toContain($period->label);

    $this->actingAs($otherUser)->get('/creator/earnings/statements/'.$period->public_id)->assertNotFound();
    $this->actingAs($creator)->get('/creator/earnings/statements/'.$draft->public_id)->assertNotFound();
    $this->actingAs($creator)->get('/creator/earnings/statements/not-a-ulid')->assertNotFound();
    $this->actingAs($creator)->get('/creator/earnings/statements/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $this->actingAs($creator)->get('/creator/earnings/statements/1')->assertNotFound();
});

test('BA15-08: a statement never shows the platform pool, other creators or internal policy fields', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$a, , $accountA] = e39_creator($admin);
    [$b, $profileB] = e39_creator($admin);
    e39_solve([e39_work($a)], 3);
    e39_solve([e39_work($b)], 1);
    $period = e39_finalize(e39_review($admin, ['pool' => '4000.00']), $admin);
    $html = $this->actingAs($a)->get('/creator/earnings/statements/'.$period->public_id)->assertOk()->getContent();

    expect($html)->not->toContain($profileB->slug)->not->toContain('4,000.00')->not->toContain($admin->email)->not->toContain('formula_version')->not->toContain('policy_snapshot');
});

test('BA15-09: payout rows are scoped to the signed-in creator and cancelling someone else request is a 404 that changes nothing', function () {
    [$creator] = e39_funded_creator(3);
    [$other] = e39_creator();
    $payout = e39_request($creator, '100.00');

    $this->actingAs($other)->post('/creator/earnings/requests/'.$payout->public_id.'/cancel')->assertNotFound();
    expect($payout->refresh()->status)->toBe('requested');

    $this->actingAs($other)->get('/creator/earnings')->assertOk()->assertDontSee('data-payout-row', false);
    $this->actingAs($creator)->post('/creator/earnings/requests/'.$payout->public_id.'/cancel')->assertRedirect(route('creator.earnings'))->assertSessionHas('success');
    expect($payout->refresh()->status)->toBe('cancelled');
});

test('BA15-10: requesting a payout through the form creates one request for the session user and ignores every extra field', function () {
    [$creator, , $account] = e39_funded_creator(3);
    [$other, , $otherAccount] = e39_creator();

    $this->actingAs($creator)->post('/creator/earnings/requests', [
        'amount' => '120.00', 'method' => 'e_wallet',
        'user_id' => $other->id, 'creator_earning_account_id' => $otherAccount->id, 'status' => 'paid', 'amount_minor' => 1, 'external_reference' => 'HACK-0001', 'provider' => 'evil', 'approved_by' => 1,
    ])->assertRedirect(route('creator.earnings'))->assertSessionHas('success');

    $payout = CreatorPayout::query()->firstOrFail();

    expect($payout->user_id)->toBe($creator->id)->and($payout->creator_earning_account_id)->toBe($account->id)->and($payout->status)->toBe('requested')->and($payout->amount_minor)->toBe(12000)
        ->and($payout->method_code)->toBe('e_wallet')->and($payout->external_reference)->toBeNull()->and($payout->provider)->toBe('manual')->and($payout->approved_by)->toBeNull();
});

test('BA15-11: bad input is answered with a friendly message and reserves nothing', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)->actingAs($creator);

    $this->post('/creator/earnings/requests', ['method' => 'bank_transfer'])->assertSessionHasErrors('amount');
    $this->post('/creator/earnings/requests', ['amount' => '30.00'])->assertSessionHasErrors('method');
    $this->post('/creator/earnings/requests', ['amount' => str_repeat('9', 40), 'method' => 'bank_transfer'])->assertSessionHasErrors('amount');
    $this->post('/creator/earnings/requests', ['amount' => ['x'], 'method' => 'bank_transfer'])->assertSessionHasErrors('amount');
    $this->post('/creator/earnings/requests', ['amount' => '1.00', 'method' => 'bank_transfer'])->assertSessionHas('error');
    $this->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'crypto'])->assertSessionHas('error');

    expect(CreatorPayout::query()->count())->toBe(0)->and($account->refresh()->available_minor)->toBe(100000);
});

test('BA15-12: no earnings mutation is reachable by GET and unsupported verbs are refused', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $this->actingAs($creator);

    $this->get('/creator/earnings/requests')->assertStatus(405);
    $this->get('/creator/earnings/requests/'.$payout->public_id.'/cancel')->assertStatus(405);
    $this->put('/creator/earnings')->assertStatus(405);
    $this->delete('/creator/earnings/requests/'.$payout->public_id.'/cancel')->assertStatus(405);
    $this->delete('/creator/earnings/requests/'.$payout->public_id)->assertNotFound();

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'creator.earnings')) {
            $isRead = in_array('GET', $route->methods(), true);
            expect($isRead || in_array('POST', $route->methods(), true))->toBeTrue()
                ->and(in_array('web', $route->gatherMiddleware(), true))->toBeTrue()
                ->and(in_array('auth', $route->gatherMiddleware(), true))->toBeTrue();
        }
    }

    expect($payout->refresh()->status)->toBe('requested');
});

test('BA15-13: the global kill switch hides the screens with a 404, refuses requests and keeps the studio link away', function () {
    [$creator] = e39_funded_creator(3);
    $period = CreatorRevenuePeriod::query()->firstOrFail();

    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk()->assertSee(route('creator.earnings'), false);

    config(['creator_monetization.enabled' => false]);

    $this->actingAs($creator)->get('/creator/earnings')->assertNotFound();
    $this->actingAs($creator)->get('/creator/earnings/statements/'.$period->public_id)->assertNotFound();
    $this->actingAs($creator)->post('/creator/earnings/requests', ['amount' => '30.00', 'method' => 'bank_transfer'])->assertSessionHas('error');
    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk()->assertDontSee(route('creator.earnings'), false);

    expect(CreatorPayout::query()->count())->toBe(0);
    $this->actingAs(e39_admin())->get('/admin/creator-payouts')->assertOk();
});

test('BA15-14: the payout request limiter bites at the fourth attempt and is per user', function () {
    [$creator] = e39_funded_creator(3);
    [$other] = e39_creator();

    $this->actingAs($creator);
    $statuses = e26_hammer($this, 'post', '/creator/earnings/requests', 5, ['amount' => '1.00', 'method' => 'bank_transfer']);
    expect(e26_first_429($statuses))->toBe(4);

    auth()->logout();
    $this->actingAs($other);
    expect($this->post('/creator/earnings/requests', ['amount' => '1.00', 'method' => 'bank_transfer'])->getStatusCode())->not->toBe(429);
});

test('BA15-15: the payout cancel limiter bites at the sixth attempt', function () {
    [$creator] = e39_funded_creator(3);
    $this->actingAs($creator);
    $statuses = e26_hammer($this, 'post', '/creator/earnings/requests/01HZZZZZZZZZZZZZZZZZZZZZZZ/cancel', 7);

    expect(e26_first_429($statuses))->toBe(6);
});

test('BA15-16: all three limiters are registered with the documented names', function () {
    foreach (['creator-payout-request', 'creator-payout-cancel', 'creator-earnings-read'] as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull($name);
    }
});

test('BA15-17: page numbers are clamped - an absurd or malformed page is an empty list, never an error', function () {
    [$creator] = e39_funded_creator(3);
    $this->actingAs($creator);

    foreach (['999999', '0', '-5', 'abc', '1.5', '99999999999999999999'] as $page) {
        expect($this->get('/creator/earnings?page='.$page)->getStatusCode())->toBe(200);
    }
});

// ───────────────────────────── BA16: صفحات الإدارة ─────────────────────────────

test('BA16-01: the finance pages render for finance staff and refuse moderators and players', function () {
    foreach (E39_PAGES as $url) {
        e39_as($this, e39_admin())->get($url)->assertOk();
        e39_as($this, e39_plain_staff())->get($url)->assertForbidden();
        e39_as($this, e16User())->get($url)->assertForbidden();
    }
});

test('BA16-01b: guests are sent away from every finance page', function () {
    foreach (E39_PAGES as $url) {
        $this->get($url)->assertRedirect();
    }
});

test('BA16-02: a view-only staff member can read every page but each mutation is refused by the service and changes nothing', function () {
    [$creator, , $account, $period] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $viewer = e39_staff_with(['admin.access', 'creator-finance.view']);
    $this->actingAs($viewer);
    $before = e39_counts();

    foreach (E39_PAGES as $url) {
        $this->get($url)->assertOk();
    }

    Livewire::test(CreatorPayoutsPage::class)->call('show', $payout->public_id)->call('startReview')->call('approve')->set('rejectMessage', 'رفض بلا صلاحية كافية')->call('reject')->set('reference', 'REF-VIEW-1')->call('settle');
    Livewire::test(CreatorEarningAccountsPage::class)->call('show', $account->public_id)->set('note', 'ملاحظة كافية الطول')->call('pause')->call('placeHold')->set('adjustAmount', '5.00')->set('adjustReason', 'goodwill')->call('adjust');
    Livewire::test(CreatorRevenuePeriodsPage::class)->call('startCreate')->set('label', 'فترة بلا صلاحية')->set('startsAt', '2026-09-01T00:00')->set('endsAt', '2026-09-05T00:00')->set('pool', '10.00')->call('save')
        ->call('show', $period->public_id)->set('confirmFinalize', true)->call('finalizePeriod')->call('cancelPeriod');

    expect(e39_counts())->toBe($before)->and($payout->refresh()->status)->toBe('requested')->and($account->refresh()->isEnabled())->toBeTrue()->and($account->isOnHold())->toBeFalse();
});

test('BA16-03: the periods page walks a period from draft to a confirmed, final close', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator, , $account] = e39_creator($admin);
    e39_solve([e39_work($creator)], 3);
    $this->actingAs($admin);

    $page = Livewire::test(CreatorRevenuePeriodsPage::class)->call('startCreate')
        ->set('label', 'نوفمبر من الواجهة')->set('startsAt', '2026-11-01T00:00')->set('endsAt', '2026-11-10T00:00')->set('pool', '500.00')->call('save');
    $period = CreatorRevenuePeriod::query()->firstOrFail();

    expect($period->status)->toBe('draft')->and($period->pool_minor)->toBe(50000);

    $page->call('submitReview')->call('togglePreview');
    expect($period->refresh()->status)->toBe('review');

    $page->call('finalizePeriod');
    expect($period->refresh()->status)->toBe('review')->and(CreatorEarningEntry::query()->count())->toBe(0);

    $page->set('confirmFinalize', true)->call('finalizePeriod');
    expect($period->refresh()->status)->toBe('finalized')->and($account->refresh()->available_minor)->toBe(50000);

    $page->set('confirmFinalize', true)->call('finalizePeriod');
    expect(CreatorEarningEntry::query()->count())->toBe(1)->and($account->refresh()->available_minor)->toBe(50000);
});

test('BA16-04: the periods page edits only drafts, sends back from review and cancels with a reason', function () {
    $admin = e39_admin();
    $this->actingAs($admin);
    $draft = e39_period($admin);

    $page = Livewire::test(CreatorRevenuePeriodsPage::class)->call('show', $draft->public_id)->call('startEdit')->set('label', 'اسم معدّل')->set('pool', '750.00')->call('save');
    expect($draft->refresh()->label)->toBe('اسم معدّل')->and($draft->pool_minor)->toBe(75000);

    $page->call('submitReview');
    expect($draft->refresh()->status)->toBe('review');
    $page->call('startEdit')->set('label', 'تعديل بعد الإرسال')->call('save');
    expect($draft->refresh()->label)->toBe('اسم معدّل');

    $page->call('sendBack');
    expect($draft->refresh()->status)->toBe('draft');
    $page->call('cancelPeriod');
    expect($draft->refresh()->status)->toBe('draft');
    $page->set('cancelReason', 'أُلغيت لأن المجمّع خاطئ')->call('cancelPeriod');
    expect($draft->refresh()->status)->toBe('cancelled');
});

test('BA16-05: the payouts page runs the whole lifecycle with the right permissions at each step', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $admin = e39_admin();
    $this->actingAs($admin);

    $page = Livewire::test(CreatorPayoutsPage::class)->assertSee('100.00')->call('show', $payout->public_id)->call('startReview');
    expect($payout->refresh()->status)->toBe('under_review');

    $page->set('reviewNote', 'تم التحقق')->call('approve');
    expect($payout->refresh()->status)->toBe('approved');

    $page->set('reference', 'bad;ref')->call('settle');
    expect($payout->refresh()->status)->toBe('approved');

    $page->set('reference', 'WIRE-2026-77')->call('settle');
    expect($payout->refresh()->status)->toBe('paid')->and($payout->external_reference)->toBe('WIRE-2026-77')->and($account->refresh()->paid_lifetime_minor)->toBe(10000);
});

test('BA16-06: the payouts page rejects with a public message and returns the money, and tabs filter by state', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $this->actingAs(e39_admin());

    $page = Livewire::test(CreatorPayoutsPage::class)->call('show', $payout->public_id)->call('reject');
    expect($payout->refresh()->status)->toBe('requested');

    $page->set('rejectMessage', 'يرجى تحديث وسيلة التواصل ثم إعادة الطلب')->call('reject');
    expect($payout->refresh()->status)->toBe('rejected')->and($account->refresh()->available_minor)->toBe(100000);

    Livewire::test(CreatorPayoutsPage::class)->set('tab', 'closed')->assertSee('100.00');
    Livewire::test(CreatorPayoutsPage::class)->set('tab', 'open')->assertDontSee('data-payout-open-row', false);
});

test('BA16-07: the payouts page cannot approve or settle for staff who lack that permission, even with the page open', function () {
    [$creator] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $reviewer = e39_staff_with(['admin.access', 'creator-finance.view', 'creator-finance.review-payouts']);
    $this->actingAs($reviewer);

    Livewire::test(CreatorPayoutsPage::class)->call('show', $payout->public_id)->call('approve');
    expect($payout->refresh()->status)->toBe('approved');

    Livewire::test(CreatorPayoutsPage::class)->call('show', $payout->public_id)->set('reference', 'REF-NOPE-1')->call('settle');
    expect($payout->refresh()->status)->toBe('approved')->and($payout->external_reference)->toBeNull();
});

test('BA16-08: the accounts page enrolls by slug and runs pause, resume, hold and adjustment flows', function () {
    $admin = e39_admin();
    [$creator, $profile] = e39_creator($admin, false);
    $this->actingAs($admin);

    $page = Livewire::test(CreatorEarningAccountsPage::class)->set('enableSlug', 'no-such-slug')->call('enable');
    expect(CreatorEarningAccount::query()->count())->toBe(0);

    $page->set('enableSlug', $profile->slug)->call('enable');
    $account = CreatorEarningAccount::query()->firstOrFail();
    expect($account->isEnabled())->toBeTrue();

    $page->set('note', 'سبب الإيقاف المؤقت')->call('pause');
    expect($account->refresh()->isEnabled())->toBeFalse();
    $page->call('resume');
    expect($account->refresh()->isEnabled())->toBeTrue();

    $page->set('note', 'تحقيق جارٍ في الحساب')->set('holdReason', 'investigation')->call('placeHold');
    expect($account->refresh()->isOnHold())->toBeTrue();
    $page->set('note', 'انتهى التحقيق دون مخالفة')->call('releaseHold');
    expect($account->refresh()->isOnHold())->toBeFalse();

    $page->set('adjustDirection', 'credit')->set('adjustAmount', '12.34')->set('adjustReason', 'goodwill')->set('note', 'تعويض معتمد بقرار الإدارة')->call('adjust');
    expect($account->refresh()->available_minor)->toBe(1234);
});

test('BA16-09: submitting the same adjustment form twice posts it once', function () {
    [, , $account] = e39_funded_creator(3);
    $this->actingAs(e39_admin());

    $page = Livewire::test(CreatorEarningAccountsPage::class)->call('show', $account->public_id);
    $key = $page->get('requestKey');
    $page->set('adjustAmount', '5.00')->set('adjustReason', 'goodwill')->set('note', 'تعويض معتمد بقرار الإدارة')->call('adjust');
    $page->set('requestKey', $key)->set('adjustAmount', '5.00')->set('adjustReason', 'goodwill')->set('note', 'تعويض معتمد بقرار الإدارة')->call('adjust');

    expect($account->refresh()->available_minor)->toBe(100500)->and(CreatorEarningEntry::query()->where('type', 'adjustment_credit')->count())->toBe(1);
});

test('BA16-10: the accounts page filters by state and searches by slug without exposing e-mail addresses', function () {
    $admin = e39_admin();
    [$a, $profileA, $accountA] = e39_creator($admin);
    [$b, $profileB, $accountB] = e39_creator($admin);
    e39_monetization()->placeHold($accountB, $admin, 'investigation', 'تحقيق جارٍ في الحساب');
    $this->actingAs($admin);

    $holdHtml = Livewire::test(CreatorEarningAccountsPage::class)->set('filter', 'hold')->html();
    $searchHtml = Livewire::test(CreatorEarningAccountsPage::class)->set('search', $profileA->slug)->html();

    expect($holdHtml)->toContain($profileB->slug)->not->toContain($profileA->slug)->and($searchHtml)->toContain($profileA->slug)->not->toContain($profileB->slug)
        ->and($holdHtml.$searchHtml)->not->toContain($a->email)->not->toContain($b->email);
});

test('BA16-11: a tampered record id on any finance page renders nothing and mutates nothing', function () {
    [, , $account] = e39_funded_creator(3);
    $this->actingAs(e39_admin());
    $before = e39_counts();

    Livewire::test(CreatorPayoutsPage::class)->set('open', "x' OR 1=1 --")->assertSuccessful()->call('approve')->call('startReview');
    Livewire::test(CreatorPayoutsPage::class)->call('show', '99999')->call('settle');
    Livewire::test(CreatorEarningAccountsPage::class)->set('open', '<script>alert(1)</script>')->assertSuccessful()->call('pause')->call('placeHold');
    Livewire::test(CreatorRevenuePeriodsPage::class)->set('open', '0')->assertSuccessful()->call('submitReview')->set('confirmFinalize', true)->call('finalizePeriod');

    expect(e39_counts())->toBe($before)->and($account->refresh()->isEnabled())->toBeTrue();
});

test('BA16-12: the analytics page shows aggregates only - no creator identity, e-mail or leaderboard', function () {
    [$creator, $profile, , $period] = e39_funded_creator(3);
    e39_request($creator, '100.00');
    $html = Livewire::actingAs(e39_admin())->test(CreatorFinanceAnalyticsPage::class)->assertSuccessful()->html();

    expect($html)->toContain($period->label)->not->toContain($profile->slug)->not->toContain($creator->email)->not->toContain($creator->name)->not->toContain('المتصدرين');
});

test('BA16-13: finance staff see the new navigation group between support and moderation in the admin shell', function () {
    $html = $this->actingAs(e39_admin())->get('/admin')->assertOk()->getContent();
    preg_match_all('/data-group-label="([^"]+)"/u', $html, $m);
    $groups = $m[1];

    expect($groups)->toContain('مالية المبدعين');

    $position = array_search('مالية المبدعين', $groups, true);
    expect($groups[$position - 1])->toBe('الرعاية والدعم')->and($groups[$position + 1])->toBe('الإشراف');
});

test('BA16-14: every finance page declares its slug, group and permission-based access', function () {
    foreach ([CreatorRevenuePeriodsPage::class, CreatorPayoutsPage::class, CreatorEarningAccountsPage::class, CreatorFinanceAnalyticsPage::class] as $page) {
        $reflection = new ReflectionClass($page);

        expect($reflection->getProperty('navigationGroup')->getValue())->toBe('مالية المبدعين');

        $this->actingAs(e39_admin());
        expect($page::canAccess())->toBeTrue();
        $this->actingAs(e39_plain_staff());
        expect($page::canAccess())->toBeFalse();
        $this->actingAs(e16User());
        expect($page::canAccess())->toBeFalse();
    }
});

test('BA16-15: the list pages do not grow their query count with the number of rows', function () {
    $admin = e39_admin();
    $this->actingAs($admin);
    [, , $first] = e39_creator($admin);

    DB::enableQueryLog();
    Livewire::test(CreatorEarningAccountsPage::class)->assertSuccessful();
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    for ($i = 0; $i < 8; $i++) {
        e39_creator($admin);
    }

    DB::flushQueryLog();
    Livewire::test(CreatorEarningAccountsPage::class)->assertSuccessful();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few);
});

// ───────────────────────────── BA17: الصلاحيات والأدوار ─────────────────────────────

test('BA17-01: the eight finance permissions exist, belong to administrator only and are not wildcards', function () {
    $expected = ['view', 'manage-periods', 'finalize', 'manage-monetization', 'review-payouts', 'settle-payouts', 'adjust', 'manage-holds'];
    $group = array_keys((array) config('permissions.creator_finance.permissions'));

    expect($group)->toHaveCount(8);

    foreach ($expected as $name) {
        expect(\Spatie\Permission\Models\Permission::query()->where('name', 'creator-finance.'.$name)->exists())->toBeTrue()
            ->and(e39_admin()->can('creator-finance.'.$name))->toBeTrue()
            ->and(e39_plain_staff()->can('creator-finance.'.$name))->toBeFalse()
            ->and(e16User()->can('creator-finance.'.$name))->toBeFalse();
    }
});

test('BA17-02: no other staff role is granted a finance permission by the seeder', function () {
    $roles = \Spatie\Permission\Models\Role::query()->with('permissions')->get();

    foreach ($roles as $role) {
        $finance = $role->permissions->pluck('name')->filter(fn ($n) => str_starts_with($n, 'creator-finance.'))->count();

        if (in_array($role->name, ['administrator', 'super-admin'], true)) {
            continue;
        }

        expect($finance)->toBe(0, "role {$role->name} must not hold finance permissions");
    }
});

test('BA17-03: the money-moving steps are separate permissions - period close, review and settlement can be split between people', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    $reviewer = e39_staff_with(['creator-finance.view', 'creator-finance.review-payouts']);
    $settler = e39_staff_with(['creator-finance.view', 'creator-finance.settle-payouts']);

    expect(e39_error(fn () => e39_payouts()->settle($payout, $reviewer, 'REF-SPLIT-1')))->not->toBeNull()
        ->and(e39_error(fn () => e39_payouts()->approve($payout, $settler)))->not->toBeNull()
        ->and(e39_payouts()->approve($payout, $reviewer)->status)->toBe('approved')
        ->and(e39_payouts()->settle($payout->refresh(), $settler, 'REF-SPLIT-1')->status)->toBe('paid');
});
