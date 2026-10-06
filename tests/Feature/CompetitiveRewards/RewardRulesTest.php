<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardRule;
use App\Models\OperationalAuditLog;
use App\Models\StoreItem;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Competitive\Rewards\CompetitiveRewardRuleException;
use Carbon\Carbon;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('1: a structured rank reward rule can be created before the event starts - and is audited', function () {
    $event = e18Event();
    $currency = e18Currency();

    $rule = e18Rule($event, ['min_rank' => 2, 'max_rank' => 3, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);

    expect($rule->exists)->toBeTrue()->and($rule->placementLabel())->toBe('المراكز 2–3')->and($rule->rewardLabel())->toBe("100 {$currency->name}")
        ->and($event->refresh()->rewardRules)->toHaveCount(1)
        ->and(OperationalAuditLog::where('action', 'competitive_reward_rule_created')->count())->toBe(1);
    $rule->update(['amount' => 120]);
    $rule->delete();
    expect(OperationalAuditLog::where('action', 'competitive_reward_rule_updated')->count())->toBe(1)->and(OperationalAuditLog::where('action', 'competitive_reward_rule_deleted')->count())->toBe(1);
});

test('3: min_rank greater than max_rank, zero, and over-the-limit ranks are rejected', function (int $min, int $max) {
    $event = e18Event();

    expect(fn () => e18Rule($event, ['min_rank' => $min, 'max_rank' => $max]))->toThrow(CompetitiveRewardRuleException::class);
    expect(CompetitiveRewardRule::count())->toBe(0);
})->with(['min above max' => [5, 2], 'zero rank' => [0, 1], 'beyond the cap' => [1, 101]]);

test('4: overlapping rank ranges are rejected whatever the order - no ambiguity is ever resolved by priority', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 3]);

    foreach ([[2, 5], [3, 3], [1, 1], [3, 8], [1, 10]] as [$min, $max]) {
        expect(fn () => e18Rule($event, ['min_rank' => $min, 'max_rank' => $max]))->toThrow(CompetitiveRewardRuleException::class, 'يتداخل');
    }
    e18Rule($event, ['min_rank' => 4, 'max_rank' => 10]); // متجاور بلا تداخل: مقبول
    expect(CompetitiveRewardRule::count())->toBe(2);

    // التعديل أيضًا: توسيع النطاق ليلمس آخر مرفوض.
    $second = CompetitiveRewardRule::where('min_rank', 4)->first();
    expect(fn () => $second->update(['min_rank' => 3]))->toThrow(CompetitiveRewardRuleException::class);
});

test('5: invalid reward definitions are rejected - unknown currency, non-earnable, manual items, missing entitlement key, out-of-range amounts, mixed references', function () {
    $event = e18Event();
    $premium = e18Currency(['is_earnable' => false]);
    $manual = StoreItem::factory()->manual()->create();

    $bad = [
        ['reward_type' => 'currency', 'currency_id' => 999999, 'amount' => 10],
        ['reward_type' => 'currency', 'currency_id' => $premium->id, 'amount' => 10],
        ['reward_type' => 'currency', 'currency_id' => null, 'amount' => 10],
        ['reward_type' => 'store_item', 'store_item_id' => $manual->id, 'amount' => 1],
        ['reward_type' => 'store_item', 'store_item_id' => 999999, 'amount' => 1],
        ['reward_type' => 'xp', 'amount' => 0],
        ['reward_type' => 'xp', 'amount' => 10001],
        ['reward_type' => 'xp', 'amount' => 50, 'currency_id' => e18Currency()->id],
        ['reward_type' => 'cash', 'amount' => 50],
        ['reward_type' => 'store_item', 'store_item_id' => StoreItem::factory()->create()->id, 'amount' => 11],
    ];

    foreach ($bad as $i => $attrs) {
        expect(fn () => e18Rule($event, ['min_rank' => $i + 1, 'max_rank' => $i + 1] + $attrs))->toThrow(CompetitiveRewardRuleException::class);
    }
    expect(CompetitiveRewardRule::count())->toBe(0);
});

