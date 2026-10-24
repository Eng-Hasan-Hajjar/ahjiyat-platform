<?php

use App\Models\DigitalPurchase;
use App\Models\DigitalPurchaseEvent;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| E40 / AX7 قفل المميز، AX8 شراء المميز (يدوي، خادم هو السلطة)، AX9 أثر رجعي.
*/

beforeEach(function () {
    e40_seed();
    Storage::fake('local');
});

/** مسار 12 شريحة بمكافأة مجانية ومميزة على كل شريحة، نقاط المهمة 100. */
function e40_full_track(array $over = []): array
{
    return e40_build($over + [
        'tiers' => 12, 'step' => 100,
        'free_tiers' => range(1, 12), 'premium_tiers' => range(1, 12),
        'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]],
    ]);
}

// ───────────────────────────── AX7: قفل المميز ─────────────────────────────

test('AX7-44: a free player sees premium rewards locked, never claimable', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3); // شريحة 4

    $rows = app(\App\Services\AhjiyatPass\PassPageData::class)->forUser($user, $b['pass'])['tiers'];

    expect($rows[0]['premium']['state'])->toBe('locked_premium')
        ->and($rows[3]['premium']['state'])->toBe('locked_premium')
        ->and($rows[3]['free']['state'])->toBe('claimable')
        ->and($rows[4]['premium']['state'])->toBe('locked_tier');

    $this->actingAs($user)->get(route('pass.index'))->assertOk()->assertSee('data-state="locked_premium"', false);
});

test('AX7-45: a premium reward cannot be claimed without premium (service and route)', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $premiumId = (int) $b['rewards'][1]['premium']->id;

    expect(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], $premiumId)))->toBe('premium_required');
    $this->actingAs($user)->post(route('pass.collect', $premiumId))->assertSessionHas('error');
    expect(UserPassRewardClaim::query()->count())->toBe(0);
});

test('AX7-46: the client cannot spoof premium — no parameter, header or mass assignment unlocks it', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $premiumId = (int) $b['rewards'][1]['premium']->id;

    $this->actingAs($user)->post(route('pass.collect', $premiumId), ['premium' => 1, 'has_premium' => true, 'premium_unlocked_at' => now()->toDateTimeString(), 'track' => 'free'], ['X-Premium' => '1'])
        ->assertSessionHas('error');
    $this->actingAs($user)->post(route('pass.collect_all'), ['pass' => $b['pass']->public_id, 'premium' => 1]);

    expect(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe(0)
        ->and(e40_row($user, $b['pass'])->premium_unlocked_at)->toBeNull();

    expect(fn () => UserPassProgress::query()->create(['user_id' => $user->id, 'ahjiyat_pass_id' => $b['pass']->id, 'premium_unlocked_at' => now()]))
        ->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('AX7-47: premium ownership has nothing to do with gems or any in-game balance', function () {
    $b = e40_full_track();
    $user = e40_player();
    DB::table('wallets')->where('user_id', $user->id)->exists() || DB::table('wallets')->insert(['user_id' => $user->id, 'pending_balance' => 0, 'available_balance' => 9_000_000, 'lifetime_earned' => 9_000_000, 'lifetime_redeemed' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wallets')->where('user_id', $user->id)->update(['available_balance' => 9_000_000]);
    e40_grant($user, $b['pass'], $b['quests'][0], 3);

    expect(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][1]['premium']->id)))->toBe('premium_required');

    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'pass'))->map(fn ($r) => $r->uri())->implode(' ');
    expect($routes)->not->toContain('gem')->and($routes)->not->toContain('wallet')->and($routes)->not->toContain('buy-tier')->and($routes)->not->toContain('buy-points');
});

// ───────────────────────────── AX8: الشراء ─────────────────────────────

