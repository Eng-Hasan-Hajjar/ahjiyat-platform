<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Jobs\DistributeCompetitiveRewardsChunk;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\CurrencyTransaction;
use App\Models\InventoryTransaction;
use App\Models\OperationalAuditLog;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Competitive\CompetitiveException;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e18Dist(): CompetitiveRewardDistributionService
{
    return app(CompetitiveRewardDistributionService::class);
}

/** حدث بخمسة لاعبين صحيحين مرتَّبين 1..5، وقواعد: مركز 1 = XP، مراكز 2–3 = عملة، والرابع والخامس بلا قاعدة. @return array{0: CompetitiveEvent, 1: array<int, User>, 2: \App\Models\Currency} */
function e18Podium(array $extraRules = []): array
{
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'xp', 'amount' => 50]);
    e18Rule($event, ['min_rank' => 2, 'max_rank' => 3, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    foreach ($extraRules as $attrs) {
        e18Rule($event, $attrs);
    }
    $users = collect(range(1, 5))->map(fn ($i) => e16User(['name' => "P{$i}"]))->all();
    e18Run($event, array_map(fn ($u, $i) => [$u, E17_ANSWER, $i * 10_000], $users, range(1, 5)));

    return [$event->refresh(), $users, $currency];
}

test('10/11/12/13: a finalized event distributes the right reward by final rank - and players outside every range get nothing', function () {
    [$event, [$p1, $p2, $p3, $p4, $p5], $currency] = e18Podium();

    expect(e18Xp($p1))->toBe(50)->and(e18Grant($event, $p1)->status)->toBe('granted')->and(e18Grant($event, $p1)->final_rank)->toBe(1)
        ->and(e18Pending($p2, $currency))->toBe(100)->and(e18Pending($p3, $currency))->toBe(100)
        ->and(e18Grant($event, $p2)->reward_label)->toBe("100 {$currency->name}")
        ->and(e18Grant($event, $p4))->toBeNull()->and(e18Grant($event, $p5))->toBeNull()->and(e18Xp($p4))->toBe(0)->and(e18Pending($p4, $currency))->toBe(0)
        ->and(CompetitiveRewardGrant::count())->toBe(3);
});

test('14/15/16: distributing again, replaying the queued job and two racing workers never duplicate a reward', function () {
    [$event, [$p1, $p2], $currency] = e18Podium();
    $walletRows = CurrencyTransaction::count();
    $xpRows = XpTransaction::count();

    e18Dist()->distributeChunk($event);              // مرة ثانية
    e18Dist()->distributeChunk($event);              // ثالثة
    foreach (range(1, 5) as $i) {
        DistributeCompetitiveRewardsChunk::dispatchSync($event->id); // الوظيفة عشر مرات تقريبًا
    }

    expect(CompetitiveRewardGrant::count())->toBe(3)->and(CurrencyTransaction::count())->toBe($walletRows)->and(XpTransaction::count())->toBe($xpRows)
        ->and(e18Xp($p1))->toBe(50)->and(e18Pending($p2, $currency))->toBe(100);

    // عاملان: كلاهما قرأ الصف pending قبل أن ينجح الآخر (نسخ قديمة بالذاكرة).
    $event2 = e18Event();
    e18Rule($event2, ['reward_type' => 'xp', 'amount' => 70]);
    $solo = e16User();
    e18Run($event2, [[$solo, E17_ANSWER, 5_000]], finalize: false);
    app(CompetitiveEventFinalizer::class)->finalize($event2->refresh());
    $grant = e18Grant($event2, $solo);
    $stale = CompetitiveRewardGrant::find($grant->id);
    $grant->update(['status' => 'pending', 'granted_at' => null]); // كأن العاملين قرآه معلّقًا
    DB::table('xp_transactions')->where('idempotency_key', "competitive-reward:{$event2->id}:{$solo->id}")->delete();
    DB::table('player_progressions')->where('user_id', $solo->id)->update(['total_xp' => 0]);

    $outcomes = [e18Dist()->process($grant->refresh()), e18Dist()->process($stale)];

    expect($outcomes)->toBe(['granted', 'skipped'])->and(e18Xp($solo))->toBe(70);
});

test('17/18/19: a failed reward never rolls back the event or the other players, is retryable by an authorized admin - and a granted one is never granted again', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    e18Rule($event, ['min_rank' => 3, 'max_rank' => 3, 'reward_type' => 'xp', 'amount' => 30]);
    [$a, $b, $c] = [e16User(), e16User(), e16User()];
    $currency->update(['is_earnable' => false]); // يفشل منح العملة لاحقًا (تعريف لم يعد صالحًا)

    $finalized = e18Run($event, [[$a, E17_ANSWER, 10_000], [$b, E17_ANSWER, 20_000], [$c, E17_ANSWER, 30_000]]);

    expect($finalized->status)->toBe('completed')->and(\App\Models\CompetitiveEventResult::count())->toBe(3)                       // الحدث ونتائجه سليمة
        ->and(e18Grant($event, $a)->status)->toBe('failed')->and(e18Grant($event, $b)->status)->toBe('failed')
        ->and(e18Grant($event, $a)->failure_reason)->not->toBeEmpty()->and(e18Pending($a, $currency))->toBe(0)                     // لا نصف منحة
        ->and(e18Grant($event, $c)->status)->toBe('granted')->and(e18Xp($c))->toBe(30);                                            // غيره سليم

    $admin = e16User();
    $admin->givePermissionTo('competitive_events.rewards.retry');
    $currency->update(['is_earnable' => true]);

    $first = e18Dist()->retryFailed($event, $admin);
    $second = e18Dist()->retryFailed($event, $admin);

    expect($first)->toBe(['requested' => 2, 'granted' => 2, 'failed' => 0])->and($second)->toBe(['requested' => 0, 'granted' => 0, 'failed' => 0])
        ->and(e18Pending($a, $currency))->toBe(100)->and(e18Pending($b, $currency))->toBe(100)->and(e18Xp($c))->toBe(30)         // الممنوح لم يُعَد
        ->and(e18Grant($event, $a)->attempts)->toBe(2)->and(e18Grant($event, $c)->attempts)->toBe(1)    // الممنوح لم يُلمس أصلًا (لا إعادة معالجة)
        ->and(CurrencyTransaction::where('reason', 'competitive_event_reward')->count())->toBe(2)
        ->and(OperationalAuditLog::where('action', 'competitive_rewards_retry')->count())->toBe(2); // تدقيق واحد لكل عملية، لا لكل معاملة محفظة
});

