<?php

use App\Filament\Resources\PuzzleResource;
use App\GameEngine\GameTypeRegistry;
use App\Models\CampaignGateQualification;
use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Services\Competitive\CompetitiveEligibility;
use App\Services\Engagement\QuestService;
use App\Services\GameSessionService;
use App\Services\PuzzleAttemptService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** @return array{0: CreatorPuzzle, 1: Puzzle} عمل منشور وأحجيته القانونية. */
function e28_live(string $kind = 'text', array $over = []): array
{
    $work = e28_published(e28_creator(), $kind, $over);

    return [$work, $work->publishedPuzzle];
}

test('Y3-23 (23): a creator cannot set a gem reward (create or update) and the canonical puzzle always has zero', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['gem_reward', 'reward_currency_id'] as $field) {
        $this->post(route('creator.puzzles.store'), e28_data('text', [$field => 500]))->assertSessionHasErrors($field);
    }

    $work = e28_draft($creator)->work;
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('text', ['gem_reward' => 9]))->assertSessionHasErrors('gem_reward');

    $published = e28_published($creator);
    expect((int) $published->publishedPuzzle->gem_reward)->toBe(0)->and($published->publishedPuzzle->reward_currency_id)->toBeNull();

    // حتى كتابة مباشرة بالنموذج لا تنجو: الإنشاء يصفّر المكافآت (رفض افتراضي على مستوى النموذج).
    $forced = new Puzzle;
    $forced->forceFill(['puzzle_category_id' => e28_category()->id, 'title' => 'x', 'type' => 'text', 'difficulty' => 'easy', 'prompt' => 'سؤال طويل بما يكفي', 'answer_raw' => 'a', 'max_attempts' => 3, 'gem_reward' => 999, 'xp_reward' => 999, 'is_active' => true, 'origin' => 'community'])->save();
    expect((int) $forced->fresh()->gem_reward)->toBe(0)->and((int) $forced->fresh()->xp_reward)->toBe(0)->and($forced->fresh()->is_active)->toBeFalse();
});

test('Y3-24 (24): a creator cannot set an XP reward and the canonical puzzle always has zero', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('text', ['xp_reward' => 500]))->assertSessionHasErrors('xp_reward');

    $puzzle = e28_published($creator)->publishedPuzzle;
    expect((int) $puzzle->xp_reward)->toBe(0)->and((int) config('creators.puzzle.xp_reward'))->toBe(0)->and((int) config('creators.puzzle.gem_reward'))->toBe(0);
});

test('Y3-25 (25): a creator cannot make a puzzle the daily puzzle', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['is_daily_puzzle' => true]))->assertSessionHasErrors('is_daily_puzzle');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['daily_puzzle_date' => today()->toDateString()]))->assertSessionHasErrors('daily_puzzle_date');

    $puzzle = e28_published($creator)->publishedPuzzle;
    expect($puzzle->is_daily_puzzle)->toBeFalse()->and($puzzle->daily_puzzle_date)->toBeNull()->and($puzzle->is_active)->toBeFalse()->and($puzzle->origin)->toBe('community');
});

test('Y3-26 (26): a creator cannot attach a puzzle to an official campaign / season / competition / challenge, and admin pickers never offer community puzzles', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    // طلب واحد بكل حقول الربط الرسمي (محدِّد creator-draft-create يسمح بخمس مسودات بالدقيقة): كلها ترفض صراحة لا تُتجاهل بصمت.
    $fields = ['campaign_id', 'campaign_step_id', 'campaign_gate_id', 'season_id', 'competition_id', 'competitive_event_id', 'challenge_id'];
    $this->post(route('creator.puzzles.store'), e28_data('text', array_fill_keys($fields, 1)))->assertSessionHasErrors($fields);
    expect(CreatorPuzzle::query()->count())->toBe(0);

    $community = e28_published($creator)->publishedPuzzle;
    $official = Puzzle::factory()->create();

    // منتقيات الإدارة (الأحجيات/الحملات/التحديات/المنافسات) مبنية على official().
    expect(Puzzle::query()->official()->pluck('id')->all())->toContain($official->id)->not->toContain($community->id);
    $this->actingAs(e28_reviewer('administrator'));
    expect(PuzzleResource::getEloquentQuery()->pluck('puzzles.id')->all())->toContain($official->id)->not->toContain($community->id);
    expect(app(CompetitiveEligibility::class)->eligiblePuzzlesQuery(false)->pluck('puzzles.id')->all())->not->toContain($community->id);
});

