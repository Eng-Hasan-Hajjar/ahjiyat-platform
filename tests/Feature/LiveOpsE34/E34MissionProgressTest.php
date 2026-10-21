<?php

use App\Models\PlayerProgression;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\MissionAssignmentService;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

test('Z3-01: a solve advances global missions and only the missions assigned to that player, never the other pool members', function () {
    $u = e34_user();
    $global = e34_quest();
    $members = e34_quests(5, ['target_value' => 3]);
    e34_pool($members, ['selection_count' => 2]);

    e34_solve($u);

    $progress = UserQuestProgress::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    $assigned = UserMissionAssignment::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

    expect($assigned)->toHaveCount(2)
        ->and($progress)->toEqualCanonicalizing([$global->id, ...$assigned])
        ->and(UserQuestProgress::query()->where('user_id', $u->id)->whereIn('quest_definition_id', array_diff(array_map(fn ($q) => $q->id, $members), $assigned))->count())->toBe(0);
});

test('Z3-02: the reward is granted once through the existing authority (XP) and never doubled by repeated solves or re-evaluation', function () {
    $u = e34_user();
    $quest = e34_quest(['xp_reward' => 10]);
    e34_pool([$quest, e34_quest()], ['selection_count' => 2]);

    $questXp = fn () => (int) XpTransaction::query()->where('user_id', $u->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->sum('amount');

    e34_solve($u);
    expect($questXp())->toBe(10);

    e34_solve($u);
    app(QuestService::class)->syncCurrentQuests($u);
    app(QuestService::class)->syncCurrentQuests($u);

    expect($questXp())->toBe(10)
        ->and(XpTransaction::query()->where('user_id', $u->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1);
});

test('Z3-03: playing a community puzzle never touches missions, assignments, streaks or any economy table (zero-economy isolation)', function () {
    $player = e34_user();
    $quest = e34_quest(['target_value' => 1, 'xp_reward' => 10]);
    e34_pool(e34_quests(3), ['selection_count' => 2]);
    $work = e28_published(e28_creator());

    $before = e28_world($player);
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'القلم');

    expect(e28_world($player))->toBe($before)
        ->and(UserQuestProgress::query()->where('user_id', $player->id)->count())->toBe(0)
        ->and(UserMissionAssignment::query()->where('user_id', $player->id)->count())->toBe(0);

    $this->actingAs($player)->get('/missions')->assertOk();
    expect(UserQuestProgress::query()->where('user_id', $player->id)->where('quest_definition_id', $quest->id)->value('current_value'))->toBe(0);
});

test('Z3-04: the client cannot drive progress or rewards - missions/quests/events routes are read-only and writes are rejected', function () {
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (preg_match('#^(missions|quests|events)(/|$)#', $uri)) {
            expect(array_values(array_diff($route->methods(), ['GET', 'HEAD'])))->toBe([], "route {$uri} must be GET-only");
        }
    }

    $u = e34_user();
    $quest = e34_quest();
    $before = UserQuestProgress::query()->count();

    $this->actingAs($u)->post('/missions', ['current_value' => 99, 'quest' => $quest->id])->assertStatus(405);
    $this->actingAs($u)->post('/quests', ['amount' => 500])->assertStatus(405);
    $this->actingAs($u)->put('/events', ['x' => 1])->assertStatus(405);

    expect(UserQuestProgress::query()->count())->toBe($before);
});

test('Z3-05: continuity - a mission the player already progressed stays visible to them after it joins a pool, and stays hidden from others', function () {
    $u = e34_user();
    $other = e34_user();
    $quest = e34_quest(['target_value' => 5]);
    app(QuestService::class)->progressFor($u, $quest, app(QuestPeriodService::class)->dailyContext());

    $filler = e34_quests(3);
    e34_pool([$quest, ...$filler], ['selection_count' => 1]);

    expect(e34_visible_ids($u))->toContain($quest->id)->and(e34_visible_via_isVisible($u))->toContain($quest->id);
    expect(e34_visible_ids($other))->toHaveCount(1);
});

test('Z3-06: an assignment failure fails closed for pooled missions only and never breaks gameplay or global missions', function () {
    $u = e34_user();
    $global = e34_quest(['target_value' => 2]);
    e34_pool(e34_quests(3), ['selection_count' => 2]);

    $this->partialMock(MissionAssignmentService::class, fn ($m) => $m->shouldReceive('assignedQuestIds')->andThrow(new RuntimeException('boom')));

    e34_solve($u); // لا يرمي

    expect(UserQuestProgress::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all())->toBe([$global->id])
        ->and(e34_visible_ids($u))->toBe([$global->id]);
});

test('Z3-07: batched visibility (visibleQuestsFor) matches per-quest visibility (isVisibleTo) across every mixed case', function () {
    $u = e34_user();
    e34_quest();                                              // عامة نشطة
    $inactiveWith = e34_quest(['is_active' => false]);        // معطّلة لكن لها تقدّم
    e34_quest(['is_active' => false]);                        // معطّلة بلا تقدّم
    e34_quest(['starts_at' => now()->addDay()]);              // خارج النافذة
    e34_quest(['ends_at' => now()->subHour()]);               // منتهية
    $members = e34_quests(4);
    e34_pool($members, ['selection_count' => 2]);
    $other = e34_pool(e34_quests(2), ['selection_count' => 1, 'priority' => 500]);
    $weekly = e34_weekly();
    e34_pool([$weekly, e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 1]);

    app(QuestService::class)->progressFor($u, $inactiveWith, app(QuestPeriodService::class)->dailyContext());

    foreach (['daily', 'weekly'] as $type) {
        expect(e34_visible_ids($u, $type))->toBe(collect(e34_visible_via_isVisible($u, $type))->sort()->values()->all());
    }

    expect($other->fresh()->is_active)->toBeTrue();
});

test('Z3-08: missions of other period types or other pools are never given progress rows for a player who is not assigned them', function () {
    $u = e34_user();
    e34_pool([e34_weekly(), e34_weekly(), e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 1]);

    e34_solve($u);

    expect(UserQuestProgress::query()->where('user_id', $u->id)->where('period_type', 'weekly')->count())->toBe(1)
        ->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(1);
});

test('Z3-09: no second engine - the evaluator set is unchanged and LiveOps services never write progress, rewards or wallets', function () {
    expect(QuestEvaluatorRegistry::ALL_TYPES)->toBe([
        QuestEvaluatorRegistry::PUZZLES_SOLVED, QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY,
        QuestEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED, QuestEvaluatorRegistry::QUALIFICATIONS_EARNED,
    ]);

    $forbidden = ['GemWalletService', 'ProgressionRewardService', 'grantXp', 'grantQuestRewards', 'UserQuestProgress::query()->create', 'UserQuestProgress::create', 'UserQuestProgress::query()->update', 'forceCreate'];

    foreach (glob(base_path('app/Services/LiveOps').'/{*,*/*}.php', GLOB_BRACE) as $file) {
        $src = e34_code_only($file);
        foreach ($forbidden as $needle) {
            // الاستثناء الوحيد المقصود: لقطة الإسناد نفسها (UserMissionAssignment) عبر insertOrIgnore.
            expect(str_contains($src, $needle))->toBeFalse("{$file} must not contain {$needle}");
        }
    }
});