test('20/21/22: currency, XP, inventory item and entitlement all go through the official services with a source tag and the event reference', function () {
    $event = e18Event();
    $currency = e18Currency();
    $cosmetic = StoreItem::factory()->cosmeticAvatar()->create();
    $entitlementItem = StoreItem::factory()->entitlement('vip.competitive')->create();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 40]);
    e18Rule($event, ['min_rank' => 2, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 25]);
    e18Rule($event, ['min_rank' => 3, 'max_rank' => 3, 'reward_type' => 'store_item', 'store_item_id' => $cosmetic->id, 'amount' => 2]);
    e18Rule($event, ['min_rank' => 4, 'max_rank' => 4, 'reward_type' => 'store_item', 'store_item_id' => $entitlementItem->id, 'amount' => 1]);
    $u = collect(range(1, 4))->map(fn () => e16User())->all();

    e18Run($event, array_map(fn ($user, $i) => [$user, E17_ANSWER, $i * 10_000], $u, range(1, 4)));

    $money = CurrencyTransaction::where('user_id', $u[0]->id)->where('reason', 'competitive_event_reward')->sole();
    $xp = XpTransaction::where('user_id', $u[1]->id)->where('type', 'competitive_reward')->sole();
    $inv = InventoryTransaction::where('user_id', $u[2]->id)->where('reason', 'competitive_event_reward')->sole();

    expect($money->type)->toBe('earn_pending')->and($money->reference_id)->toBe($event->id)->and($money->reference_type)->toBe((new CompetitiveEvent)->getMorphClass())
        ->and($money->idempotency_key)->toBe("competitive-reward:{$event->id}:{$u[0]->id}")->and(e18Pending($u[0], $currency))->toBe(40)  // معلّقة بالحجز المعتاد
        ->and($xp->amount)->toBe(25)->and($xp->source_id)->toBe($event->id)->and($xp->idempotency_key)->toBe("competitive-reward:{$event->id}:{$u[1]->id}")->and(e18Xp($u[1]))->toBe(25)
        ->and($inv->quantity)->toBe(2)->and($inv->reference_id)->toBe($event->id)->and(e18Qty($u[2], $cosmetic))->toBe(2)
        ->and(e18Entitlements($u[3]))->toBe(1)->and(app(\App\Services\Store\EntitlementService::class)->hasActive($u[3], 'vip.competitive'))->toBeTrue()
        ->and(CompetitiveRewardGrant::where('status', 'granted')->count())->toBe(4);
});