test('Y3-27 (27): a correct community solve grants zero gems - no wallet change, no transaction rows', function () {
    [$work] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHas('success');

    expect(CommunityPuzzleAttempt::query()->where('user_id', $player->id)->where('is_correct', true)->count())->toBe(1);
    $after = e28_world($player);
    expect($after['wallet'])->toBe($before['wallet'])->and($after['counts']['GemTransaction'])->toBe($before['counts']['GemTransaction'])->and($after['counts']['CurrencyTransaction'])->toBe($before['counts']['CurrencyTransaction']);
});

test('Y3-28 (28): a correct community solve grants zero XP - no XP transaction, no level progress', function () {
    [$work] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    $after = e28_world($player);
    expect($after['xp'])->toBe($before['xp'])->and($after['counts']['XpTransaction'])->toBe($before['counts']['XpTransaction'])->and($after['counts']['PlayerProgression'])->toBe($before['counts']['PlayerProgression'])
        ->and($after['counts']['UserAchievementProgress'])->toBe($before['counts']['UserAchievementProgress']);

    // ضابط (control): الحل الرسمي بالمستخدم نفسه يغيّر الحالة فعلًا، فاللقطة قادرة على كشف أي تسرّب.
    $official = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 12, 'xp_reward' => 10]);
    app(PuzzleAttemptService::class)->attempt($player, $official, 'صح');
    expect(e28_world($player))->not->toBe($after);
});

test('Y3-29 (29): a community solve does not progress a daily quest (while an official solve does)', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    [$work] = e28_live();
    $player = e16User();

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    expect((int) app(QuestService::class)->progressFor($player, $quest)->current_value)->toBe(0)->and(e28_world($player)['quest'])->toBe(0);

    app(PuzzleAttemptService::class)->attempt($player, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');
    expect((int) app(QuestService::class)->progressFor($player, $quest)->fresh()->current_value)->toBeGreaterThan(0);
});

test('Y3-30 (30): a community solve does not touch the official streak (while an official solve does)', function () {
    [$work] = e28_live();
    $player = e16User();

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);
    expect(e28_world($player)['streak'])->toBe(0)->and(e28_world($player)['counts']['PlayerStreak'])->toBe(0);

    app(PuzzleAttemptService::class)->attempt($player, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');
    expect(e28_world($player)['streak'])->toBe(1);
});

test('Y3-31 (31): a community puzzle can never qualify a campaign gate - every official engine path refuses it', function () {
    [, $community] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    expect(fn () => app(PuzzleAttemptService::class)->attempt($player, $community, 'القلم'))->toThrow(\RuntimeException::class);
    expect(fn () => app(PuzzleAttemptService::class)->purchaseHint($player, $community))->toThrow(\RuntimeException::class);
    expect(fn () => app(GameSessionService::class)->start($player, $community))->toThrow(\RuntimeException::class);

    expect(CampaignGateQualification::query()->count())->toBe(0)->and(PuzzleAttempt::query()->where('puzzle_id', $community->id)->count())->toBe(0)->and(e28_world($player))->toBe($before);

    // المسارات الرسمية العامة (الويب وAPI) تعامل أحجية المجتمع كغير موجودة.
    $this->actingAs($player);
    $this->get(route('puzzles.show', $community))->assertNotFound();
    $this->post(route('puzzles.attempt', $community), ['answer' => 'القلم'])->assertNotFound();
});

test('Y3-32 (32): a community puzzle never enters an official competition, challenge or official leaderboard', function () {
    [$work, $community] = e28_live();
    $eligibility = app(CompetitiveEligibility::class);

    expect($eligibility->reasonIfIneligible($community, false))->toBe('أحجيات المجتمع لا تصلح للمنافسات.')->and($eligibility->isEligible($community))->toBeFalse();

    $player = e16User();
    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    // لا صف يدخل لوحة الصدارة الرسمية أو تأهيل المنافسات: جدول المحاولات الرسمي فارغ، وجدول المجتمع هو الوحيد.
    expect(PuzzleAttempt::query()->count())->toBe(0)->and(CommunityPuzzleAttempt::query()->count())->toBe(1)->and(e28_world($player)['counts']['CompetitiveEventParticipant'])->toBe(0);
    $this->get(route('leaderboard.index'))->assertOk();

    // الأحجية القانونية غير مفعّلة رسميًا ولا تُعدَّل أو تُحذف.
    expect(fn () => $community->forceFill(['is_active' => true])->save())->toThrow(\LogicException::class)
        ->and(fn () => $community->delete())->toThrow(\LogicException::class);
    expect(app(GameTypeRegistry::class)->validatorFor($community))->not->toBeNull();
});