test('AX8-48: the price comes from the server-side pass definition and is snapshotted on the order', function () {
    $b = e40_full_track(['price' => 499, 'currency' => 'USD', 'name' => 'مسار الشتاء']);
    $user = e40_player();

    $order = e40_premium()->request($user, $b['pass']);

    expect($order->amount_minor)->toBe(499)->and($order->currency)->toBe('USD')->and($order->product_name)->toBe('مسار الشتاء')
        ->and($order->product_id)->toBe($b['pass']->id)->and($order->provider)->toBe('manual')->and($order->status)->toBe('awaiting_payment');

    // لقطة ثابتة
    expect(fn () => $order->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $order->forceFill(['product_name' => 'اسم آخر'])->save())->toThrow(LogicException::class);
});

test('AX8-49: money is stored as integer minor units with an explicit currency, never floats', function () {
    $columns = collect(DB::select("select name, type from pragma_table_info('digital_purchases')"))->keyBy('name');

    expect(strtolower($columns['amount_minor']->type))->toContain('int')
        ->and(collect($columns)->filter(fn ($c) => str_contains(strtolower($c->type), 'float') || str_contains(strtolower($c->type), 'double') || str_contains(strtolower($c->type), 'decimal'))->isEmpty())->toBeTrue();

    $admin = e40_admin();
    $draft = e40_builder()->createDraft($admin, ['internal_key' => 'price-int', 'name' => 'سعر', 'starts_at' => now()->format('Y-m-d H:i:s'), 'premium_price_minor' => '499', 'currency' => 'usd']);
    expect($draft->premium_price_minor)->toBe(499)->and($draft->currency)->toBe('USD');

    expect(e40_reason(fn () => e40_builder()->createDraft($admin, ['internal_key' => 'price-float', 'name' => 'سعر', 'starts_at' => now()->format('Y-m-d H:i:s'), 'premium_price_minor' => '4.99', 'currency' => 'USD'])))->toBe('price');

    $viaDecimalString = e40_builder()->createDraft($admin, ['internal_key' => 'price-str', 'name' => 'سعر', 'starts_at' => now()->format('Y-m-d H:i:s'), 'premium_price' => '4.99', 'currency' => 'USD']);
    expect($viaDecimalString->premium_price_minor)->toBe(499);
});

test('AX8-50: requesting twice returns the same open order (idempotent)', function () {
    $b = e40_full_track();
    $user = e40_player();

    $first = e40_premium()->request($user, $b['pass']);
    $second = e40_premium()->request($user, $b['pass']);
    $third = e40_premium()->request($user, $b['pass'], 'client-key-12345');

    expect($second->id)->toBe($first->id)->and($third->id)->toBe($first->id)->and(DigitalPurchase::query()->count())->toBe(1);

    $again = e40_premium()->request($user, $b['pass'], 'client-key-12345');
    expect($again->id)->toBe($first->id);
});

test('AX8-50b: the same request key from two POSTs creates one order', function () {
    $b = e40_full_track();
    $user = e40_player();

    $this->actingAs($user)->post(route('pass.orders.store'), ['request_key' => 'abcdef123456'])->assertRedirect();
    $this->actingAs($user)->post(route('pass.orders.store'), ['request_key' => 'abcdef123456'])->assertRedirect();

    expect(DigitalPurchase::query()->count())->toBe(1);
});

test('AX8-51: a verified owner can never create or verify a second purchase for the same pass', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_buy_premium($user, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->request($user, $b['pass'])))->toBe('already_owned')
        ->and(e40_reason(fn () => e40_premium()->verify(e40_admin(), $order)))->toBe('invalid_state')
        ->and(DigitalPurchase::query()->count())->toBe(1);

    // والقيد على مستوى القاعدة: لا طلبان محتفظان بـopen_key لنفس (منتج، مستخدم)
    $dupe = DB::table('digital_purchases')->where('id', $order->id)->first();
    expect(fn () => DB::table('digital_purchases')->insert([
        'public_id' => (string) \Illuminate\Support\Str::ulid(), 'user_id' => $user->id, 'product_type' => $dupe->product_type, 'product_id' => $dupe->product_id,
        'product_public_id' => $dupe->product_public_id, 'product_name' => 'x', 'amount_minor' => 1, 'currency' => 'USD', 'provider' => 'manual', 'status' => 'verified',
        'open_key' => $dupe->open_key, 'reference_code' => 'PASS-DUPLICATE', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('AX8-52: an unverified purchase (awaiting or submitted) grants no premium access', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);

    $order = e40_premium()->request($user, $b['pass']);
    expect(e40_row($user, $b['pass'])->premium_unlocked_at)->toBeNull();

    e40_premium()->submit($user, $order, 'TRX-111222', 'تحويل');
    expect(e40_row($user, $b['pass'])->premium_unlocked_at)->toBeNull()
        ->and(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][1]['premium']->id)))->toBe('premium_required');
});

