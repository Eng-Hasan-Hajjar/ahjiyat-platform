<?php

use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\InventoryTransaction;
use App\Models\UserEntitlement;
use App\Models\UserInventoryItem;
use App\Models\UserPassRewardClaim;
use App\Services\AhjiyatPass\PassClaimService;
use App\Services\AhjiyatPass\PassTrack;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E40 / AX5 الشرائح (عتبات تراكمية)، AX6 المكافآت المجانية، AX10 «استلام الكل».
*/

beforeEach(fn () => e40_seed());

// ───────────────────────────── AX5: الشرائح ─────────────────────────────

test('AX5-31: generated thresholds are cumulative and deterministic (step + ramp)', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 1])['pass'];
    e40_builder()->generateTiers($admin, $draft, 6, 100, 20, 0);

    $points = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $draft->id)->orderBy('tier_number')->pluck('required_points')->all();

    // 0, +100, +120, +140, +160, +180
    expect($points)->toBe([0, 100, 220, 360, 520, 700]);

    e40_builder()->generateTiers($admin, $draft, 6, 100, 20, 0);
    expect(AhjiyatPassTier::query()->where('ahjiyat_pass_id', $draft->id)->orderBy('tier_number')->pluck('required_points')->all())->toBe($points);
});

test('AX5-32: the tier is computed correctly for any point total', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100]); // 0,100,200,300,400
    $track = e40_cache()->track($b['pass']);

    foreach ([0 => 1, 1 => 1, 99 => 1, 100 => 2, 150 => 2, 299 => 3, 300 => 4, 399 => 4, 400 => 5, 99999 => 5] as $points => $tier) {
        expect($track->tierAt($points))->toBe($tier, "points={$points}");
    }

    expect($track->maxTier())->toBe(5);
});

test('AX5-33: reaching an exact threshold reaches that tier (inclusive)', function () {
    $b = e40_build(['tiers' => 4, 'step' => 100, 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();

    e40_grant($user, $b['pass'], $b['quests'][0], 1);
    expect(e40_points_of($user, $b['pass']))->toBe(100)->and(e40_tier_of($user, $b['pass']))->toBe(2);
});

test('AX5-34: one grant can unlock several tiers at once', function () {
    $b = e40_build(['tiers' => 5, 'step' => 50, 'missions' => [['difficulty' => 'hard', 'points' => 175, 'max' => 14]]]); // 0,50,100,150,200
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 1);

    expect(e40_points_of($user, $b['pass']))->toBe(175)->and(e40_tier_of($user, $b['pass']))->toBe(4);
});

test('AX5-35: descending or non-ascending thresholds are rejected', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 2])['pass'];

    expect(e40_reason(fn () => e40_builder()->replaceTiers($admin, $draft, [['required_points' => 0], ['required_points' => 100], ['required_points' => 50]])))->toBe('tier_order');
});

test('AX5-36: duplicate (equal) thresholds are rejected and the database refuses a duplicate tier number', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 2])['pass'];

    expect(e40_reason(fn () => e40_builder()->replaceTiers($admin, $draft, [['required_points' => 0], ['required_points' => 100], ['required_points' => 100]])))->toBe('tier_order');

    expect(fn () => DB::table('ahjiyat_pass_tiers')->insert(['ahjiyat_pass_id' => $draft->id, 'tier_number' => 1, 'required_points' => 5, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

test('AX5-37: non-integer, negative and out-of-range thresholds are rejected', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 2])['pass'];

    foreach ([[['required_points' => '12.5']], [['required_points' => -1]], [['required_points' => 'abc']], [['required_points' => 5_000_000]], [['required_points' => 1.5]]] as $rows) {
        expect(e40_reason(fn () => e40_builder()->replaceTiers($admin, $draft, $rows)))->toBe('tier_points');
    }
});

test('AX5-38: the tier count is bounded by config', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 2])['pass'];

    expect(e40_reason(fn () => e40_builder()->generateTiers($admin, $draft, 101, 10)))->toBe('tier_count')
        ->and(e40_reason(fn () => e40_builder()->generateTiers($admin, $draft, 0, 10)))->toBe('tier_count')
        ->and(e40_reason(fn () => e40_builder()->generateTiers($admin, $draft, 5, 0)))->toBe('tier_step');
});

test('AX5-39: tier rows of a published pass cannot be edited or deleted even through the model', function () {
    $b = e40_build();

    $tier = AhjiyatPassTier::find($b['tiers'][3]->id);
    $tier->forceFill(['required_points' => 1]);

    expect(fn () => $tier->save())->toThrow(LogicException::class)
        ->and(fn () => AhjiyatPassTier::find($b['tiers'][3]->id)->delete())->toThrow(LogicException::class);
});

