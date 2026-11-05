<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Puzzle;
use App\Services\Cinematic\Media\CinematicMediaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - اللعب: التقدّم قرار خادمي بحت. فتح خطي، لا تخطي، لا إتمام مزدوج، لا مكافأة على ادعاء «شاهدت»، محاولات محدودة، إعادة وتحمّل نسخ، عزل المستخدمين، لا اقتصاد.
*/

beforeEach(fn () => cin_seed());

/** قصة منشورة بثلاثة مشاهد إلزامية: سرد ← تفاعل ← سرد. @return array<string, mixed> */
function cin_three(array $extra = []): array
{
    $w = cin_story();
    [$public, $solution] = cin_choose('b');
    $one = cin_narrative($w['chapter'], 'scene_one');
    $two = cin_interaction($w['chapter'], 'scene_two', $public, $solution, $extra['two'] ?? []);
    $three = cin_narrative($w['chapter'], 'scene_three');
    $live = cin_publish($w['revision']);

    return $w + ['one' => $one->fresh(), 'two' => $two->fresh(), 'three' => $three->fresh(), 'live' => $live];
}

test('PLAY-01: starting creates exactly one progress row pinned to the live revision; resuming returns the same row; an unpublished story cannot be started', function () {
    $w = cin_three();
    $user = cin_user();

    $a = cin_play()->begin($user, $w['story']);
    $b = cin_play()->begin($user, $w['story']);

    expect($b->id)->toBe($a->id)->and($a->revision_id)->toBe($w['live']->id)->and($a->status)->toBe('in_progress')
        ->and(CinematicProgress::query()->where('user_id', $user->id)->count())->toBe(1);

    cin_publishing()->unpublish($w['live']->fresh());
    expect(cin_reason(fn () => cin_play()->begin(cin_user(), $w['story']->fresh())))->toBe('unavailable');
});

test('PLAY-02: scenes unlock strictly in order - nothing later can be started, finished or answered before the earlier required scenes are done', function () {
    $w = cin_three();
    [$p] = cin_begin($w['story']);

    $s = cin_play()->state($p);
    expect(cin_status($s, 'scene_one'))->toBe('available')->and(cin_status($s, 'scene_two'))->toBe('locked')->and(cin_status($s, 'scene_three'))->toBe('locked');

    expect(cin_reason(fn () => cin_play()->start($p, $w['two'])))->toBe('scene_locked')
        ->and(cin_reason(fn () => cin_play()->finish($p, $w['three'], 'read')))->toBe('scene_locked')
        ->and(cin_reason(fn () => cin_play()->submit($p, $w['two'], ['item' => 'b'])))->toBe('scene_locked');

    expect(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(0);

    cin_play()->finish($p, $w['one'], 'read');
    $s = cin_play()->state($p->fresh());
    expect(cin_status($s, 'scene_one'))->toBe('completed')->and(cin_status($s, 'scene_two'))->toBe('available')->and(cin_status($s, 'scene_three'))->toBe('locked')->and($s['current'])->toBe($w['two']->public_id);
});

test('PLAY-03: an optional scene never blocks the ones after it, and an unlock rule can still gate a scene on a specific earlier scene', function () {
    $w = cin_story();
    $a = cin_narrative($w['chapter'], 'req_a');
    $opt = cin_narrative($w['chapter'], 'opt_b', ['is_optional' => true]);
    $gated = cin_narrative($w['chapter'], 'gated_c', ['unlock_rule' => ['requires' => ['opt_b']]]);
    $free = cin_narrative($w['chapter'], 'free_d');
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);

    cin_play()->finish($p, $a, 'read');
    $s = cin_play()->state($p->fresh());

    // الاختياري متاح، والمشهد المشروط مقفل حتى يُنجز الاختياري، وغير المشروط الذي بعده متاح لأن الاختياري لا يحجب.
    expect(cin_status($s, 'opt_b'))->toBe('available')->and(cin_status($s, 'gated_c'))->toBe('locked')->and(cin_status($s, 'free_d'))->toBe('locked');
    expect(cin_reason(fn () => cin_play()->finish($p, $gated->fresh(), 'read')))->toBe('scene_locked');

    cin_play()->finish($p, $opt->fresh(), 'read');
    $s = cin_play()->state($p->fresh());
    expect(cin_status($s, 'gated_c'))->toBe('available');
});

