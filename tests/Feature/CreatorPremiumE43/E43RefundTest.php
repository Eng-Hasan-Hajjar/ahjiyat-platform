<?php

use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeriesAccess;
use App\Models\DigitalPurchase;
use App\Services\Commerce\SandboxCommercePaymentProvider;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorPremium\CreatorDirectSaleService;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (4/9) - الاسترداد: استرداد المزوّد هو السلطة، سحب الوصول (لا حذف)، عكس حصة المبدع إلحاقيًا، المحجوب ⇒ needs_finance_review بلا رصيد سالب،
| إعادة المحاولة / تحميل المنصة، ما استهلكه المشتري، إعادة الشراء بعد الاسترداد، وصلاحيات العمليات الإدارية.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

function e43_refund(array $world, array $bought, bool $confirm = false, string $reason = 'user_request')
{
    return app(CreatorPremiumRefundService::class)->refund($world['admin'], $bought['purchase']->public_id, $reason, 'استرداد موثَّق لأغراض الاختبار', $confirm);
}

test('E43-RF-01: a provider refund revokes access (no deletion) and reverses the creator share with an append-only ledger entry', function () {
    $world = e43_world();
    $before = e43_available($world['account']);
    $bought = e43_buy($world);
    expect(e43_available($world['account']))->toBe($before + 350);

    $refunded = e43_refund($world, $bought);
    $access = e43_access_row($bought['user'], $world['series']);
    $sale = $bought['sale']->fresh();
    $reversal = CreatorEarningEntry::query()->find($sale->reversal_entry_id);
    $account = $world['account']->fresh();

    expect($refunded->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and($refunded->provider_status)->toBe('refunded')
        ->and($access)->not->toBeNull()->and($access->isActive())->toBeFalse()->and($access->revoke_reason)->toBe('refund')->and($access->revoked_at)->not->toBeNull()
        ->and($sale->status)->toBe('refunded')->and($sale->refunded_at)->not->toBeNull()
        ->and($reversal->type)->toBe('direct_sale_reversal')->and($reversal->available_delta_minor)->toBe(-350)->and($reversal->source_key)->toBe('direct_sale:'.$bought['purchase']->id.':reversal')
        ->and((int) $account->available_minor)->toBe($before)
        ->and(e39_ledger()->ledgerBalances($account->id)['available'])->toBe($before)
        ->and(DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count())->toBe(1);

    // التاريخ لا يُمحى: القيد الدائن الأصلي ولقطة البيع باقيان.
    expect(CreatorEarningEntry::query()->find($sale->credit_entry_id)->available_delta_minor)->toBe(350)->and($sale->gross_minor)->toBe(500);
});

test('E43-RF-02: after a refund the buyer is locked out again and notified; the creator is told about the adjustment', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_refund($world, $bought);

    expect(e43_access_service()->decide($bought['user'], e43_get_work_for_refund($world))->allowed)->toBeFalse()
        ->and(e43_notes($bought['user'], 'commerce_refunded')->count())->toBe(1)
        ->and(e43_notes($world['user'], 'creator_premium_sale_adjusted')->count())->toBe(1);

    $this->actingAs($bought['user'])->get(route('community.puzzles.show', $world['works'][0]->public_id))->assertOk()->assertDontSee('يكتب ولا يقرأ');
});

function e43_get_work_for_refund(array $world): \App\Models\CreatorPuzzle
{
    return \App\Models\CreatorPuzzle::query()->visible()->withPremiumLock()->where('public_id', $world['works'][0]->public_id)->firstOrFail();
}

test('E43-RF-03: replaying the refund (manual again, provider event, reverse twice) never reverses twice or flips state back', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_refund($world, $bought);
    $entries = DB::table('creator_earning_entries')->count();

    expect(e43_reason(fn () => e43_refund($world, $bought)))->not->toBeNull();
    e41_deliver($bought['session'], 'payment.refunded', [], 'rf-replay-1');

    $locked = DB::transaction(fn () => app(\App\Services\Commerce\DigitalPurchaseService::class)->lock($bought['purchase']));
    $again = DB::transaction(fn () => app(CreatorDirectSaleService::class)->reverseForPurchase($locked));

    expect($again)->toBe('already')->and(DB::table('creator_earning_entries')->count())->toBe($entries)->and($bought['sale']->fresh()->status)->toBe('refunded');
});