test('AX8-53: a verified purchase grants premium, links the purchase and notifies the buyer', function () {
    $b = e40_full_track();
    $user = e40_player();
    $verified = e40_buy_premium($user, $b['pass'], $b['admin']);
    $row = e40_row($user, $b['pass']);

    expect($verified->status)->toBe('verified')->and($verified->verified_by)->toBe($b['admin']->id)
        ->and($row->premium_unlocked_at)->not->toBeNull()->and((int) $row->premium_purchase_id)->toBe((int) $verified->id)
        ->and(e40_notes($user, 'pass_premium_unlocked'))->toHaveCount(1)
        ->and(DB::table('operational_audit_logs')->where('action', 'digital_purchase_verified')->count())->toBe(1);
});

test('AX8-53b: only an authorised finance reviewer can verify, reject or refund', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->submit($user, e40_premium()->request($user, $b['pass']), 'TRX-9988', null);

    $plain = e40_plain_staff();
    $viewer = e40_staff_with(['passes.view-purchases']);

    foreach ([$plain, $viewer, $user] as $actor) {
        expect(e40_reason(fn () => e40_premium()->verify($actor, $order)))->toBe('forbidden');
        expect(e40_reason(fn () => e40_premium()->reject($actor, $order, 'سبب')))->toBe('forbidden');
        expect(e40_reason(fn () => e40_premium()->refund($actor, $order, 'error', 'ملاحظة طويلة كفاية')))->toBe('forbidden');
    }

    expect($order->fresh()->status)->toBe('submitted')->and(e40_row($user, $b['pass'])?->premium_unlocked_at)->toBeNull();
});

test('AX8-54: another user can neither submit, cancel nor even see someone else’s order', function () {
    $b = e40_full_track();
    $owner = e40_player();
    $intruder = e40_player();
    $order = e40_premium()->request($owner, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->submit($intruder, $order, 'TRX-STEAL', null)))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_premium()->cancelByOwner($intruder, $order)))->toBe('forbidden');

    $this->actingAs($intruder)->get(route('pass.orders.show', $order->public_id))->assertNotFound();
    $this->actingAs($intruder)->post(route('pass.orders.submit', $order->public_id), ['transfer_reference' => 'TRX-STEAL'])->assertNotFound();
    $this->actingAs($intruder)->post(route('pass.orders.cancel', $order->public_id))->assertNotFound();
    expect($order->fresh()->status)->toBe('awaiting_payment');
});

test('AX8-54b: a posted user id is ignored — the order always belongs to the signed-in user', function () {
    $b = e40_full_track();
    $me = e40_player();
    $victim = e40_player();

    $this->actingAs($me)->post(route('pass.orders.store'), ['user_id' => $victim->id, 'user' => $victim->id])->assertRedirect();

    expect(DigitalPurchase::query()->first()->user_id)->toBe($me->id)->and(DigitalPurchase::query()->where('user_id', $victim->id)->count())->toBe(0);
});

