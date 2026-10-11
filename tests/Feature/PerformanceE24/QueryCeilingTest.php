<?php

require_once __DIR__.'/../Chat/ChatTestHelpers.php';

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\ChatMessage;
use App\Models\Season;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E24 - سقوف الاستعلامات: عدد استعلامات الصفحة **لا يتبع** حجم البيانات المعروضة (لا N+1).
 * لا نختبر أزمنة (غير حتمية)؛ نختبر ثبات العدد بين حجم صغير وحجم أكبر بكثير، فيفشل الاختبار فور رجوع استعلام لكل عنصر.
 */
function e24_count_queries(callable $request): int
{
    Cache::flush(); // نفس الحالة الباردة للحالتين (كاش المستويات/الإعدادات…)
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/** حملة منشورة بمرحلتين وبوابة لكل مرحلة و$steps خطوة لكل بوابة، ولمستخدمٍ تقدّم جزئي. */
function e24_playable_campaign(User $user, int $steps): array
{
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    foreach ([1, 2] as $s) {
        $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => $s]);
        $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);

        foreach (range(1, $steps) as $n) {
            $step = ($n % 2 === 0 ? CampaignStep::factory()->puzzle() : CampaignStep::factory())->create(['campaign_gate_id' => $gate->id, 'sort_order' => $n]);

            if ($s === 1 && $n === 1) {
                UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now(), 'completed_at' => now()]);
            }
        }
    }

    return [$campaign, $season];
}

test('E24: the campaign page runs the same number of queries for 2 steps per gate as for 12', function () {
    $small = (function () {
        $user = User::factory()->create();
        [$campaign] = e24_playable_campaign($user, 2);

        return e24_count_queries(fn () => $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk());
    })->call($this);

    $large = (function () {
        $user = User::factory()->create();
        [$campaign] = e24_playable_campaign($user, 12);

        return e24_count_queries(fn () => $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk());
    })->call($this);

    expect($large)->toBeLessThanOrEqual($small + 2);
});

test('E24: the season page runs the same number of queries for 2 steps per gate as for 12', function () {
    $small = (function () {
        $user = User::factory()->create();
        [, $season] = e24_playable_campaign($user, 2);

        return e24_count_queries(fn () => $this->actingAs($user)->get(route('seasons.show', $season))->assertOk());
    })->call($this);

    $large = (function () {
        $user = User::factory()->create();
        [, $season] = e24_playable_campaign($user, 12);

        return e24_count_queries(fn () => $this->actingAs($user)->get(route('seasons.show', $season))->assertOk());
    })->call($this);

    expect($large)->toBeLessThanOrEqual($small + 2);
});

test('E24: the global chat page runs the same number of queries for 3 messages as for 40 messages from many senders', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $reader = e16User();
    $thread = chatThreads()->global();

    $seed = function (int $messages) use ($thread) {
        foreach (range(1, $messages) as $i) {
            (new ChatMessage)->forceFill(['chat_thread_id' => $thread->id, 'sender_id' => e16User()->id, 'body' => 'رسالة '.$i])->save();
        }
    };

    $seed(3);
    $small = e24_count_queries(fn () => $this->actingAs($reader)->get(route('community.chat'))->assertOk());

    $seed(37);
    $large = e24_count_queries(fn () => $this->actingAs($reader)->get(route('community.chat'))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + 1);
});

test('E24: the direct chat page runs the same number of queries for 3 messages as for 40', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    [$a, $b] = chatFriends();

    $small = null;

    foreach (range(1, 3) as $i) {
        chatDm($this, $i % 2 ? $a : $b, $i % 2 ? $b : $a, 'رسالة '.$i)->assertSuccessful();
    }
    $small = e24_count_queries(fn () => $this->actingAs($a)->get(route('messages.direct', $b))->assertOk());

    foreach (range(4, 40) as $i) {
        $thread = chatThreads()->directOrCreate($a, $b);
        (new ChatMessage)->forceFill(['chat_thread_id' => $thread->id, 'sender_id' => $i % 2 ? $a->id : $b->id, 'body' => 'رسالة '.$i])->save();
    }
    $large = e24_count_queries(fn () => $this->actingAs($a)->get(route('messages.direct', $b))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + 1);
});