test('AX5-40: the track serializes and restores losslessly (cache safety)', function () {
    $b = e40_build(['tiers' => 8, 'step' => 70, 'ramp' => 9]);
    $track = e40_cache()->build($b['pass']);
    $copy = PassTrack::fromArray($track->toArray());

    foreach ([0, 70, 150, 999, 5000] as $p) {
        expect($copy->tierAt($p))->toBe($track->tierAt($p));
    }

    expect($copy->toArray())->toBe($track->toArray());
});

test('AX5-41: highest tier never goes down as points accumulate', function () {
    $b = e40_build();
    $user = e40_player();
    $previous = 0;

    foreach (range(1, 8) as $n) {
        e40_completion($user, $b['quests'][0], now()->subDays($n)->setTime(9, 0));
        e40_points()->healFor($user, $b['pass']);
        $tier = e40_tier_of($user, $b['pass']);
        expect($tier)->toBeGreaterThanOrEqual($previous);
        $previous = $tier;
    }
});

// ───────────────────────────── AX6: المكافآت المجانية ─────────────────────────────

test('AX6-37: a reached free reward is claimable and appears in the claimable list', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2); // 100 نقطة => شريحة 2

    $row = e40_row($user, $b['pass']);
    $claimable = e40_cache()->track($b['pass'])->claimable((int) $row->highest_tier, false, []);

    expect(collect($claimable)->pluck('id')->all())->toBe([(int) $b['rewards'][2]['free']->id]); // 1 ممیز مقفل، 2 مجانية
});

test('AX6-38: an unreached reward is not claimable', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);
    e40_grant($user, $b['pass'], $b['quests'][0], 1); // 50 => شريحة 1

    expect(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][4]['free']->id)))->toBe('not_reached')
        ->and(UserPassRewardClaim::query()->count())->toBe(0);
});

