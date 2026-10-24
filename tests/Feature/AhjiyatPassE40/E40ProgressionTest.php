<?php

use App\Models\AhjiyatPassMission;
use App\Models\PassPointGrant;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\AhjiyatPass\PassPointService;
use App\Services\Engagement\QuestService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/_support.php';

/*
| E40 / AX2 تقدّم مجاني، AX3 إعادة استخدام المهام (QuestDefinition/UserQuestProgress/QuestService)، AX4 مستويات الصعوبة.
*/

beforeEach(fn () => e40_seed());

// ───────────────────────────── AX2: التقدّم المجاني ─────────────────────────────

test('AX2-09: a free player progresses through tiers by completing missions', function () {
    $b = e40_build();
    $user = e40_player();

    e40_grant($user, $b['pass'], $b['quests'][0], 3);

    expect(e40_points_of($user, $b['pass']))->toBe(150)
        ->and(e40_tier_of($user, $b['pass']))->toBe(2) // 0,100,200,300,400
        ->and(e40_row($user, $b['pass'])->premium_unlocked_at)->toBeNull();
});

test('AX2-10: no purchase is needed to progress or to collect a free reward', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 2);

    $claim = e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);

    expect($claim->exists)->toBeTrue()->and(DB::table('digital_purchases')->count())->toBe(0);
});

test('AX2-11: progress rows are created lazily — none at publish, one per player on first interaction', function () {
    $b = e40_build();
    e16User();
    e16User();

    expect(DB::table('user_pass_progress')->count())->toBe(0);

    $user = e16User();
    $this->actingAs($user)->get(route('pass.index'))->assertOk();
    $this->actingAs($user)->get(route('pass.index'))->assertOk();

    expect(DB::table('user_pass_progress')->count())->toBe(1)
        ->and(e40_row($user, $b['pass'])->highest_tier)->toBe(1);
});

