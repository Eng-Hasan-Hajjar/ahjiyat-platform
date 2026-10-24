<?php

use App\Models\DigitalPurchase;
use App\Models\Puzzle;
use App\Models\UserInventoryItem;
use App\Models\UserPassRewardClaim;
use App\Services\PuzzleAttemptService;
use App\Services\TrustSafety\RestrictedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../TrustSafetyE38/_support.php';

/*
| E40 / AX11 الاسترداد، AX12 عزل الاقتصاد (لا أثر على المنافسة/السمعة/الإحالة/الرعاية/المبدعين/الفرق)، AX13 الثقة والسلامة.
*/

beforeEach(function () {
    e40_seed();
    Storage::fake('local');
});

function e40_track12(array $over = []): array
{
    return e40_build($over + [
        'tiers' => 12, 'step' => 100,
        'free_tiers' => range(1, 12), 'premium_tiers' => range(1, 12),
        'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]],
    ]);
}

// ───────────────────────────── AX11: الاسترداد ─────────────────────────────

test('AX11-70: an unclaimed verified purchase is refunded safely without any extra confirmation', function () {
    $b = e40_track12();
    $user = e40_player();
    $order = e40_buy_premium($user, $b['pass']);

    $refunded = e40_premium()->refund($b['admin'], $order, 'user_request', 'طلب المستخدم قبل الاستخدام');

    expect($refunded->status)->toBe('refunded')->and($refunded->refund_reason)->toBe('user_request')->and($refunded->refund_kept_items)->toBe(0)
        ->and($refunded->refunded_by)->toBe($b['admin']->id)->and($refunded->refunded_at)->not->toBeNull();
});

test('AX11-71: premium access is revoked on refund — premium rewards lock again, free progress stays', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    $order = e40_buy_premium($user, $b['pass']);
    e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][1]['free']->id);

    e40_premium()->refund($b['admin'], $order, 'payment_issue', 'دفعة معكوسة لدى المصرف');
    $row = e40_row($user, $b['pass']);

    expect($row->premium_unlocked_at)->toBeNull()->and($row->premium_purchase_id)->toBeNull()
        ->and($row->pass_points)->toBe(400)->and($row->highest_tier)->toBe(5)
        ->and(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['premium']->id)))->toBe('premium_required')
        ->and(UserPassRewardClaim::query()->where('track', 'free')->count())->toBe(1);
});

test('AX11-72: once premium rewards were claimed, refund demands explicit finance confirmation and never silently pulls items', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    $order = e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);
    $premiumClaims = UserPassRewardClaim::query()->where('track', 'premium')->count();
    $itemsBefore = (int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity');
    expect($premiumClaims)->toBe(5);

    $e = null;
    try {
        e40_premium()->refund($b['admin'], $order, 'fraud', 'مراجعة مالية لتحويل مشبوه');
    } catch (\App\Services\AhjiyatPass\PassException $e) {
    }

    expect($e?->reason)->toBe('claimed_requires_confirmation')->and($order->fresh()->status)->toBe('verified')
        ->and(e40_row($user, $b['pass'])->premium_unlocked_at)->not->toBeNull();

    $done = e40_premium()->refund($b['admin'], $order, 'fraud', 'مراجعة مالية لتحويل مشبوه', true);

    expect($done->status)->toBe('refunded')->and($done->refund_kept_items)->toBe(5)
        ->and((int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity'))->toBe($itemsBefore)
        ->and(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe($premiumClaims);

    $audit = DB::table('operational_audit_logs')->where('action', 'digital_purchase_refunded')->first();
    expect(json_decode($audit->metadata ?? $audit->meta ?? '{}', true)['kept_premium_items'] ?? null)->toBe(5);
});

test('AX11-73: refund history is retained — purchase, events, ledger and claims all remain', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $order = e40_buy_premium($user, $b['pass']);
    $events = DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->count();

    e40_premium()->refund($b['admin'], $order, 'duplicate', 'طلب مكرر لدى المزوّد');

    expect(DigitalPurchase::query()->find($order->id)->status)->toBe('refunded')
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->count())->toBe($events + 1)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->orderByDesc('id')->value('event'))->toBe('refunded')
        ->and(DB::table('pass_point_grants')->where('user_id', $user->id)->count())->toBe(3)
        ->and(fn () => DigitalPurchase::query()->find($order->id)->delete())->toThrow(LogicException::class)
        ->and(e40_reason(fn () => e40_premium()->refund($b['admin'], $order->fresh(), 'duplicate', 'مرة ثانية ملاحظة')))->toBe('invalid_state');
});