test('AX6-39: claiming grants the item exactly once and records an immutable claim', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $reward = $b['rewards'][2]['free'];

    $claim = e40_claims()->claim($user, $b['pass'], (int) $reward->id);

    expect($claim->tier_number)->toBe(2)->and($claim->track)->toBe('free')->and($claim->quantity)->toBe(1)
        ->and($claim->item_name)->toBe($reward->item->name)
        ->and(UserInventoryItem::query()->where('user_id', $user->id)->where('store_item_id', $reward->store_item_id)->value('quantity'))->toBe(1);

    $row = UserPassRewardClaim::query()->first();
    expect(fn () => $row->forceFill(['quantity' => 9])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

test('AX6-40: a double click is idempotent — second attempt is refused and nothing is granted twice', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $id = (int) $b['rewards'][2]['free']->id;

    e40_claims()->claim($user, $b['pass'], $id);

    expect(e40_reason(fn () => e40_claims()->claim($user, $b['pass'], $id)))->toBe('already_claimed')
        ->and(UserPassRewardClaim::query()->count())->toBe(1)
        ->and((int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity'))->toBe(1)
        ->and(InventoryTransaction::query()->where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($user)->post(route('pass.collect', $id))->assertRedirect()->assertSessionHas('error');
    expect(UserPassRewardClaim::query()->count())->toBe(1);
});

test('AX6-41: a lost race maps to already_claimed and leaves no partial grant', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $reward = $b['rewards'][2]['free'];

    // يحاكي فوز طلب متزامن بإدراج مطالبة الصف نفسه بعد فحص الوجود وقبل إدراجنا (نفس الاتصال داخل المعاملة).
    app()->bind(InventoryService::class, fn () => new class(app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)) extends InventoryService
    {
        public function grant($user, $item, int $quantity, string $reason, ?\Illuminate\Database\Eloquent\Model $reference = null): InventoryTransaction
        {
            $tx = parent::grant($user, $item, $quantity, $reason, $reference);
            $progress = DB::table('user_pass_progress')->where('user_id', $user->id)->first();
            DB::table('user_pass_reward_claims')->insert([
                'user_id' => $user->id, 'user_pass_progress_id' => $progress->id, 'ahjiyat_pass_id' => $progress->ahjiyat_pass_id,
                'ahjiyat_pass_tier_reward_id' => $reference->getKey(), 'store_item_id' => $item->id, 'track' => 'free', 'tier_number' => 2,
                'quantity' => 1, 'item_name' => 'سباق', 'source' => 'manual', 'claimed_at' => now(),
            ]);

            return $tx;
        }
    });
    app()->forgetInstance(PassClaimService::class);

    expect(e40_reason(fn () => app(PassClaimService::class)->claim($user, $b['pass'], (int) $reward->id)))->toBe('already_claimed')
        ->and(UserPassRewardClaim::query()->count())->toBe(0)
        ->and((int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity'))->toBe(0);
});

test('AX6-41b: the database enforces one claim per (user, reward)', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $claim = e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);

    expect(fn () => DB::table('user_pass_reward_claims')->insert([
        'user_id' => $user->id, 'user_pass_progress_id' => $claim->user_pass_progress_id, 'ahjiyat_pass_id' => $b['pass']->id,
        'ahjiyat_pass_tier_reward_id' => $claim->ahjiyat_pass_tier_reward_id, 'store_item_id' => $claim->store_item_id, 'track' => 'free', 'tier_number' => 2,
        'quantity' => 1, 'item_name' => 'نسخة', 'source' => 'manual', 'claimed_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('AX6-42: an inventory reward is granted through InventoryService with a traceable transaction', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $reward = $b['rewards'][2]['free'];

    $claim = e40_claims()->claim($user, $b['pass'], (int) $reward->id);
    $tx = InventoryTransaction::query()->find($claim->inventory_transaction_id);

    expect($tx)->not->toBeNull()
        ->and($tx->type)->toBe(InventoryTransaction::TYPE_GRANT)
        ->and($tx->reason)->toBe(PassClaimService::REASON)
        ->and($tx->reference_type)->toBe((new AhjiyatPassTierReward)->getMorphClass())
        ->and((int) $tx->reference_id)->toBe((int) $reward->id)
        ->and($claim->user_entitlement_id)->toBeNull();
});

test('AX6-43: an entitlement reward is granted through EntitlementService (quantity never multiplies it)', function () {
    $perk = e40_entitlement_item();
    $b = e40_build(['free_item' => $perk]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);

    $claim = e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);

    expect($claim->user_entitlement_id)->not->toBeNull()->and($claim->inventory_transaction_id)->toBeNull()
        ->and(UserEntitlement::query()->where('user_id', $user->id)->where('key', $perk->entitlement_key)->count())->toBe(1)
        ->and(app(EntitlementService::class)->hasActive($user, $perk->entitlement_key))->toBeTrue();
});

test('AX6-44: a reward id from another pass is not found and a foreign reward can never be claimed', function () {
    $b1 = e40_build(['starts' => now()->subDays(2), 'days' => 10]);
    $b2 = e40_build(['starts' => now()->addDays(9), 'days' => 20]);
    $user = e40_player();
    e40_grant($user, $b1['pass'], $b1['quests'][0], 2);

    expect(e40_reason(fn () => e40_claims()->claim($user, $b1['pass'], (int) $b2['rewards'][2]['free']->id)))->toBe('reward_not_found');
});

test('AX6-45: the claim window is enforced — after it closes nothing can be claimed', function () {
    $b = e40_build(['starts' => now()->subDays(14), 'days' => 16, 'grace' => 2]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);

    Carbon::setTestNow(now()->addDays(16)->addDays(3));
    e40_cache()->flush();

    expect(e40_reason(fn () => e40_claims()->claim($user, $b['pass']->fresh(), (int) $b['rewards'][2]['free']->id)))->toBe('claim_closed');
});

test('AX6-46: rewards earned before the end can still be claimed inside the grace window', function () {
    $b = e40_build(['starts' => now()->subDays(14), 'days' => 16, 'grace' => 3]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);

    Carbon::setTestNow(now()->addDays(3)); // بعد ends_at بيوم وداخل المهلة
    e40_cache()->flush();
    e40_life()->sync();

    $claim = e40_claims()->claim($user, $b['pass']->fresh(), (int) $b['rewards'][2]['free']->id);
    expect($claim->exists)->toBeTrue();
});

test('AX6-47: an item deactivated after publish is refused with a safe message and no claim row', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $b['rewards'][2]['free']->item->forceFill(['is_active' => false])->save();
    e40_cache()->flush();

    $e = null;
    try {
        e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);
    } catch (\App\Services\AhjiyatPass\PassException $e) {
    }

    expect($e?->reason)->toBe('item_unavailable')->and($e->getMessage())->not->toContain('SQL')
        ->and(UserPassRewardClaim::query()->count())->toBe(0);
});

test('AX6-48: a frozen player cannot claim', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $user->forceFill(['is_frozen' => true])->save();

    expect(e40_reason(fn () => e40_claims()->claim($user->fresh(), $b['pass'], (int) $b['rewards'][2]['free']->id)))->toBe('frozen');
});

test('AX6-49: the POST route claims for the signed-in user only and flashes success', function () {
    $b = e40_build();
    $user = e40_player();
    $other = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);
    $id = (int) $b['rewards'][2]['free']->id;

    $this->actingAs($other)->post(route('pass.collect', $id))->assertRedirect()->assertSessionHas('error');
    expect(UserPassRewardClaim::query()->count())->toBe(0);

    $this->actingAs($user)->post(route('pass.collect', $id))->assertRedirect()->assertSessionHas('success');
    expect(UserPassRewardClaim::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('AX6-50: unknown reward ids are 404 and nothing leaks', function () {
    e40_build();
    $this->actingAs(e16User())->post(route('pass.collect', 999999))->assertNotFound();
});

// ───────────────────────────── AX10: استلام الكل ─────────────────────────────

test('AX10-65: collect-all claims every eligible free reward', function () {
    $b = e40_build(['tiers' => 7, 'step' => 100, 'free_tiers' => [2, 4, 6], 'premium_tiers' => [1, 3, 5, 7], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 5); // 500 => شريحة 6

    $result = e40_claims()->collectAll($user, $b['pass']);

    expect($result['claimed'])->toHaveCount(3)->and($result['failed'])->toBe([])->and($result['remaining'])->toBe(0)
        ->and(UserPassRewardClaim::query()->where('user_id', $user->id)->pluck('source')->unique()->all())->toBe(['collect_all']);
});

test('AX10-66: collect-all skips locked tiers and premium rewards of a free player', function () {
    $b = e40_build(['tiers' => 7, 'step' => 100, 'free_tiers' => [2, 4, 6], 'premium_tiers' => [1, 3, 5, 7], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 3); // 300 => شريحة 4

    $result = e40_claims()->collectAll($user, $b['pass']);

    expect($result['claimed'])->toHaveCount(2) // الشريحتان 2 و4 المجانيتان فقط
        ->and(UserPassRewardClaim::query()->where('track', 'premium')->count())->toBe(0)
        ->and(UserPassRewardClaim::query()->where('tier_number', '>', 4)->count())->toBe(0);
});

test('AX10-67: collect-all skips what is already claimed', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100, 'free_tiers' => [2, 4], 'premium_tiers' => [1, 3, 5], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 5);
    e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);

    $result = e40_claims()->collectAll($user, $b['pass']);

    expect($result['claimed'])->toBe([(int) $b['rewards'][4]['free']->id])->and(UserPassRewardClaim::query()->count())->toBe(2);
});

test('AX10-68: collect-all is idempotent', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100, 'free_tiers' => [2, 4], 'premium_tiers' => [1, 3, 5], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 5);

    $first = e40_claims()->collectAll($user, $b['pass']);
    $second = e40_claims()->collectAll($user, $b['pass']);

    expect($first['claimed'])->toHaveCount(2)->and($second['claimed'])->toBe([])->and($second['failed'])->toBe([])
        ->and(UserPassRewardClaim::query()->count())->toBe(2)
        ->and((int) UserInventoryItem::query()->where('user_id', $user->id)->sum('quantity'))->toBe(2);
});