test('AX2-11b: the unique (user, pass) constraint makes concurrent joins converge on one row', function () {
    $b = e40_build();
    $user = e16User();

    $a = e40_progress()->ensure($user, $b['pass']);
    $c = e40_progress()->ensure($user, $b['pass']);

    expect($a->id)->toBe($c->id)->and(DB::table('user_pass_progress')->count())->toBe(1);
    expect(fn () => DB::table('user_pass_progress')->insert(['user_id' => $user->id, 'ahjiyat_pass_id' => $b['pass']->id, 'pass_points' => 0, 'highest_tier' => 1, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

test('AX2-12/13: Pass Points never change XP or Gems (own unit, own ledger)', function () {
    $b = e40_build();
    $user = e40_player();
    $before = e40_economy($user);

    e40_grant($user, $b['pass'], $b['quests'][0], 4);
    e40_claims()->collectAll($user, $b['pass']);

    expect(e40_points_of($user, $b['pass']))->toBe(200)
        ->and(e40_economy($user))->toBe($before);
});

test('AX2-14/15: Pass Points never touch season, competition, referral, sponsorship, creator or reputation tables', function () {
    $b = e40_build();
    $user = e40_player();
    $before = e40_foreign_counts();

    e40_grant($user, $b['pass'], $b['quests'][0], 5);
    e40_buy_premium($user, $b['pass']);
    e40_claims()->collectAll($user, $b['pass']);

    expect(e40_foreign_counts())->toBe($before);
});

test('AX2-16: the same completion never grants twice (idempotent source key)', function () {
    $b = e40_build();
    $user = e40_player();
    $row = e40_completion($user, $b['quests'][0], now()->subHour());

    expect(e40_points()->onQuestCompleted($user, $row))->not->toBeNull()
        ->and(e40_points()->onQuestCompleted($user, $row))->toBeNull()
        ->and(e40_points()->healFor($user, $b['pass']))->toBe(0)
        ->and(PassPointGrant::query()->count())->toBe(1)
        ->and(e40_points_of($user, $b['pass']))->toBe(50);
});

test('AX2-16b: the database rejects a duplicate source key outright', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);
    $row = e40_completion($user, $b['quests'][0], now()->subHour());
    e40_points()->onQuestCompleted($user, $row);
    $grant = PassPointGrant::query()->first();

    expect(fn () => DB::table('pass_point_grants')->insert([
        'user_pass_progress_id' => $grant->user_pass_progress_id, 'user_id' => $user->id, 'ahjiyat_pass_id' => $b['pass']->id,
        'ahjiyat_pass_mission_id' => $grant->ahjiyat_pass_mission_id, 'user_quest_progress_id' => $row->id, 'source_type' => 'mission',
        'source_key' => $grant->source_key, 'points' => 50, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('AX2-17: pass_points always equals the sum of its ledger (derived, never incremented blindly)', function () {
    $b = e40_build();
    $user = e40_player();
    e40_grant($user, $b['pass'], $b['quests'][0], 6);

    expect(e40_points_of($user, $b['pass']))->toBe((int) PassPointGrant::query()->where('user_id', $user->id)->sum('points'));
});

test('AX2-18: a frozen account earns nothing and cannot even create progress', function () {
    $b = e40_build();
    $user = e40_player();
    $user->forceFill(['is_frozen' => true])->save();
    e40_completion($user, $b['quests'][0], now()->subHour());

    expect(e40_points()->healFor($user->fresh(), $b['pass']))->toBe(0)
        ->and(e40_reason(fn () => e40_progress()->ensure($user->fresh(), $b['pass'])))->toBe('frozen')
        ->and(DB::table('user_pass_progress')->count())->toBe(0);
});

test('AX2-19: completion exactly at the start counts; exactly at the end and before the start do not', function () {
    $b = e40_build(['starts' => '2026-12-01 00:00:00', 'days' => 30]);
    $user = e40_player();
    $quest = $b['quests'][0];

    e40_completion($user, $quest, '2026-11-30 23:59:59', 'daily:before');
    e40_completion($user, $quest, '2026-12-01 00:00:00', 'daily:at-start');
    e40_completion($user, $quest, '2026-12-31 00:00:00', 'daily:at-end');
    e40_completion($user, $quest, '2026-12-09 10:00:00', 'daily:inside');

    expect(e40_points()->healFor($user, $b['pass']))->toBe(2);
});

test('AX2-20: the per-mission completion cap bounds farming', function () {
    $b = e40_build(['missions' => [['difficulty' => 'easy', 'points' => 10, 'max' => 3]], 'tiers' => 3, 'step' => 10]);
    $user = e40_player();

    expect(e40_grant($user, $b['pass'], $b['quests'][0], 6))->toBe(3)
        ->and(e40_points_of($user, $b['pass']))->toBe(30);
});

// ───────────────────────────── AX3: إعادة استخدام المهام ─────────────────────────────

test('AX3-17: a linked QuestDefinition drives the mission through the real puzzle-solving path', function () {
    $b = e40_build(['missions' => [['difficulty' => 'medium', 'points' => 75, 'max' => 6]]]);
    $user = e40_player();

    e34_solve($user);

    expect(e40_points_of($user, $b['pass']))->toBe(75);
});

test('AX3-18: UserQuestProgress stays the source of truth — the grant points at it and it is not altered', function () {
    $b = e40_build();
    $user = e40_player();
    e34_solve($user);

    $progress = UserQuestProgress::query()->where('user_id', $user->id)->where('quest_definition_id', $b['quests'][0]->id)->first();
    $grant = PassPointGrant::query()->where('user_id', $user->id)->first();

    expect($progress->completed_at)->not->toBeNull()
        ->and((int) $grant->user_quest_progress_id)->toBe((int) $progress->id)
        ->and($grant->source_key)->toBe(PassPointService::sourceKey($b['pass'], $user, $progress));
});

test('AX3-19: no second evaluator — the pass code never inspects puzzle attempts or evaluates conditions itself', function () {
    $forbidden = ['PuzzleAttempt', 'puzzle_attempts', 'QuestEvaluator', 'evaluateForEvent', 'PUZZLES_SOLVED', 'condition_type =='];

    foreach (File::allFiles(app_path('Services/AhjiyatPass')) as $file) {
        $source = file_get_contents($file->getPathname());

        foreach ($forbidden as $needle) {
            if ($needle === 'QuestEvaluator' && str_ends_with($file->getFilename(), 'PassRules.php')) {
                continue; // الصحة فقط عبر QuestEvaluatorRegistry::isValidConditionType (لا تقييم)
            }

            expect(str_contains($source, $needle))->toBeFalse("{$file->getFilename()} contains {$needle}");
        }
    }
});

test('AX3-20: an incomplete quest grants no points', function () {
    $quest = QuestDefinition::factory()->daily(3)->create();
    $b = e40_build(['missions' => [['difficulty' => 'easy', 'points' => 50, 'max' => 14, 'quest' => $quest]]]);
    $user = e40_player();

    e34_solve($user);

    $progress = UserQuestProgress::query()->where('user_id', $user->id)->where('quest_definition_id', $quest->id)->first();
    expect($progress->completed_at)->toBeNull()->and(e40_points_of($user, $b['pass']))->toBe(0);

    e34_solve($user);
    e34_solve($user);
    expect(e40_points_of($user, $b['pass']))->toBe(50);
});

test('AX3-21: a completed quest grants exactly the configured points', function () {
    $b = e40_build(['tiers' => 3, 'missions' => [['difficulty' => 'hard', 'points' => 130, 'max' => 2]]]);
    $user = e40_player();
    e34_solve($user);

    expect(e40_points_of($user, $b['pass']))->toBe(130)
        ->and(PassPointGrant::query()->where('user_id', $user->id)->value('points'))->toBe(130);
});

test('AX3-22: re-running the quest evaluation after completion never grants again', function () {
    $b = e40_build();
    $user = e40_player();
    e34_solve($user);
    $quests = app(QuestService::class);

    $quests->evaluateQuest($user, $b['quests'][0]);
    $quests->evaluateQuest($user, $b['quests'][0]);
    $quests->syncCurrentQuests($user);

    expect(PassPointGrant::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('AX3-23: a quest that is not a mission gives no pass points', function () {
    $b = e40_build();
    $other = e40_quest(['name' => 'مهمة غير مرتبطة']);
    $user = e40_player();
    e40_completion($user, $other, now()->subHour());

    expect(e40_points()->healFor($user, $b['pass']))->toBe(0)
        ->and(e40_points()->onQuestCompleted($user, UserQuestProgress::query()->where('quest_definition_id', $other->id)->first()))->toBeNull();
});

test('AX3-24: a deactivated quest stops generating live completions, history stays, and reconcile flags it', function () {
    $b = e40_build();
    $user = e40_player();
    e34_solve($user);
    expect(e40_points_of($user, $b['pass']))->toBe(50);

    $b['quests'][0]->forceFill(['is_active' => false])->save();
    e34_solve($user);
    e34_solve($user);

    expect(e40_points_of($user, $b['pass']))->toBe(50);

    $codes = collect(app(\App\Services\AhjiyatPass\PassReconciler::class)->run(false)['findings'])->pluck('code');
    expect($codes)->toContain('mission_quest_inactive');
});

test('AX3-24b: a quest that belongs to an E34 mission pool cannot back a pass mission', function () {
    $quest = e34_quest();
    e34_pool([$quest, e34_quest()]);
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'missions' => []])['pass'] ?? null;
    $pass = e40_builder()->createDraft($admin, ['internal_key' => 'pooled-try', 'name' => 'مجمّعة', 'starts_at' => now()->format('Y-m-d H:i:s')]);

    expect(e40_reason(fn () => e40_builder()->attachMission($admin, $pass, $quest, 'easy')))->toBe('quest_pooled');
});

test('AX3-24c: pass-linked quest definitions are protected from semantics-changing edits and deletion', function () {
    $b = e40_build();
    $quest = $b['quests'][0];

    expect(fn () => $quest->fresh()->update(['target_value' => 99]))->toThrow(\App\Exceptions\StoreItemInvariantViolation::class)
        ->and(fn () => $quest->fresh()->update(['period_type' => QuestDefinition::PERIOD_WEEKLY]))->toThrow(\App\Exceptions\StoreItemInvariantViolation::class)
        ->and(fn () => $quest->fresh()->delete())->toThrow(\App\Exceptions\StoreItemInvariantViolation::class);

    $quest->fresh()->update(['name' => 'اسم معروض جديد']);
    expect($quest->fresh()->name)->toBe('اسم معروض جديد');
});

test('AX3-25: self-heal recovers a missed grant exactly once', function () {
    $b = e40_build();
    $user = e40_player();
    e40_completion($user, $b['quests'][0], now()->subHours(2)); // الخطّاف لم يعمل
    expect(PassPointGrant::query()->count())->toBe(0);

    $this->actingAs($user)->get(route('pass.index'))->assertOk();

    expect(e40_points_of($user, $b['pass']))->toBe(50);

    $this->actingAs($user)->get(route('pass.index'))->assertOk();
    expect(e40_points_of($user, $b['pass']))->toBe(50)->and(PassPointGrant::query()->count())->toBe(1);
});

test('AX3-25b: a failure inside the pass hook never rolls back or blocks the quest reward', function () {
    $b = e40_build(['missions' => [['difficulty' => 'easy', 'points' => 50, 'max' => 14, 'quest' => QuestDefinition::factory()->daily(1)->create(['xp_reward' => 25])]]]);
    $user = e40_player();

    app()->bind(PassPointService::class, fn () => new class extends PassPointService
    {
        public function __construct() {}

        public function onQuestCompleted(User $user, UserQuestProgress $questProgress): ?PassPointGrant
        {
            throw new \RuntimeException('simulated pass failure');
        }
    });

    e34_solve($user);

    $progress = UserQuestProgress::query()->where('user_id', $user->id)->where('quest_definition_id', $b['quests'][0]->id)->first();
    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->reward_granted_at)->not->toBeNull()
        ->and(DB::table('xp_transactions')->where('user_id', $user->id)->where('amount', 25)->exists())->toBeTrue()
        ->and(PassPointGrant::query()->count())->toBe(0);

    // لاحقًا تُستعاد النقاط ذاتيًا
    app()->forgetInstance(PassPointService::class);
    app()->bind(PassPointService::class, fn () => new PassPointService(app(\App\Services\AhjiyatPass\PassLifecycleService::class), app(\App\Services\AhjiyatPass\PassDefinitionCache::class), app(\App\Services\AhjiyatPass\PassProgressService::class)));
    expect(e40_points()->healFor($user, $b['pass']))->toBe(1);
});

// ───────────────────────────── AX4: الصعوبة ─────────────────────────────

test('AX4-26/27/28: easy, medium and hard are stored and exposed as mission metadata', function () {
    $b = e40_build(['missions' => [
        ['difficulty' => 'easy', 'points' => 50, 'max' => 14],
        ['difficulty' => 'medium', 'points' => 100, 'max' => 4],
        ['difficulty' => 'hard', 'points' => 200, 'max' => 4],
    ]]);

    $byDifficulty = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $b['pass']->id)->pluck('pass_points', 'difficulty')->all();
    $track = e40_cache()->track($b['pass']);

    expect($byDifficulty)->toBe(['easy' => 50, 'medium' => 100, 'hard' => 200])
        ->and(collect($track->missions)->pluck('difficulty')->sort()->values()->all())->toBe(['easy', 'hard', 'medium']);

    $user = e40_player();
    $this->actingAs($user)->get(route('pass.index'))->assertOk()->assertSee('سهلة')->assertSee('متوسطة')->assertSee('صعبة');
});

test('AX4-28b: an unknown difficulty is rejected', function () {
    $admin = e40_admin();
    $pass = e40_builder()->createDraft($admin, ['internal_key' => 'diff-bad', 'name' => 'صعوبة', 'starts_at' => now()->format('Y-m-d H:i:s')]);

    expect(e40_reason(fn () => e40_builder()->attachMission($admin, $pass, e40_quest(), 'impossible')))->toBe('difficulty');
});

test('AX4-29: difficulty never changes how the quest is evaluated — the definition is untouched by attaching', function () {
    $quest = QuestDefinition::factory()->daily(2)->create();
    $before = $quest->fresh()->only(['period_type', 'condition_type', 'target_value', 'scope_type', 'scope_id', 'xp_reward']);

    $b = e40_build(['missions' => [['difficulty' => 'hard', 'points' => 200, 'max' => 4, 'quest' => $quest]]]);

    expect($quest->fresh()->only(['period_type', 'condition_type', 'target_value', 'scope_type', 'scope_id', 'xp_reward']))->toBe($before);

    $user = e40_player();
    e34_solve($user);
    expect(e40_points_of($user, $b['pass']))->toBe(0); // الهدف 2 لم يتحقق رغم أن المهمة «صعبة»
    e34_solve($user);
    expect(e40_points_of($user, $b['pass']))->toBe(200);
});

test('AX4-30: admin-configured point values per mission are applied exactly (not hard-coded by difficulty)', function () {
    $b = e40_build(['tiers' => 3, 'step' => 5, 'missions' => [
        ['difficulty' => 'easy', 'points' => 7, 'max' => 14],
        ['difficulty' => 'hard', 'points' => 13, 'max' => 14],
    ]]);
    $user = e40_player();
    e40_completion($user, $b['quests'][0], now()->subHours(3));
    e40_completion($user, $b['quests'][1], now()->subHours(2));
    e40_points()->healFor($user, $b['pass']);

    expect(e40_points_of($user, $b['pass']))->toBe(20);
});

test('AX4-31: points per mission and completion caps respect the configured bounds', function () {
    $admin = e40_admin();
    $pass = e40_builder()->createDraft($admin, ['internal_key' => 'bounds', 'name' => 'حدود', 'starts_at' => now()->format('Y-m-d H:i:s')]);

    expect(e40_reason(fn () => e40_builder()->attachMission($admin, $pass, e40_quest(), 'easy', ['points' => 0])))->toBe('points')
        ->and(e40_reason(fn () => e40_builder()->attachMission($admin, $pass, e40_quest(), 'easy', ['points' => 100000])))->toBe('points')
        ->and(e40_reason(fn () => e40_builder()->attachMission($admin, $pass, e40_quest(), 'easy', ['points' => 10, 'max_completions' => 999])))->toBe('max_completions');
});