test('item grants are atomic with the ledger - an inventory write that is followed by a failure rolls back completely, and the retry grants it once', function () {
    $event = e18Event();
    $item = StoreItem::factory()->cosmeticAvatar()->create();
    e18Rule($event, ['reward_type' => 'store_item', 'store_item_id' => $item->id, 'amount' => 1]);
    $user = e16User();

    // مزوّد مخزون يكتب العنصر فعلًا ثم يفشل (كانقطاع بعد الكتابة): يجب أن يتراجع كل شيء.
    $this->app->bind(\App\Services\Store\InventoryService::class, fn ($app) => new class($app->make(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)) extends \App\Services\Store\InventoryService
    {
        public function grant(User $user, StoreItem $item, int $quantity, string $reason, ?\Illuminate\Database\Eloquent\Model $reference = null): InventoryTransaction
        {
            parent::grant($user, $item, $quantity, $reason, $reference);

            throw new RuntimeException('connection lost after the write');
        }
    });
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);
    $grant = e18Grant($event, $user);

    expect($grant->status)->toBe('failed')->and($grant->granted_at)->toBeNull()->and($grant->failure_reason)->toContain('connection lost')
        ->and(e18Qty($user, $item))->toBe(0)->and(InventoryTransaction::count())->toBe(0); // لا نصف منحة

    $this->app->forgetInstance(\App\Services\Store\InventoryService::class);
    $this->app->bind(\App\Services\Store\InventoryService::class, fn ($app) => new \App\Services\Store\InventoryService($app->make(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)));
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $admin = e16User();
    $admin->givePermissionTo('competitive_events.rewards.retry');
    e18Dist()->retryFailed($event, $admin);

    expect($grant->refresh()->status)->toBe('granted')->and(e18Qty($user, $item))->toBe(1)->and(InventoryTransaction::count())->toBe(1);
});

test('8/B18: participation is explicit and only for a valid correct result - never for registering, never for a wrong answer; ranks beat participation', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'xp', 'amount' => 100]);
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'reward_type' => 'xp', 'amount' => 5]);
    [$winner, $finisher, $wrong, $registeredOnly, $noShow] = collect(range(1, 5))->map(fn () => e16User())->all();
    e17Events()->register($registeredOnly, $event);   // سجّل فقط
    e17Events()->register($noShow, $event);

    e18Run($event, [[$winner, E17_ANSWER, 10_000], [$finisher, E17_ANSWER, 20_000], [$wrong, 'خطأ', 5_000]]);

    expect(e18Xp($winner))->toBe(100)->and(e18Grant($event, $winner)->reward_label)->toBe('100 نقطة خبرة')   // مركز لا مشاركة
        ->and(e18Xp($finisher))->toBe(5)->and(e18Grant($event, $wrong))->toBeNull()                          // خطأ: لا مشاركة ولا مركز
        ->and(e18Grant($event, $registeredOnly))->toBeNull()->and(e18Grant($event, $noShow))->toBeNull()
        ->and(e18Xp($wrong))->toBe(0)->and(CompetitiveRewardGrant::count())->toBe(2);
});