test('AX11-74: a refund never touches gems, XP or any wallet', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $order = e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);
    $before = e40_economy($user);
    $foreign = e40_foreign_counts();

    e40_premium()->refund($b['admin'], $order, 'error', 'خطأ إداري في التحقق', true);

    expect(e40_economy($user))->toBe($before)->and(e40_foreign_counts())->toBe($foreign);
});

test('AX11-75: refund requires a documented note, a known reason and the refund permission', function () {
    $b = e40_track12();
    $user = e40_player();
    $order = e40_buy_premium($user, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->refund($b['admin'], $order, 'user_request', 'ok')))->toBe('note_required')
        ->and(e40_reason(fn () => e40_premium()->refund($b['admin'], $order, 'because', 'ملاحظة كافية')))->toBe('invalid_reason')
        ->and(e40_reason(fn () => e40_premium()->refund(e40_staff_with(['passes.verify-purchases']), $order, 'error', 'ملاحظة كافية')))->toBe('forbidden')
        ->and($order->fresh()->status)->toBe('verified');
});

test('AX11-76: only verified purchases can be refunded', function () {
    $b = e40_track12();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);

    expect(e40_reason(fn () => e40_premium()->refund($b['admin'], $order, 'error', 'ملاحظة كافية')))->toBe('invalid_state');
});

test('AX11-77: after a refund the player may buy again, but never receives a premium reward twice', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $order = e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);
    e40_premium()->refund($b['admin'], $order, 'payment_issue', 'استرداد مؤقت للتحقق', true);

    e40_buy_premium($user, $b['pass']);
    $again = e40_claims()->collectAll($user, $b['pass']);

    expect($again['claimed'])->toBe([])->and(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe(4)
        ->and(DigitalPurchase::query()->where('status', 'refunded')->count())->toBe(1)->and(DigitalPurchase::query()->where('status', 'verified')->count())->toBe(1);
});

test('AX11-78: refunding one player never affects another player’s premium', function () {
    $b = e40_track12();
    $one = e40_player();
    $two = e40_player();
    $orderOne = e40_buy_premium($one, $b['pass']);
    e40_buy_premium($two, $b['pass']);

    e40_premium()->refund($b['admin'], $orderOne, 'user_request', 'طلب المستخدم الأول');

    expect(e40_row($two, $b['pass'])->premium_unlocked_at)->not->toBeNull()->and(e40_row($one, $b['pass'])->premium_unlocked_at)->toBeNull();
});

// ───────────────────────────── AX12: عزل الاقتصاد ─────────────────────────────

test('AX12-75: premium gives no competitive multiplier — identical puzzle rewards for premium and free players', function () {
    $b = e40_track12();
    $premium = e40_player();
    $free = e40_player();
    e40_buy_premium($premium, $b['pass']);

    $solve = function ($user) {
        $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 10, 'gem_reward' => 5]);
        $before = e40_economy($user);
        app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
        $after = e40_economy($user);

        return array_map(fn ($k) => $after[$k] - $before[$k], array_keys($before));
    };

    expect($solve($premium))->toBe($solve($free));
});

test('AX12-76: buying premium, earning points and claiming change no ranking or competition table', function () {
    $b = e40_track12();
    $user = e40_player();
    $before = e40_foreign_counts();

    e40_grant($user, $b['pass'], $b['quests'][0], 6);
    e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);

    expect(e40_foreign_counts())->toBe($before);
});

test('AX12-77: no competitive, season, leaderboard or team code depends on the pass', function () {
    $needles = ['AhjiyatPass', 'PassPoint', 'DigitalPurchase', 'UserPassProgress', 'premium_unlocked'];

    foreach (['app/Services/Competitive', 'app/Services/Teams', 'app/Services/Progression', 'app/Services/Economy'] as $dir) {
        expect(e40_scan_for($dir, $needles))->toBe([]);
    }

    foreach (['app/Services/LeaderboardService.php', 'app/Services/SeasonLifecycleService.php', 'app/Services/GemWalletService.php', 'app/Services/PuzzleAttemptService.php'] as $file) {
        $source = file_get_contents(base_path($file));

        foreach ($needles as $needle) {
            expect(stripos($source, $needle))->toBeFalse("{$file} mentions {$needle}");
        }
    }
});