test('PLAY-04: claiming to have watched a video earns nothing - the server needs real elapsed time since the scene was started, and no media means no "watched"', function () {
    Carbon::setTestNow('2026-12-10 12:00:00');
    $w = cin_story();
    $film = cin_video_scene($w['chapter'], 'film', ['config' => ['transcript' => [['speaker' => 'x', 'text' => 'نص بديل']]]], cin_long_video_asset(100000));
    cin_narrative($w['chapter'], 'after_film');
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);
    $film = $film->fresh();

    // ادعاء فوري بلا بدء ⇒ مرفوض؛ ثم بعد البدء مباشرة ⇒ مرفوض.
    expect(cin_reason(fn () => cin_play()->finish($p, $film, 'watched')))->toBe('too_early');
    cin_play()->start($p, $film);
    expect(cin_reason(fn () => cin_play()->finish($p, $film, 'watched')))->toBe('too_early');
    Carbon::setTestNow('2026-12-10 12:00:20');
    expect(cin_reason(fn () => cin_play()->finish($p, $film, 'watched')))->toBe('too_early');

    // نصف المدة (50 ثانية) مرّ فعلًا على ساعة الخادم.
    Carbon::setTestNow('2026-12-10 12:00:52');
    cin_play()->finish($p, $film, 'watched');
    expect(cin_status(cin_play()->state($p->fresh()), 'film'))->toBe('completed')
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->first()->completion_mode)->toBe('watched');

    Carbon::setTestNow();
});

test('PLAY-05: a non-skippable video cannot be skipped, the text alternative needs a transcript, and nothing after it opens until it is genuinely finished', function () {
    $w = cin_story();
    $noskip = cin_video_scene($w['chapter'], 'film_noskip', ['config' => ['transcript' => []]]);
    $text = cin_narrative($w['chapter'], 'plain_text');
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);

    expect(cin_reason(fn () => cin_play()->finish($p, $noskip->fresh(), 'skipped')))->toBe('skip_denied')
        ->and(cin_reason(fn () => cin_play()->finish($p, $noskip->fresh(), 'read')))->toBe('no_text_alternative')
        ->and(cin_reason(fn () => cin_play()->finish($p, $text->fresh(), 'read')))->toBe('scene_locked')
        ->and(cin_status(cin_play()->state($p->fresh()), 'plain_text'))->toBe('locked');
});

test('PLAY-06: a skippable video can be skipped and the skip is recorded as a skip; scene types only accept their own finishing modes', function () {
    $w = cin_story();
    $skippable = cin_video_scene($w['chapter'], 'film_skip', ['is_skippable' => true]);
    $text = cin_narrative($w['chapter'], 'plain_text');
    [$public, $solution] = cin_choose('a');
    $quiz = cin_interaction($w['chapter'], 'quiz_it', $public, $solution);
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);

    cin_play()->finish($p, $skippable->fresh(), 'skipped');
    expect(CinematicSceneState::query()->where('progress_id', $p->id)->first()->completion_mode)->toBe('skipped');

    expect(cin_reason(fn () => cin_play()->finish($p->fresh(), $text->fresh(), 'watched')))->toBe('mode_not_allowed')
        ->and(cin_reason(fn () => cin_play()->finish($p->fresh(), $text->fresh(), 'skipped')))->toBe('mode_not_allowed')
        ->and(cin_reason(fn () => cin_play()->submit($p->fresh(), $text->fresh(), ['item' => 'a'])))->toBe('mode_not_allowed');

    cin_play()->finish($p->fresh(), $text->fresh(), 'read');
    expect(cin_reason(fn () => cin_play()->finish($p->fresh(), $quiz->fresh(), 'read')))->toBe('mode_not_allowed');
});