test('7/B16/B17: a cancelled event, an event without results and an event without rules distribute nothing', function () {
    $cancelled = e18Event();
    e18Rule($cancelled);
    $user = e16User();
    e17Forward(2 * 3600_000);
    e17PlayEvent($user, $cancelled);
    $cancelled->forceFill(['status' => 'cancelled'])->save();
    e17Forward(5 * 3600_000);

    $empty = e17Event(['starts_at' => now()->subDay(), 'ends_at' => now()->subHour()], 'published');
    $noRules = e18Event();
    e17Forward(7 * 3600_000);
    $emptyEvent = e18Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    e18Rule($emptyEvent);
    e17Forward(5 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($emptyEvent->refresh());

    expect(e18Dist()->distributeChunk($cancelled->refresh()))->toMatchArray(['count' => 0, 'granted' => 0])->and(e18Dist()->isDistributable($cancelled))->toBeFalse()
        ->and(e18Dist()->distributeChunk($emptyEvent->refresh()))->toMatchArray(['count' => 0])->and(CompetitiveRewardGrant::count())->toBe(0)
        ->and(e18Xp($user))->toBe(0);
});

test('25: an event finalized before any rules existed gets no retroactive reward - rules cannot be added afterwards and nothing is queued', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $old = e17Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);   // أُنشئ بلا قواعد (كأحداث E17)
    $user = e16User();
    e17Forward(2 * 3600_000);
    e17PlayEvent($user, $old);
    e17Forward(5 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($old->refresh());

    expect($old->refresh()->status)->toBe('completed')
        ->and(fn () => e18Rule($old))->toThrow(\App\Services\Competitive\Rewards\CompetitiveRewardRuleException::class, 'مقفلة')
        ->and(e18Dist()->distributeChunk($old))->toMatchArray(['count' => 0])->and(CompetitiveRewardGrant::count())->toBe(0);
    \Illuminate\Support\Facades\Queue::assertNotPushed(DistributeCompetitiveRewardsChunk::class); // لا وظيفة لحدث بلا قواعد
});

test('B7/B8/B9: distribution is queued only after finalization commits, as a chunked job - and a downstream failure never fails the finalization', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $event = e18Event();
    e18Rule($event);
    $user = e16User();
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);

    \Illuminate\Support\Facades\Queue::assertPushed(DistributeCompetitiveRewardsChunk::class, fn ($job) => $job->eventId === $event->id && $job->afterResultId === 0);
    expect(CompetitiveRewardGrant::count())->toBe(0); // لم يُوزَّع داخل الطلب نفسه: الوظيفة هي التي توزّع

    // وفشل ما بعد الاعتماد لا يُسقطه.
    \Illuminate\Support\Facades\Queue::swap(new \Illuminate\Queue\QueueManager(app()));
    $this->app->bind(CompetitiveRewardDistributionService::class, fn () => throw new RuntimeException('distribution is down'));
    $second = e18Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(5)]);
    e18Rule($second);
    $other = e16User();
    $result = e18Run($second, [[$other, E17_ANSWER, 10_000]]);

    expect($result->status)->toBe('completed')->and(\App\Models\CompetitiveEventResult::where('user_id', $other->id)->value('final_rank'))->toBe(1);
});

test('B10: distribution is chunked - many players, a tiny chunk size, a chained job, every player rewarded exactly once, audited start and completion once', function () {
    config(['competitive.rewards.chunk_size' => 2]);
    $event = e18Event();
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'reward_type' => 'xp', 'amount' => 10]);
    $users = collect(range(1, 7))->map(fn () => e16User())->all();

    e18Run($event, array_map(fn ($u, $i) => [$u, E17_ANSWER, $i * 1_000], $users, range(1, 7)));

    expect(CompetitiveRewardGrant::where('status', 'granted')->count())->toBe(7)->and(array_sum(array_map('e18Xp', $users)))->toBe(70)
        ->and(OperationalAuditLog::where('action', 'competitive_rewards_distribution_started')->count())->toBe(1)
        ->and(OperationalAuditLog::where('action', 'competitive_rewards_distribution_completed')->count())->toBe(1)
        ->and(OperationalAuditLog::where('action', 'competitive_rewards_distribution_completed')->first()->metadata)->toMatchArray(['eligible' => 7, 'granted' => 7, 'failed' => 0, 'pending' => 0]);
});

test('B1/rank immutability: the distribution reads final ranks and never changes score, rank, winner or qualification', function () {
    [$event, $users] = e18Podium();
    $before = \App\Models\CompetitiveEventResult::orderBy('id')->get(['id', 'score', 'final_rank', 'duration_ms', 'is_correct'])->toArray();

    e18Dist()->distributeChunk($event);
    app(CompetitiveEventFinalizer::class)->finalize($event->refresh()); // إعادة الاعتماد: لا شيء

    expect(\App\Models\CompetitiveEventResult::orderBy('id')->get(['id', 'score', 'final_rank', 'duration_ms', 'is_correct'])->toArray())->toBe($before)
        ->and(\App\Models\CompetitiveEventResult::orderBy('final_rank')->pluck('user_id')->all())->toBe(array_map(fn ($u) => $u->id, $users));
});