test('AX8-55: price, currency, pass and status posted by the client are ignored', function () {
    $b = e40_full_track(['price' => 750, 'currency' => 'EUR']);
    $user = e40_player();

    $this->actingAs($user)->post(route('pass.orders.store'), ['amount' => 1, 'amount_minor' => 1, 'price' => '0.01', 'currency' => 'USD', 'pass_id' => 999, 'status' => 'verified', 'product_id' => 1])->assertRedirect();
    $order = DigitalPurchase::query()->first();

    expect($order->amount_minor)->toBe(750)->and($order->currency)->toBe('EUR')->and($order->product_id)->toBe($b['pass']->id)->and($order->status)->toBe('awaiting_payment');

    $this->actingAs($user)->post(route('pass.orders.submit', $order->public_id), ['transfer_reference' => 'TRX-1234', 'status' => 'verified', 'amount_minor' => 1])->assertRedirect();
    expect($order->fresh()->status)->toBe('submitted')->and($order->fresh()->amount_minor)->toBe(750);
});

test('AX8-56: purchases are rejected outside the sales window, after the pass ended, and when premium is off', function () {
    $closed = e40_full_track(['sales_end' => now()->subHour()->format('Y-m-d H:i:s'), 'sales_start' => now()->subDays(5)->format('Y-m-d H:i:s')]);
    $user = e40_player();
    expect(e40_reason(fn () => e40_premium()->request($user, $closed['pass'])))->toBe('sales_closed');
});

test('AX8-56b: a sales window that has not opened yet blocks purchase until it does', function () {
    $b = e40_full_track(['sales_start' => now()->addDays(2)->format('Y-m-d H:i:s')]);
    $user = e40_player();

    expect(e40_reason(fn () => e40_premium()->request($user, $b['pass'])))->toBe('sales_closed');

    Carbon::setTestNow(now()->addDays(3));
    e40_cache()->flush();
    expect(e40_premium()->request($user, $b['pass']->fresh())->exists)->toBeTrue();
});

test('AX8-56c: premium disabled passes cannot be bought and the page offers nothing to buy', function () {
    $b = e40_build(['premium' => false]);
    $user = e40_player();

    expect(e40_reason(fn () => e40_premium()->request($user, $b['pass'])))->toBe('sales_closed');
    $this->actingAs($user)->get(route('pass.index'))->assertOk()->assertDontSee(route('pass.orders.store'));
});

test('AX8-56d: a provider that is switched off blocks new orders', function () {
    $b = e40_full_track();
    config(['payments.providers.manual.enabled' => false]);

    expect(e40_reason(fn () => e40_premium()->request(e40_player(), $b['pass'])))->toBe('provider_disabled');
});

test('AX8-57: a frozen account cannot request, submit or be verified', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);
    $user->forceFill(['is_frozen' => true])->save();

    expect(e40_reason(fn () => e40_premium()->request($user->fresh(), $b['pass'])))->toBe('frozen')
        ->and(e40_reason(fn () => e40_premium()->submit($user->fresh(), $order, 'TRX-FROZEN1', null)))->toBe('frozen');

    $user->forceFill(['is_frozen' => false])->save();
    $submitted = e40_premium()->submit($user->fresh(), $order, 'TRX-FROZEN1', null);
    $user->forceFill(['is_frozen' => true])->save();

    expect(e40_reason(fn () => e40_premium()->verify($b['admin'], $submitted)))->toBe('frozen')
        ->and(e40_row($user, $b['pass'])?->premium_unlocked_at)->toBeNull();
});

test('AX8-58: payment history is retained across every outcome and cannot be edited or deleted', function () {
    $b = e40_full_track();
    $a = e40_player();
    $c = e40_player();
    $d = e40_player();

    $verified = e40_buy_premium($a, $b['pass']);
    $rejected = e40_premium()->reject($b['admin'], e40_premium()->submit($c, e40_premium()->request($c, $b['pass']), 'TRX-0000AA', null), 'الحوالة غير واضحة.');
    $cancelled = e40_premium()->cancelByOwner($d, e40_premium()->request($d, $b['pass']));

    expect(DigitalPurchase::query()->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'rejected', 'verified'])
        ->and(DigitalPurchaseEvent::query()->count())->toBeGreaterThanOrEqual(8);

    expect(fn () => $verified->delete())->toThrow(LogicException::class)
        ->and(fn () => DigitalPurchaseEvent::query()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => DigitalPurchaseEvent::query()->first()->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $rejected->forceFill(['status' => 'verified'])->save())->toThrow(LogicException::class)
        ->and(fn () => $cancelled->forceFill(['status' => 'submitted'])->save())->toThrow(LogicException::class);
});