test('PLAY-07: an interaction is completed only by a correct server-checked answer; wrong answers count attempts; a finished scene is never re-completed or re-counted', function () {
    $w = cin_three();
    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    $wrong = cin_play()->submit($p->fresh(), $w['two'], ['item' => 'a']);
    expect($wrong['result']['correct'])->toBeFalse()->and(cin_status($wrong, 'scene_two'))->toBe('available')->and(collect($wrong['scenes'])->firstWhere('key', 'scene_two')['attempts'])->toBe(1);

    $right = cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    expect($right['result']['correct'])->toBeTrue()->and(cin_status($right, 'scene_two'))->toBe('completed')->and(collect($right['scenes'])->firstWhere('key', 'scene_two')['attempts'])->toBe(2);

    $row = CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $w['two']->id)->first();
    $stamp = $row->completed_at->toDateTimeString();

    Carbon::setTestNow(now()->addMinutes(5));
    $again = cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    Carbon::setTestNow();

    expect($again['result']['correct'])->toBeTrue()
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $w['two']->id)->count())->toBe(1)
        ->and($row->fresh()->completed_at->toDateTimeString())->toBe($stamp)->and($row->fresh()->attempts_count)->toBe(2);
});

test('PLAY-08: attempts are capped per scene (configured on the scene or by the global default) and exhaustion blocks even a correct answer', function () {
    $w = cin_three(['two' => ['config' => ['max_attempts' => 2, 'interaction' => cin_choose('b')[0]]]]);
    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'a']);
    $second = cin_play()->submit($p->fresh(), $w['two'], ['item' => 'c']);
    expect($second['result']['attempts_left'])->toBe(0);

    expect(cin_reason(fn () => cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b'])))->toBe('attempts_exhausted')
        ->and(cin_status(cin_play()->state($p->fresh()), 'scene_two'))->toBe('available');
});

test('PLAY-09: finishing the same scene twice leaves one row and one completion time; the story completes exactly once when every required scene is done', function () {
    $w = cin_three();
    [$p] = cin_begin($w['story']);

    cin_play()->finish($p, $w['one'], 'read');
    $first = CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $w['one']->id)->first()->completed_at->toDateTimeString();
    Carbon::setTestNow(now()->addHour());
    cin_play()->finish($p->fresh(), $w['one'], 'read');
    Carbon::setTestNow();

    expect(CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $w['one']->id)->count())->toBe(1)
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $w['one']->id)->first()->completed_at->toDateTimeString())->toBe($first);

    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    expect($p->fresh()->status)->toBe('in_progress')->and($p->fresh()->completed_at)->toBeNull();

    cin_play()->finish($p->fresh(), $w['three'], 'read');
    $done = $p->fresh();
    expect($done->status)->toBe('completed')->and($done->completed_at)->not->toBeNull();

    $stamp = $done->completed_at->toDateTimeString();
    Carbon::setTestNow(now()->addDay());
    cin_play()->finish($p->fresh(), $w['three'], 'read');
    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    Carbon::setTestNow();

    expect($p->fresh()->completed_at->toDateTimeString())->toBe($stamp)->and(cin_play()->state($p->fresh())['progress']['percent'])->toBe(100);
});

test('PLAY-10: replay is allowed only where the author permits it and never changes the completion record', function () {
    $w = cin_story();
    $replayable = cin_narrative($w['chapter'], 'again_ok', ['allow_replay' => true]);
    $once = cin_narrative($w['chapter'], 'once_only', ['allow_replay' => false]);
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);

    cin_play()->finish($p, $replayable->fresh(), 'read');
    cin_play()->finish($p->fresh(), $once->fresh(), 'read');
    $stamp = CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $replayable->id)->first()->completed_at->toDateTimeString();

    cin_play()->start($p->fresh(), $replayable->fresh());
    cin_play()->start($p->fresh(), $replayable->fresh());
    $row = CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $replayable->id)->first();

    expect($row->replay_count)->toBe(2)->and($row->completed_at->toDateTimeString())->toBe($stamp)
        ->and(cin_reason(fn () => cin_play()->start($p->fresh(), $once->fresh())))->toBe('replay_denied');
});