test('B3: the database itself forbids a second grant for the same player and event, and keeps history when catalog rows are deactivated or protected from deletion', function () {
    [$event, [$p1, $p2], $currency] = e18Podium();
    $grant = e18Grant($event, $p2);

    expect(fn () => DB::table('competitive_reward_grants')->insert($grant->only(['competitive_event_id', 'user_id', 'competitive_event_result_id', 'competitive_reward_rule_id', 'final_rank', 'reward_type', 'amount', 'reward_label']) + ['status' => 'pending', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

    // 58: تعطيل العملة لا يمحو التاريخ، وحذفها ممنوع ما دامت قاعدة تستعملها.
    $currency->update(['is_active' => false]);
    expect(fn () => $currency->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect($grant->refresh()->reward_label)->toBe("100 {$currency->name}")->and($grant->status)->toBe('granted')
        ->and(fn () => CompetitiveRewardRule::where('reward_type', 'currency')->first()->delete())->toThrow(\App\Services\Competitive\Rewards\CompetitiveRewardRuleException::class);
});

test('B15: a frozen player keeps the historical result and the reward follows the existing wallet service', function () {
    $event = e18Event();
    e18Rule($event, ['reward_type' => 'xp', 'amount' => 20]);
    $user = e16User();
    e17Forward(2 * 3600_000);
    e17PlayEvent($user, $event);
    $user->forceFill(['is_frozen' => true])->save();
    e17Forward(5 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($event->refresh());

    expect(\App\Models\CompetitiveEventResult::where('user_id', $user->id)->value('final_rank'))->toBe(1)->and(e18Grant($event, $user))->not->toBeNull()
        ->and(e18Grant($event, $user)->status)->toBeIn(['granted', 'failed']);
});

test('24: friend challenges grant NO economic reward - no grants, no wallet, XP or item change - only competitive stats', function () {
    [$a, $b] = e17Friends();
    $before = e17Snapshot();

    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 20_000);

    expect($challenge->refresh()->status)->toBe('completed')->and(CompetitiveRewardGrant::count())->toBe(0)->and(e17Snapshot())->toBe($before)
        ->and(CurrencyTransaction::where('reason', 'competitive_event_reward')->count())->toBe(0);
});

test('retry is authorized and protected: unauthorized users get 403, and repeated retries are rate limited', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    [$event] = e18Podium();
    $nobody = e16User();
    $retrier = e16User();
    $retrier->givePermissionTo('competitive_events.rewards.retry');

    expect(fn () => e18Dist()->retryFailed($event, $nobody))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    foreach (range(1, 5) as $i) {
        e18Dist()->retryFailed($event, $retrier);
    }

    expect(fn () => e18Dist()->retryFailed($event, $retrier))->toThrow(CompetitiveException::class, 'محاولات إعادة كثيرة');
});

test('defense in depth: ruleFor() itself refuses a wrong result and a result without a final rank, whatever the caller filtered', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 5, 'reward_type' => 'xp', 'amount' => 10]);
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'reward_type' => 'xp', 'amount' => 1]);
    $rules = e18Dist()->activeRules($event);
    $result = fn (array $a) => new \App\Models\CompetitiveEventResult($a + ['is_correct' => true, 'final_rank' => 2]);

    expect(e18Dist()->ruleFor($rules, $result([])))->not->toBeNull()->and(e18Dist()->ruleFor($rules, $result([]))->kind)->toBe('rank')
        ->and(e18Dist()->ruleFor($rules, $result(['final_rank' => 9]))->kind)->toBe('participation')   // خارج النطاق: مشاركة
        ->and(e18Dist()->ruleFor($rules, $result(['is_correct' => false])))->toBeNull()                  // خاطئة: لا مركز ولا مشاركة
        ->and(e18Dist()->ruleFor($rules, $result(['final_rank' => null])))->toBeNull();                  // بلا مركز نهائي: لا شيء
});