test('AX8-59: a rejected order frees the player to place a new one, and tells them why', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->submit($user, e40_premium()->request($user, $b['pass']), 'TRX-77AA55', null);
    e40_premium()->reject($b['admin'], $order, 'المبلغ ناقص');

    expect(e40_notes($user, 'pass_purchase_rejected'))->toHaveCount(1);
    $new = e40_premium()->request($user, $b['pass']);

    expect($new->id)->not->toBe($order->id)->and($new->status)->toBe('awaiting_payment');
});

test('AX8-60: stale awaiting orders expire, submitted ones never expire automatically', function () {
    $b = e40_full_track();
    $stale = e40_player();
    $kept = e40_player();
    e40_premium()->request($stale, $b['pass']);
    e40_premium()->submit($kept, e40_premium()->request($kept, $b['pass']), 'TRX-KEEP-1', null);

    Carbon::setTestNow(now()->addHours(60));

    expect(e40_premium()->expireStale())->toBe(1)
        ->and(DigitalPurchase::query()->where('user_id', $stale->id)->value('status'))->toBe('expired')
        ->and(DigitalPurchase::query()->where('user_id', $kept->id)->value('status'))->toBe('submitted')
        ->and(e40_premium()->expireStale())->toBe(0);
});

test('AX8-61: submitting an expired order is refused and the expiry is committed (not rolled back)', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);
    Carbon::setTestNow(now()->addHours(60));

    expect(e40_reason(fn () => e40_premium()->submit($user, $order, 'TRX-LATE-01', null)))->toBe('expired')
        ->and($order->fresh()->status)->toBe('expired');
});

test('AX8-62: card numbers are refused anywhere in the transfer reference or note, normal references are accepted', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);

    foreach (['4242 4242 4242 4242', '4111-1111-1111-1111', 'payment 5555555555554444 done'] as $card) {
        expect(e40_reason(fn () => e40_premium()->submit($user, $order, $card, null)))->toBe('card_data')
            ->and(e40_reason(fn () => e40_premium()->submit($user, $order, 'TRX-OK-0001', $card)))->toBe('card_data');
    }

    expect($order->fresh()->status)->toBe('awaiting_payment')
        ->and(e40_premium()->submit($user, $order, 'TRX-20261210-0042', 'حوالة بنكية')->status)->toBe('submitted');
});

test('AX8-63: evidence is required — empty submissions are refused', function () {
    $b = e40_full_track();
    $user = e40_player();

    expect(e40_reason(fn () => e40_premium()->submit($user, e40_premium()->request($user, $b['pass']), null, 'ملاحظة فقط')))->toBe('evidence_required');
});

test('AX8-64: a proof image is stored privately, hashed, and never exposed by URL', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);

    $submitted = e40_premium()->submit($user, $order, 'TRX-PROOF-1', null, UploadedFile::fake()->image('proof.png', 40, 40));

    expect($submitted->proof_path)->not->toBeNull()->and($submitted->proof_sha256)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($submitted->proof_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($submitted->proof_path))->toBeFalse();

    $this->actingAs($user)->get(route('pass.orders.show', $order->public_id))->assertOk()->assertDontSee($submitted->proof_path)->assertDontSee($submitted->proof_sha256);
});

test('AX8-65: unsupported proof files are refused and nothing is left behind', function () {
    $b = e40_full_track();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->submit($user, $order, 'TRX-BADFILE', null, UploadedFile::fake()->create('proof.exe', 10, 'application/x-msdownload'))))->toBe('proof_invalid')
        ->and($order->fresh()->status)->toBe('awaiting_payment')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