test('AX12-78: creator revenue never counts pass purchases or points', function () {
    $needles = ['AhjiyatPass', 'DigitalPurchase', 'digital_purchases', 'PassPoint'];

    expect(e40_scan_for('app/Services/CreatorFinance', $needles))->toBe([])
        ->and(e40_scan_for('app/Services/Creators', $needles))->toBe([]);

    $before = e40_foreign_counts();
    $b = e40_track12();
    e40_buy_premium(e40_player(), $b['pass']);
    expect(e40_foreign_counts()['creator_earning_entries'])->toBe($before['creator_earning_entries']);
});

test('AX12-79: reputation is untouched by anything the pass does', function () {
    expect(e40_scan_for('app/Services/Reputation', ['AhjiyatPass', 'DigitalPurchase', 'PassPoint']))->toBe([]);

    $b = e40_track12();
    $user = e40_player();
    $before = DB::table('community_reputation_snapshots')->count();
    e40_grant($user, $b['pass'], $b['quests'][0], 5);
    e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);

    expect(DB::table('community_reputation_snapshots')->count())->toBe($before);
});

test('AX12-80: referrals never qualify from pass activity and referral code ignores the pass', function () {
    expect(e40_scan_for('app/Services/Referrals', ['AhjiyatPass', 'DigitalPurchase', 'PassPoint', 'premium']))->toBe([]);

    $b = e40_track12();
    $referrer = e40_player();
    $invitee = e40_player();
    $before = DB::table('user_referrals')->count();
    e40_grant($invitee, $b['pass'], $b['quests'][0], 6);
    e40_buy_premium($invitee, $b['pass']);

    expect(DB::table('user_referrals')->count())->toBe($before);
});

test('AX12-81: sponsorship stays separate — no shared tables, services or payment rows', function () {
    expect(e40_scan_for('app/Services/Sponsorship', ['AhjiyatPass', 'DigitalPurchase', 'PassPoint']))->toBe([]);

    $b = e40_track12();
    $payments = DB::table('sponsorship_payments')->count();
    $contributions = DB::table('sponsorship_contributions')->count();
    e40_buy_premium(e40_player(), $b['pass']);

    expect(DB::table('sponsorship_payments')->count())->toBe($payments)->and(DB::table('sponsorship_contributions')->count())->toBe($contributions)
        ->and(DB::table('digital_purchases')->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('digital_purchases', 'sponsorship_payment_id'))->toBeFalse();
});

test('AX12-82: team competition gets no advantage — team tables untouched and no team code reads premium', function () {
    $b = e40_track12();
    $before = e40_foreign_counts();
    e40_buy_premium(e40_player(), $b['pass']);

    expect(e40_foreign_counts())->toBe($before)->and(e40_scan_for('app/Services/Teams', ['premium', 'AhjiyatPass']))->toBe([]);
});

test('AX12-83: store purchases stay independent — pass rewards are not purchasable and the pass never reuses StorePurchase', function () {
    $b = e40_track12();
    $item = $b['rewards'][2]['free']->item;

    expect($item->isPassExclusive())->toBeTrue()->and($item->isPurchasable())->toBeFalse()
        ->and(e40_scan_for('app/Services/AhjiyatPass', ['StorePurchase']))->toBe([]);

    $user = e40_player();
    $this->actingAs($user)->get(route('store.items.show', $item))->assertNotFound();
    $this->actingAs($user)->get(route('store.index'))->assertOk()->assertDontSee($item->name);
});

test('AX12-84: pass rewards are cosmetic or access only — never gems, cash, rank or real prizes', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 3])['pass'];
    $tier = \App\Models\AhjiyatPassTier::query()->where('ahjiyat_pass_id', $draft->id)->first();

    foreach ([\App\Models\StoreItem::factory()->manual()->create(), \App\Models\StoreItem::factory()->create(['item_type' => 'consumable'])] as $bad) {
        expect(e40_reason(fn () => e40_builder()->setTierReward($admin, $tier, 'free', $bad)))->toBeIn(['item_type', 'item_fulfillment']);
    }

    expect(config('ahjiyat_pass.rewards.currency_rewards_supported'))->toBeFalse();
});

// ───────────────────────────── AX13: الثقة والسلامة ─────────────────────────────