test('E43-RF-04: refunding a purchase the buyer already played needs an explicit financial confirmation, then proceeds', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e30s_solve($bought['user'], $world['works'][0]);

    expect(e43_reason(fn () => e43_refund($world, $bought)))->toBe('claimed_requires_confirmation')
        ->and($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue();

    e43_tick(2);
    $refunded = e43_refund($world, $bought, true);

    expect($refunded->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and($refunded->refund_kept_items)->toBe(1)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse();
});

test('E43-RF-05: attempts made BEFORE the purchase (grandfathered play) do not count as consumption', function () {
    $world = e43_world(['offer' => false]);
    $player = e16User();
    e30s_wrong($player, $world['works'][0]);
    e43_tick(2);
    e43_offer($world['user'], $world['series'], '5.00');
    $bought = e43_buy($world, $player);

    expect(app(\App\Services\CreatorPremium\CreatorSeriesAccessProductHandler::class)->claimedItemCount($bought['purchase']))->toBe(0);
    e43_tick(2);
    expect(e43_refund($world, $bought)->status)->toBe(DigitalPurchase::STATUS_REFUNDED);
});

test('E43-RF-06: provider refunded the payment while the buyer had played ⇒ the fact is kept, a review flag is raised, access stays until a human confirms', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e30s_solve($bought['user'], $world['works'][0]);

    e41_deliver($bought['session'], 'payment.refunded', [], 'rf-provider-1');
    $flagged = $bought['purchase']->fresh();

    expect($flagged->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and($flagged->provider_status)->toBe('refunded')->and($flagged->review_flag)->toBe('refund_claimed_items')
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue();

    e43_tick(2);
    $done = e43_refund($world, $bought, true);

    expect($done->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse()
        ->and($bought['sale']->fresh()->status)->toBe('refunded');
});

test('E43-RF-07: a provider refund event for an unplayed purchase completes everything with no human step', function () {
    $world = e43_world();
    $bought = e43_buy($world);

    e41_deliver($bought['session'], 'payment.refunded', [], 'rf-provider-2');

    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse()
        ->and($bought['sale']->fresh()->status)->toBe('refunded')->and(e43_notes($world['user'], 'creator_premium_sale_adjusted')->count())->toBe(1);
});

test('E43-RF-08: a pending provider refund keeps access and credit until the provider confirms', function () {
    $world = e43_world();
    $bought = e43_buy($world);

    SandboxCommercePaymentProvider::forceRefundMode('pending');
    $pending = e43_refund($world, $bought);
    expect($pending->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue()->and($bought['sale']->fresh()->status)->toBe('credited');

    SandboxCommercePaymentProvider::forceRefundMode(null);
    e41_deliver($bought['session'], 'payment.refunded', [], 'rf-pending-done');

    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse();
});

test('E43-RF-09: a failed provider refund changes nothing (no access change, no reversal)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $entries = DB::table('creator_earning_entries')->count();

    SandboxCommercePaymentProvider::forceRefundMode('failed');
    expect(e43_reason(fn () => e43_refund($world, $bought)))->toBe('refund_failed');
    SandboxCommercePaymentProvider::forceRefundMode(null);

    expect($bought['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue()
        ->and(DB::table('creator_earning_entries')->count())->toBe($entries)->and($bought['sale']->fresh()->status)->toBe('credited');
});

// ───────────── الحالة الحرجة: المبدع قبض/استهلك الحصة ─────────────

test('E43-RF-10: refund confirmed but the creator share cannot be reversed ⇒ needs_finance_review: access revoked, no negative balance, nothing deleted, nothing auto-confiscated', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_drain($world, '1.00'); // المتاح 1.00 فقط (الحصة 3.50) كأن المبدع صرف الباقي

    $before = DB::table('creator_earning_entries')->count();
    $availableBefore = e43_available($world['account']);
    $refunded = e43_refund($world, $bought);
    $sale = $bought['sale']->fresh();

    expect($refunded->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and($refunded->provider_status)->toBe('refunded')
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeFalse()
        ->and($sale->status)->toBe('needs_finance_review')->and($sale->review_reason)->toBe('reversal_blocked')->and($sale->review_flagged_at)->not->toBeNull()->and($sale->reversal_entry_id)->toBeNull()
        ->and(DB::table('creator_earning_entries')->count())->toBe($before)
        ->and(e43_available($world['account']))->toBe($availableBefore)->and(e43_available($world['account']))->toBeGreaterThanOrEqual(0)
        ->and(DB::table('creator_earning_entries')->where('id', $sale->credit_entry_id)->exists())->toBeTrue()
        ->and(DB::table('operational_audit_logs')->where('action', 'creator_direct_sale_needs_review')->count())->toBe(1);
});

test('E43-RF-11: retrying the reversal stays blocked (changes nothing) until the available balance covers the share, then completes append-only', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_drain($world, '1.00');
    e43_refund($world, $bought);
    $sale = $bought['sale']->fresh();

    $entries = DB::table('creator_earning_entries')->count();
    expect(e43_reason(fn () => app(CreatorDirectSaleService::class)->retryReversal($world['admin'], $sale->public_id)))->toBe('still_blocked')
        ->and($sale->fresh()->status)->toBe('needs_finance_review')->and(DB::table('creator_earning_entries')->count())->toBe($entries);

    e39_ledger()->adjust($world['account']->fresh(), $world['admin'], 'credit', '5.00', 'goodwill', 'تعويض لإتاحة عكس حصة البيع المسترد.');
    $done = app(CreatorDirectSaleService::class)->retryReversal($world['admin'], $sale->public_id);

    expect($done->status)->toBe('refunded')->and($done->reversal_entry_id)->not->toBeNull()->and($done->review_resolution)->toBe('reversed')->and($done->review_resolved_by)->toBe($world['admin']->id)
        ->and(e43_available($world['account']))->toBe(100 + 500 - 350)
        ->and(e39_ledger()->ledgerBalances($world['account']->id)['available'])->toBe(e43_available($world['account']))
        ->and(e43_reason(fn () => app(CreatorDirectSaleService::class)->retryReversal($world['admin'], $sale->public_id)))->toBe('invalid_state');
});

test('E43-RF-12: the platform can absorb the refund (write-off) with a note - no ledger movement, creator keeps the share, decision is final', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_drain($world, '1.00');
    e43_refund($world, $bought);
    $sale = $bought['sale']->fresh();
    $entries = DB::table('creator_earning_entries')->count();
    $available = e43_available($world['account']);
    $service = app(CreatorDirectSaleService::class);

    expect(e43_reason(fn () => $service->writeOff($world['admin'], $sale->public_id, 'قصير')))->toBe('note_invalid');

    $done = $service->writeOff($world['admin'], $sale->public_id, 'تتحمل المنصة خسارة هذا الاسترداد بقرار الإدارة المالية.');

    expect($done->status)->toBe('written_off')->and($done->review_resolution)->toBe('written_off')->and($done->review_note)->toContain('تتحمل المنصة')
        ->and(DB::table('creator_earning_entries')->count())->toBe($entries)->and(e43_available($world['account']))->toBe($available)
        ->and($done->netCreatorMinor())->toBe(350)
        ->and(e43_reason(fn () => $service->writeOff($world['admin'], $sale->public_id, 'محاولة ثانية بعد القرار النهائي')))->toBe('invalid_state')
        ->and(e43_reason(fn () => $service->retryReversal($world['admin'], $sale->public_id)))->toBe('invalid_state');
});

test('E43-RF-13: finance resolutions need the refund permission, and an admin cannot resolve a sale on their own earnings account', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_drain($world, '1.00');
    e43_refund($world, $bought);
    $sale = $bought['sale']->fresh();
    $service = app(CreatorDirectSaleService::class);

    expect(e43_reason(fn () => $service->retryReversal(e39_plain_staff(), $sale->public_id)))->toBe('forbidden')
        ->and(e43_reason(fn () => $service->writeOff(e39_plain_staff(), $sale->public_id, 'سبب كافٍ للتحميل')))->toBe('forbidden')
        ->and(e43_reason(fn () => $service->retryReversal($admin = e39_admin(), 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ')))->toBe('not_found');

    $creatorAsAdmin = $world['user'];
    $creatorAsAdmin->assignRole('administrator');
    expect(e43_reason(fn () => $service->writeOff($creatorAsAdmin->fresh(), $sale->public_id, 'محاولة معالجة أرباحي بنفسي')))->toBe('own_account');
});

test('E43-RF-14: reversal works even when the creator monetization was paused or the offer closed after the sale (refund is a fact, not a sale)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_offers()->retire($world['user'], $world['offer']->public_id);
    app(CreatorMonetizationService::class)->pause($world['account']->fresh(), $world['admin'], 'إيقاف تجريبي للتسييل');

    $refunded = e43_refund($world, $bought);

    expect($refunded->status)->toBe(DigitalPurchase::STATUS_REFUNDED)->and($bought['sale']->fresh()->status)->toBe('refunded');
});

// ───────────── إعادة الشراء ─────────────

test('E43-RF-15: after a refund the buyer can buy again - one access row is re-activated (grant_count 2) and the new sale is a new row with its own ledger key', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_refund($world, $bought);
    $firstSaleId = $bought['sale']->id;

    e43_tick(5);
    $again = e43_buy($world, $bought['user']);
    $access = e43_access_row($bought['user'], $world['series']);

    expect(CreatorSeriesAccess::query()->where('user_id', $bought['user']->id)->count())->toBe(1)->and($access->isActive())->toBeTrue()->and($access->grant_count)->toBe(2)
        ->and($access->source_digital_purchase_id)->toBe($again['purchase']->id)->and($access->revoked_at)->toBeNull()
        ->and(CreatorDirectSale::query()->count())->toBe(2)->and($again['sale']->id)->not->toBe($firstSaleId)
        ->and(DB::table('creator_earning_entries')->where('source_key', 'direct_sale:'.$again['purchase']->id.':credit')->count())->toBe(1);
});

// ───────────── العمليات الإدارية ─────────────

test('E43-RF-16: refund operations need the refund permission, a valid reason and a note; parties cannot refund their own purchase', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $service = app(CreatorPremiumRefundService::class);
    $id = $bought['purchase']->public_id;

    expect(e43_reason(fn () => $service->refund(e39_plain_staff(), $id, 'user_request', 'ملاحظة كافية للتوثيق', false)))->toBe('forbidden')
        ->and(e43_reason(fn () => $service->refund($world['admin'], $id, 'not-a-reason', 'ملاحظة كافية للتوثيق', false)))->toBe('invalid_reason')
        ->and(e43_reason(fn () => $service->refund($world['admin'], $id, 'user_request', 'قصير', false)))->toBe('note_required')
        ->and(e43_reason(fn () => $service->refund($world['admin'], 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ', 'user_request', 'ملاحظة كافية للتوثيق', false)))->toBe('not_found');

    $creator = $world['user'];
    $creator->assignRole('administrator');
    expect(e43_reason(fn () => $service->refund($creator->fresh(), $id, 'user_request', 'ملاحظة كافية للتوثيق', false)))->toBe('own_account');
});

test('E43-RF-17: the series refund service only touches creator-series purchases (a pass purchase id is not found)', function () {
    $world = e43_world();
    $build = e41_world();
    $hosted = e41_hosted($build);
    e41_pay($hosted['session']);

    expect(e43_reason(fn () => app(CreatorPremiumRefundService::class)->refund($world['admin'], $hosted['purchase']->public_id, 'user_request', 'ملاحظة كافية للتوثيق', false)))->toBe('not_found');
});

test('E43-RF-18: a late/unmatched payment on an expired order is refunded at the provider and the review closes, with no access or sale ever created', function () {
    $world = e43_world();
    $buyer = e16User();
    $order = e43_order($buyer, $world['series']);
    DB::table('digital_purchases')->where('id', $order['purchase']->id)->update(['expires_at' => now()->subMinute()]);
    e43_purchases()->request($buyer, $world['series']); // يُنهي الطلب القديم ويُنشئ بديلًا
    e41_pay($order['session'], [], 'late-refund-1');

    $late = $order['purchase']->fresh();
    expect($late->needsReview())->toBeTrue();

    $done = app(CreatorPremiumRefundService::class)->refundUnmatched($world['admin'], $late->public_id, 'استرداد دفعة متأخرة على طلب منتهٍ');

    expect($done->review_resolved_at)->not->toBeNull()->and($done->provider_refund_reference)->not->toBeNull()
        ->and(CreatorSeriesAccess::query()->count())->toBe(0)->and(CreatorDirectSale::query()->count())->toBe(0);
});

test('E43-RF-19: every refund decision is audited', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_refund($world, $bought);

    expect(DB::table('operational_audit_logs')->where('action', 'creator_premium_refund_requested')->count())->toBe(1)
        ->and(DB::table('operational_audit_logs')->where('action', 'creator_direct_sale_reversed')->count())->toBe(1);
});