test('PLAY-11: a puzzle scene is judged by the official puzzle validator, completes on a correct answer, and writes no PuzzleAttempt, gems or XP', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'مفتاح']);
    $w = cin_story();
    $scene = cin_authoring()->addScene($w['chapter'], ['type' => 'puzzle', 'title' => 'أحجية', 'stable_key' => 'riddle_p', 'puzzle_id' => $puzzle->id]);
    cin_narrative($w['chapter'], 'after_p');
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);
    $before = cin_economy_snapshot();

    $miss = cin_play()->submit($p, $scene->fresh(), ['answer' => 'خطأ']);
    expect($miss['result']['correct'])->toBeFalse()->and(cin_status($miss, 'riddle_p'))->toBe('available');

    $hit = cin_play()->submit($p->fresh(), $scene->fresh(), ['answer' => 'مفتاح']);
    expect($hit['result']['correct'])->toBeTrue()->and(cin_status($hit, 'riddle_p'))->toBe('completed')
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->first()->completion_mode)->toBe('solved')
        ->and(cin_economy_snapshot())->toBe($before);

    // إيقاف الأحجية بعد النشر ⇒ المشهد يرفض الإجابة بأمان بدل أن ينكسر.
    $other = Puzzle::factory()->create(['answer_raw' => 'سر']);
    $w2 = cin_story();
    $s2 = cin_authoring()->addScene($w2['chapter'], ['type' => 'puzzle', 'title' => 'ثانية', 'stable_key' => 'riddle_q', 'puzzle_id' => $other->id]);
    cin_publish($w2['revision']);
    [$p2] = cin_begin($w2['story']);
    $other->forceFill(['is_active' => false])->save();
    expect(cin_reason(fn () => cin_play()->submit($p2, $s2->fresh(), ['answer' => 'سر'])))->toBe('puzzle_unavailable');
});

test('PLAY-12: a complete run from first scene to last changes no economy table at all', function () {
    $w = cin_three();
    $user = cin_user();
    $before = cin_economy_snapshot();

    [$p] = cin_begin($w['story'], $user);
    cin_play()->finish($p, $w['one'], 'read');
    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'a']);
    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    cin_play()->finish($p->fresh(), $w['three'], 'read');

    expect($p->fresh()->status)->toBe('completed')->and(cin_economy_snapshot())->toBe($before);
});

test('PLAY-13: players are isolated - another user cannot see, advance or complete someone else\'s progress, and the same scene is tracked per user', function () {
    $w = cin_three();
    [$a, $alice] = cin_begin($w['story']);
    [$b, $bob] = cin_begin($w['story']);

    cin_play()->finish($a, $w['one'], 'read');

    expect($a->id)->not->toBe($b->id)->and(cin_status(cin_play()->state($a->fresh()), 'scene_one'))->toBe('completed')
        ->and(cin_status(cin_play()->state($b->fresh()), 'scene_one'))->toBe('available')
        ->and(cin_play()->progressFor($bob, $w['story'])->id)->toBe($b->id)
        ->and(cin_play()->progressFor(cin_user(), $w['story']))->toBeNull();
});

test('PLAY-14: progress is pinned to the revision - a newer publication does not touch a running player, and a scene of another revision is "not found"', function () {
    $w = cin_three();
    [$p, $user] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    $draft = cin_authoring()->createDraft($w['story']);
    $v2 = cin_publish($draft);
    $v2Scene = $v2->scenes()->where('stable_key', 'scene_two')->first();

    expect($w['live']->fresh()->status)->toBe('superseded')
        ->and(cin_reason(fn () => cin_play()->start($p->fresh(), $v2Scene)))->toBe('scene_not_found');

    $state = cin_play()->state($p->fresh());
    expect($state['upgrade_available'])->toBeTrue()->and(cin_status($state, 'scene_one'))->toBe('completed');

    // اللاعب يكمل على نسخته (المستبدلة) بلا انقطاع.
    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    cin_play()->finish($p->fresh(), $w['three'], 'read');
    expect($p->fresh()->status)->toBe('completed');
});

