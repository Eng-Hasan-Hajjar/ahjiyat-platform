<?php

require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\PlayerProgression;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Wallet;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Store\InventoryService;

/** مساعدات E18: حدث قادم قابل لتجهيز جوائزه، ثم لعب لاعبين بمدد معلومة، ثم اعتماد نتائجه (فيطلق التوزيع كما بالإنتاج). */
if (! function_exists('e18Event')) {
    /** منشور ولم يبدأ بعد: قواعده قابلة للتحرير. */
    function e18Event(array $attrs = []): CompetitiveEvent
    {
        return e17Event($attrs + ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(5)]);
    }

    function e18Currency(array $attrs = []): Currency
    {
        return Currency::factory()->create($attrs);
    }

    function e18Rule(CompetitiveEvent $event, array $attrs = []): CompetitiveRewardRule
    {
        return CompetitiveRewardRule::create($attrs + ['competitive_event_id' => $event->id, 'kind' => 'rank', 'min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'xp', 'amount' => 50]);
    }

    /**
     * يلعب اللاعبون بالترتيب المعطى (كل بدء ثم $ms ثم إرسال)، ثم ينتهي الحدث ويُعتمد. ينطلق التوزيع من حدث الاعتماد نفسه.
     *
     * @param  list<array{0: User, 1: string, 2: int}>  $players  [مستخدم، إجابة، مدة بالمللي ثانية]
     */
    function e18Run(CompetitiveEvent $event, array $players, bool $finalize = true): CompetitiveEvent
    {
        e17Forward(2 * 3600_000); // بدأ

        foreach ($players as [$user, $answer, $ms]) {
            e17PlayEvent($user, $event, $answer, $ms);
        }

        e17Forward(5 * 3600_000); // انتهى

        if ($finalize) {
            app(CompetitiveEventFinalizer::class)->finalize($event->refresh());
        }

        return $event->refresh();
    }

    function e18Grant(CompetitiveEvent $event, User $user): ?CompetitiveRewardGrant
    {
        return CompetitiveRewardGrant::where('competitive_event_id', $event->id)->where('user_id', $user->id)->first();
    }

    function e18Xp(User $user): int
    {
        return (int) PlayerProgression::where('user_id', $user->id)->value('total_xp');
    }

    function e18Pending(User $user, Currency $currency): int
    {
        return (int) Wallet::where('user_id', $user->id)->where('currency_id', $currency->id)->value('pending_balance');
    }

    function e18Qty(User $user, StoreItem $item): int
    {
        return app(InventoryService::class)->quantityFor($user, $item);
    }

    function e18Entitlements(User $user): int
    {
        return UserEntitlement::where('user_id', $user->id)->count();
    }
}

if (! function_exists('e18Finalized')) {
    /** يضيف نتيجة معتمدة بمركز معلوم لحدث قائم. */
    function e18AddResult(\App\Models\CompetitiveEvent $event, User $user, int $rank, bool $correct = true): \App\Models\CompetitiveEventResult
    {
        $session = \App\Models\GameSession::create([
            'user_id' => $user->id, 'puzzle_id' => $event->puzzle_id, 'context_type' => 'competitive_event', 'context_id' => $event->id,
            'status' => 'completed', 'started_at' => now(), 'completed_at' => now(), 'server_state' => ['competitive' => true],
        ]);
        \App\Models\CompetitiveEventParticipant::firstOrCreate(['competitive_event_id' => $event->id, 'user_id' => $user->id], ['status' => 'completed', 'registered_at' => now()->subDays(11)]);

        return \App\Models\CompetitiveEventResult::create([
            'competitive_event_id' => $event->id, 'user_id' => $user->id, 'game_session_id' => $session->id, 'is_correct' => $correct,
            'score' => $correct ? 1500 : 0, 'duration_ms' => 20_000 + $rank, 'completed_at' => now()->subDays(6), 'final_rank' => $rank,
        ]);
    }

    /** حدث معتمَد بنتيجة واحدة (للإحصاءات والتاريخ بلا لعب كامل). */
    function e18Finalized(User $user, int $rank, bool $correct = true, array $eventAttrs = []): \App\Models\CompetitiveEvent
    {
        $event = e17Event($eventAttrs + ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)->subMinutes(random_int(1, 3000))]);
        $event->forceFill(['status' => 'completed', 'finalized_at' => now()->subDays(4)])->save();
        e18AddResult($event, $user, $rank, $correct);

        return $event->refresh();
    }
}