test('AX13-82: premium status gives no moderation immunity — a premium account is frozen like any other', function () {
    $b = e40_track12();
    $subject = e40_player();
    e40_buy_premium($subject, $b['pass']);

    expect(e40_scan_for('app/Services/TrustSafety', ['AhjiyatPass', 'DigitalPurchase', 'premium_unlocked', 'PassPoint']))->toBe([]);

    [$case, $mod] = e38_user_case(e38_admin(), $subject);
    e38_apply($case, $mod, 'freeze_user');

    expect($subject->fresh()->is_frozen)->toBeTrue()->and(e40_row($subject, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});

test('AX13-83: a frozen user cannot purchase, submit, claim or open the pass pages', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $order = e40_premium()->request($user, $b['pass']);
    $user->forceFill(['is_frozen' => true])->save();

    $this->actingAs($user->fresh())->get(route('pass.index'))->assertRedirect(route('account.safety.index'));
    $this->actingAs($user->fresh())->post(route('pass.orders.store'))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($user->fresh())->post(route('pass.orders.submit', $order->public_id), ['transfer_reference' => 'TRX-FROZEN-2'])->assertRedirect()->assertSessionHas('error');
    $this->actingAs($user->fresh())->post(route('pass.collect', (int) $b['rewards'][2]['free']->id))->assertRedirect()->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('awaiting_payment')->and(UserPassRewardClaim::query()->count())->toBe(0);
});

test('AX13-84: premium is not a report-priority signal — reports from premium and free reporters get the same priority', function () {
    $b = e40_track12();
    $premiumReporter = e40_player();
    e40_buy_premium($premiumReporter, $b['pass']);
    $freeReporter = e40_player();

    $work = e36_work();
    $workTwo = e36_work();
    $a = e38_puzzle_report($premiumReporter, $work, 'spam');
    $c = e38_puzzle_report($freeReporter, $workTwo, 'spam');
    $mod = e38_mod();

    $caseA = e38_open([$a], $mod);
    $caseC = e38_open([$c], $mod);

    expect($caseA->priority)->toBe($caseC->priority);
});

test('AX13-85: appeal handling does not depend on purchases — same eligibility, same queue order by filing time', function () {
    $b = e40_track12();
    $premiumUser = e40_player();
    e40_buy_premium($premiumUser, $b['pass']);
    $freeUser = e40_player();

    $actions = [];

    foreach ([$freeUser, $premiumUser] as $subject) {
        [$case, $mod] = e38_user_case(e38_admin(), $subject);
        $actions[$subject->id] = e38_apply($case, $mod, 'freeze_user');
    }

    $appealFree = e38_appeals()->file($freeUser->fresh(), $actions[$freeUser->id], str_repeat('أعترض على هذا القرار لأنني لم أخالف. ', 3));
    \Carbon\Carbon::setTestNow(now()->addMinute());
    $appealPremium = e38_appeals()->file($premiumUser->fresh(), $actions[$premiumUser->id], str_repeat('أعترض على هذا القرار لأنني لم أخالف. ', 3));

    $reviewer = e38_admin();
    $queue = e38_appeals()->queue($reviewer, [])->pluck('id')->all();

    expect($appealFree->status)->toBe('pending')->and($appealPremium->status)->toBe('pending')
        ->and(array_search($appealFree->id, $queue, true))->toBeLessThan(array_search($appealPremium->id, $queue, true));

    e38_appeals()->claim($appealPremium, $reviewer);
    $decided = e38_appeals()->decide($appealPremium->fresh(), $reviewer, false, 'تم تأكيد القرار بعد المراجعة.');

    expect($decided->status)->not->toBe('pending')->and($premiumUser->fresh()->is_frozen)->toBeTrue();
});

test('AX13-86: the restricted safety context of a frozen account opens nothing in the pass', function () {
    $b = e40_track12();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3);
    $user->forceFill(['is_frozen' => true])->save();
    $context = [RestrictedAccess::KEY => ['user_id' => $user->id, 'until' => now()->addMinutes(20)->getTimestamp()]];

    $this->withSession($context)->get('/pass')->assertRedirect(route('login'));
    $this->withSession($context)->get('/pass/premium')->assertRedirect(route('login'));
    $this->withSession($context)->post('/pass/orders')->assertRedirect(route('login'));
    $this->withSession($context)->post('/pass/collect-all', ['pass' => $b['pass']->public_id])->assertRedirect(route('login'));
    $this->withSession($context)->post('/pass/collect/'.$b['rewards'][2]['free']->id)->assertRedirect(route('login'));

    expect(DigitalPurchase::query()->count())->toBe(0)->and(UserPassRewardClaim::query()->count())->toBe(0);
});

test('AX13-87: unverified accounts and guests never reach pass routes', function () {
    $b = e40_track12();
    $unverified = e16User(['email_verified_at' => null]);

    $this->get('/pass')->assertRedirect(route('login'));
    $this->post('/pass/orders')->assertRedirect(route('login'));
    $this->actingAs($unverified)->get('/pass')->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->post('/pass/orders')->assertRedirect(route('verification.notice'));

    expect(DigitalPurchase::query()->count())->toBe(0);
});