test('AX10-69: one failing reward never blocks or rolls back the others', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100, 'free_tiers' => [2, 4], 'premium_tiers' => [1, 3, 5], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 5);
    $b['rewards'][2]['free']->item->forceFill(['is_active' => false])->save();
    e40_cache()->flush();

    $result = e40_claims()->collectAll($user, $b['pass']);

    expect($result['claimed'])->toBe([(int) $b['rewards'][4]['free']->id])
        ->and($result['failed'])->toBe([['reward_id' => (int) $b['rewards'][2]['free']->id, 'reason' => 'item_unavailable']])
        ->and($result['remaining'])->toBe(1);
});

test('AX10-70: collect-all is bounded by the configured cap and reports what remains', function () {
    config(['ahjiyat_pass.rewards.collect_all_max' => 2]);
    $b = e40_build(['tiers' => 9, 'step' => 100, 'free_tiers' => [2, 3, 4, 5, 6, 7], 'premium_tiers' => [1], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 8);

    $first = e40_claims()->collectAll($user, $b['pass']);
    expect($first['claimed'])->toHaveCount(2)->and($first['remaining'])->toBe(4);

    $second = e40_claims()->collectAll($user, $b['pass']);
    $third = e40_claims()->collectAll($user, $b['pass']);
    expect($second['claimed'])->toHaveCount(2)->and($third['claimed'])->toHaveCount(2)->and($third['remaining'])->toBe(0);
});

test('AX10-71: collect-all route requires the pass id, is POST-only, and tells the player when nothing is ready', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);

    $this->actingAs($user)->post(route('pass.collect_all'))->assertSessionHasErrors('pass');
    $this->actingAs($user)->post(route('pass.collect_all'), ['pass' => $b['pass']->public_id])->assertRedirect()->assertSessionHas('error');
    $this->actingAs($user)->get('/pass/collect-all')->assertStatus(405);
    $this->actingAs($user)->post(route('pass.collect_all'), ['pass' => str_repeat('Z', 26)])->assertNotFound();
});

test('AX10-72: collect-all with real progress shows a success message with the count', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100, 'free_tiers' => [2, 4], 'premium_tiers' => [1, 3, 5], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 5);

    $this->actingAs($user)->post(route('pass.collect_all'), ['pass' => $b['pass']->public_id])->assertRedirect()->assertSessionHas('success', 'تم استلام 2 مكافأة.');
});