// ───────────────────────────── AX9: المميز بأثر رجعي ─────────────────────────────

test('AX9-59/60/61: a free player at tier 10 who buys premium keeps tier 10', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 9); // 900 => شريحة 10
    expect(e40_tier_of($user, $b['pass']))->toBe(10);

    e40_buy_premium($user, $b['pass']);

    expect(e40_tier_of($user, $b['pass']))->toBe(10)->and(e40_points_of($user, $b['pass']))->toBe(900);
});

test('AX9-62/63: premium rewards for tiers 1–10 become claimable; 11+ stay locked', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 9);
    e40_buy_premium($user, $b['pass']);

    $result = e40_claims()->collectAll($user, $b['pass']);
    $premiumClaims = UserPassRewardClaim::query()->where('user_id', $user->id)->where('track', 'premium')->pluck('tier_number')->sort()->values()->all();

    expect($premiumClaims)->toBe(range(1, 10))
        ->and(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][11]['premium']->id)))->toBe('not_reached')
        ->and($result['failed'])->toBe([]);
});

test('AX9-64: no free reward is duplicated by the retroactive unlock', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 9);
    e40_claims()->collectAll($user, $b['pass']); // الحرّ أولًا: 10 مجانية
    expect(UserPassRewardClaim::query()->where('track', 'free')->count())->toBe(10);

    e40_buy_premium($user, $b['pass']);
    $second = e40_claims()->collectAll($user, $b['pass']);

    expect($second['claimed'])->toHaveCount(10)
        ->and(UserPassRewardClaim::query()->where('track', 'free')->count())->toBe(10)
        ->and(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe(10)
        ->and(UserPassRewardClaim::query()->selectRaw('ahjiyat_pass_tier_reward_id, COUNT(*) c')->groupBy('ahjiyat_pass_tier_reward_id')->get()->max('c'))->toBe(1);
});

test('AX9-65: buying premium before earning anything works and later progress unlocks premium rewards as tiers are reached', function () {
    $b = e40_full_track();
    $user = e40_player();
    e40_buy_premium($user, $b['pass']);

    expect(e40_claims()->collectAll($user, $b['pass'])['claimed'])->toHaveCount(2); // الشريحة 1: مجاني + مميز

    e40_grant($user, $b['pass'], $b['quests'][0], 2); // 200 => شريحة 3
    expect(e40_claims()->collectAll($user, $b['pass'])['claimed'])->toHaveCount(4); // الشريحتان 2 و3: مجاني + مميز لكلٍّ
});

test('AX9-66: premium is per pass — owning it in one pass does not carry to the next', function () {
    $b1 = e40_full_track(['starts' => now()->subDays(2), 'days' => 10]);
    $user = e40_player();
    e40_buy_premium($user, $b1['pass']);

    $b2 = e40_full_track(['starts' => now()->addDays(9), 'days' => 20]);
    Carbon::setTestNow(now()->addDays(10));
    e40_cache()->flush();
    e40_life()->sync();

    $row2 = e40_progress()->ensure($user, $b2['pass']->fresh());
    expect($row2->premium_unlocked_at)->toBeNull();
    $order = e40_premium()->request($user, $b2['pass']->fresh());
    expect($order->product_id)->toBe($b2['pass']->id);
});

test('AX9-67: the unlock is not subscription-like — no renewal, expiry or recurring field exists', function () {
    $columns = array_merge(\Illuminate\Support\Facades\Schema::getColumnListing('digital_purchases'), \Illuminate\Support\Facades\Schema::getColumnListing('user_pass_progress'));

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/renew|recurr|subscri|auto_?pay|interval|next_?bill/');
    }

    $b = e40_full_track();
    $user = e40_player();
    e40_buy_premium($user, $b['pass']);
    Carbon::setTestNow(now()->addDays(40));
    e40_life()->sync();
    expect(e40_row($user, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});