test('A5: every supported reward type is accepted from the existing catalog - currency, xp, inventory item (cosmetic) and entitlement', function () {
    $event = e18Event();
    $cosmetic = StoreItem::factory()->cosmeticAvatar()->create();
    $entitlement = StoreItem::factory()->entitlement('vip.test')->create();

    $rules = [
        e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'currency', 'currency_id' => e18Currency()->id, 'amount' => 10]),
        e18Rule($event, ['min_rank' => 2, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 20]),
        e18Rule($event, ['min_rank' => 3, 'max_rank' => 3, 'reward_type' => 'store_item', 'store_item_id' => $cosmetic->id, 'amount' => 1]),
        e18Rule($event, ['min_rank' => 4, 'max_rank' => 4, 'reward_type' => 'store_item', 'store_item_id' => $entitlement->id, 'amount' => 1]),
    ];

    expect(collect($rules)->map->rewardLabel()->all())->toContain('20 نقطة خبرة', $cosmetic->name, $entitlement->name);
    expect(fn () => e18Rule($event, ['min_rank' => 5, 'max_rank' => 5, 'reward_type' => 'store_item', 'store_item_id' => $entitlement->id, 'amount' => 2]))
        ->toThrow(CompetitiveRewardRuleException::class, 'بين 1 و1'); // الامتياز بكمية 1 فقط
});

test('A13: the participation rule is explicit, single, and carries no ranks', function () {
    $event = e18Event();
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'amount' => 5]);

    expect(fn () => e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'amount' => 6]))->toThrow(CompetitiveRewardRuleException::class, 'قاعدة مشاركة')
        ->and(fn () => e18Rule(e18Event(), ['kind' => 'participation', 'min_rank' => 1, 'max_rank' => 2]))->toThrow(CompetitiveRewardRuleException::class)
        ->and(CompetitiveRewardRule::count())->toBe(1);
});

test('6/A14/A15: rules lock when the event starts - no jackpot can be added after the winner is known, and finalized events stay immutable', function () {
    $event = e18Event();
    $rule = e18Rule($event);
    expect($event->refresh()->rewardsEditable())->toBeTrue();

    e17Forward(2 * 3600_000); // بدأت
    expect($event->refresh()->rewardsEditable())->toBeFalse()
        ->and(fn () => e18Rule($event, ['min_rank' => 2, 'max_rank' => 2]))->toThrow(CompetitiveRewardRuleException::class, 'مقفلة')
        ->and(fn () => $rule->update(['amount' => 999]))->toThrow(CompetitiveRewardRuleException::class)
        ->and(fn () => $rule->delete())->toThrow(CompetitiveRewardRuleException::class);

    e17PlayEvent(e16User(), $event);
    e17Forward(5 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($event->refresh());
    expect($event->refresh()->status)->toBe('completed')->and(fn () => e18Rule($event, ['min_rank' => 9, 'max_rank' => 9]))->toThrow(CompetitiveRewardRuleException::class)
        ->and($rule->refresh()->amount)->toBe(50);
});

test('draft events and cancelled events: a draft is editable, a cancelled event is locked', function () {
    $draft = e17Event(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_DRAFT);
    $cancelled = e17Event(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_CANCELLED);

    expect(e18Rule($draft)->exists)->toBeTrue()->and(fn () => e18Rule($cancelled))->toThrow(CompetitiveRewardRuleException::class);
});

test('the rule schema has no multiplier, cash, wager or free-text reward column - structured references only', function () {
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('competitive_reward_rules');

    expect($columns)->toContain('currency_id', 'store_item_id', 'reward_type', 'amount');
    foreach ($columns as $column) {
        expect($column)->not->toMatch('/(multiplier|bonus|cash|payout|stake|bet|wager|expression|formula|script|callback|json|payload)/i');
    }
    expect(CompetitiveRewardRule::query()->getModel()->getFillable())->not->toContain('participation_event_id');
});