test('PLAY-15: upgrading carries only completions of unchanged scenes (same key and same logic), never over-grants, never rewrites the old progress, and is idempotent', function () {
    $w = cin_three();
    [$p, $user] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');
    cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    $oldRows = CinematicSceneState::query()->where('progress_id', $p->id)->count();

    // نسخة جديدة: السرد الأول كما هو (نص معدّل فقط ⇒ المنطق نفسه)، والتفاعل يتغير حلّه ⇒ منطق مختلف، ومشهد جديد في النهاية.
    $draft = cin_authoring()->createDraft($w['story']);
    $one2 = $draft->scenes()->where('stable_key', 'scene_one')->first();
    cin_authoring()->updateScene($one2, ['config' => ['lines' => [['speaker' => '', 'text' => 'نص معدّل']]]]);
    $two2 = $draft->scenes()->where('stable_key', 'scene_two')->first();
    cin_authoring()->setInteractionSolution($two2, ['correct' => 'c']);
    $chapter2 = $draft->chapters()->first();
    cin_narrative($chapter2, 'scene_four');
    cin_publish($draft);

    $new = cin_play()->upgrade($user, $w['story']->fresh());
    $state = cin_play()->state($new->fresh());

    expect($new->id)->not->toBe($p->id)->and($new->migrated_from_id)->toBe($p->id)
        ->and(cin_status($state, 'scene_one'))->toBe('completed')
        ->and(CinematicSceneState::query()->where('progress_id', $new->id)->where('completion_mode', 'carried')->count())->toBe(1)
        ->and(cin_status($state, 'scene_two'))->toBe('available')      // المنطق تغيّر ⇒ لا تُحمل إتمامته
        ->and(cin_status($state, 'scene_three'))->toBe('locked')
        ->and($new->status)->toBe('in_progress')
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->count())->toBe($oldRows)
        ->and(cin_play()->upgrade($user, $w['story']->fresh())->id)->toBe($new->id)
        ->and(CinematicProgress::query()->where('user_id', $user->id)->count())->toBe(2);

    // من لم يبدأ يدخل مباشرة على النسخة الجديدة.
    $fresh = cin_play()->begin(cin_user(), $w['story']->fresh());
    expect($fresh->revision_id)->toBe($new->revision_id);
});

test('PLAY-16: unpublishing mid-play stops actions without deleting anything, and republishing lets the player continue exactly where they were', function () {
    $w = cin_three();
    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    cin_publishing()->unpublish($w['live']->fresh());
    expect(cin_reason(fn () => cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b'])))->toBe('unavailable')
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(1);

    cin_publishing()->publish($w['live']->fresh());
    $s = cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    expect(cin_status($s, 'scene_two'))->toBe('completed')->and(cin_status($s, 'scene_one'))->toBe('completed');
});

test('PLAY-17: replacing a published video file or its poster never touches anyone\'s progress', function () {
    $w = cin_story();
    $film = cin_video_scene($w['chapter'], 'film', ['is_skippable' => true]);
    cin_narrative($w['chapter'], 'after_film');
    cin_publish($w['revision']);
    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $film->fresh(), 'skipped');

    cin_authoring()->replaceMedia($film->fresh(), 'video', cin_long_video_asset(5000));
    cin_authoring()->replaceMedia($film->fresh(), 'poster', cin_poster_asset());
    app(CinematicMediaService::class)->retire($film->fresh()->video);

    expect(cin_status(cin_play()->state($p->fresh()), 'film'))->toBe('completed');
});

test('PLAY-18: resuming - state always points at the first available required scene, and fresh state for a guest reads without writing anything', function () {
    $w = cin_three();
    $writes = fn () => CinematicProgress::query()->count() + CinematicSceneState::query()->count();
    $before = $writes();

    $fresh = cin_play()->freshState($w['live']->fresh());
    expect($writes())->toBe($before)->and(cin_status($fresh, 'scene_one'))->toBe('available')->and($fresh['progress'])->toBeNull();

    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    expect(cin_play()->state($p->fresh())['current'])->toBe($w['two']->public_id)
        ->and(cin_play()->progressFor($p->user, $w['story'])->id)->toBe($p->id);
});

test('PLAY-19: concurrent-style repetition - hammering the same finish/submit never produces two completed rows or a second completion time (database unique + conditional write)', function () {
    $w = cin_three();
    [$p] = cin_begin($w['story']);
    cin_play()->finish($p, $w['one'], 'read');

    foreach (range(1, 6) as $i) {
        cin_play()->finish($p->fresh(), $w['one'], 'read');
        cin_play()->submit($p->fresh(), $w['two'], ['item' => 'b']);
    }

    expect(CinematicSceneState::query()->where('progress_id', $p->id)->count())->toBe(2)
        ->and(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(2);

    expect(fn () => DB::table('cinematic_scene_states')->insert(['progress_id' => $p->id, 'scene_id' => $w['one']->id, 'created_at' => now(), 'updated_at' => now()]))->toThrow(Illuminate\Database\QueryException::class);
});
