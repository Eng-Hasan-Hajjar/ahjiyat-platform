<?php

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\Media\CinematicMediaService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - النشر: لا نشر ناقص (وسائط/تفاعلات/بنية)، تنبيهات لا تمنع، دورة الحالة، نسخة منشورة واحدة، سحب وإعادة نشر، تأثير تغيير النسخة المنشورة.
*/

beforeEach(fn () => cin_seed());

function cin_codes(CinematicRevision $revision, string $kind = 'blockers'): array
{
    return array_column(app(CinematicReadinessChecker::class)->check($revision->fresh())[$kind], 'code');
}

test('PUB-01: an empty or structurally incomplete revision cannot be published', function () {
    $story = cin_authoring()->createStory(['title' => 'فارغة']);
    $revision = $story->revisions()->first();

    expect(cin_codes($revision))->toContain('no_stages');

    $stage = cin_authoring()->addStage($revision, ['title' => 'م']);
    expect(cin_codes($revision))->toContain('stage_empty');

    $chapter = cin_authoring()->addChapter($stage, ['title' => 'ف']);
    expect(cin_codes($revision))->toContain('chapter_empty');

    cin_narrative($chapter, 'sc_a', ['is_optional' => true]);
    expect(cin_codes($revision))->toContain('no_required_scene');

    cin_publishing()->submitForReview($revision->fresh());
    expect(cin_reason(fn () => cin_publishing()->publish($revision->fresh())))->toBe('not_ready')
        ->and($revision->fresh()->status)->toBe('in_review')->and($revision->fresh()->live_slot)->toBeNull()
        ->and($story->liveRevision)->toBeNull();
});

test('PUB-02: a video scene awaiting production blocks publishing and is reported as awaiting - the missing-media review', function () {
    $w = cin_story();
    cin_authoring()->addScene($w['chapter'], ['type' => 'video', 'title' => 'ينتظر الفيديو', 'stable_key' => 'film']);

    $report = app(CinematicReadinessChecker::class)->check($w['revision']->fresh());

    expect($report['ok'])->toBeFalse()
        ->and(array_column($report['blockers'], 'code'))->toContain('video_missing')
        ->and(collect($report['blockers'])->firstWhere('code', 'video_missing')['message'])->toContain('ينتظر إنتاج الفيديو')
        ->and(collect($report['blockers'])->firstWhere('code', 'video_missing')['scene'])->toBe('film');

    cin_publishing()->submitForReview($w['revision']->fresh());
    expect(cin_reason(fn () => cin_publishing()->publish($w['revision']->fresh())))->toBe('not_ready');
});

test('PUB-03: other incomplete scenes block too (empty narrative, missing interaction/solution, bad unlock rule, bad puzzle, premium without series) while missing poster/subtitle/transcript only warn', function () {
    $w = cin_story();
    cin_authoring()->addScene($w['chapter'], ['type' => 'narrative', 'title' => 'فارغ', 'stable_key' => 'nn']);
    cin_authoring()->addScene($w['chapter'], ['type' => 'transition', 'title' => 'انتقال', 'stable_key' => 'tt']);
    cin_authoring()->addScene($w['chapter'], ['type' => 'interaction', 'title' => 'بلا تعريف', 'stable_key' => 'i1']);
    $withDef = cin_authoring()->addScene($w['chapter'], ['type' => 'interaction', 'title' => 'بلا حل', 'stable_key' => 'i2', 'config' => ['interaction' => cin_choose()[0]]]);
    cin_authoring()->addScene($w['chapter'], ['type' => 'puzzle', 'title' => 'بلا أحجية', 'stable_key' => 'pp']);
    cin_authoring()->addScene($w['chapter'], ['type' => 'narrative', 'title' => 'شرط', 'stable_key' => 'uu', 'unlock_rule' => ['requires' => ['later_scene']], 'config' => ['lines' => ['نص']]]);

    expect(cin_codes($w['revision']))->toContain('narrative_empty', 'transition_empty', 'interaction_missing', 'solution_missing', 'puzzle_invalid', 'unlock_rule_invalid');

    $video = cin_video_scene($w['chapter'], 'vv', ['config' => []]);
    expect(cin_codes($w['revision'], 'warnings'))->toContain('poster_missing', 'subtitle_missing', 'transcript_missing')
        ->and(cin_codes($w['revision']))->not->toContain('video_missing');

    // قصة مميزة بلا سلسلة لا تُنشأ أصلًا (الملكية تُقرأ من سلسلة قائمة فقط).
    expect(cin_reason(fn () => cin_authoring()->createStory(['title' => 'مميزة', 'access_mode' => 'premium'])))->toBe('series_required');
});

test('PUB-04: a complete revision publishes through review; warnings do not block; only an in-review revision can be published', function () {
    $w = cin_story();
    cin_video_scene($w['chapter'], 'film', ['config' => []]);
    cin_narrative($w['chapter'], 'tail');

    expect(cin_reason(fn () => cin_publishing()->publish($w['revision']->fresh())))->toBe('revision_state');   // من المسودة مباشرة: لا

    $in = cin_publishing()->submitForReview($w['revision']->fresh());
    expect($in->status)->toBe('in_review')->and($in->readiness['ok'])->toBeTrue()->and($in->readiness['warnings'])->not->toBe([]);

    $live = cin_publishing()->publish($in->fresh(), $w['admin']);

    expect($live->status)->toBe('published')->and($live->live_slot)->toBe(1)->and($live->published_by)->toBe($w['admin']->id)->and($live->published_at)->not->toBeNull()
        ->and($w['story']->fresh()->liveRevision->id)->toBe($live->id);

    expect(cin_reason(fn () => cin_publishing()->submitForReview($live->fresh())))->toBe('revision_state')
        ->and(cin_reason(fn () => cin_publishing()->publish($live->fresh())))->toBe('revision_state');
});

test('PUB-05: exactly one revision is live per story; publishing a newer one supersedes the older, and the database itself refuses two live rows', function () {
    $w = cin_story();
    cin_narrative($w['chapter'], 'sc_a');
    $v1 = cin_publish($w['revision']);
    $v2 = cin_publish(cin_authoring()->createDraft($w['story']));

    expect($v1->fresh()->status)->toBe('superseded')->and($v1->fresh()->live_slot)->toBeNull()
        ->and($v2->fresh()->status)->toBe('published')
        ->and(CinematicRevision::query()->where('story_id', $w['story']->id)->where('status', 'published')->count())->toBe(1);

    expect(fn () => DB::table('cinematic_revisions')->where('id', $v1->id)->update(['live_slot' => 1]))->toThrow(QueryException::class);
});

test('PUB-06: unpublish hides the story without deleting anything; it can be published again only after a fresh readiness pass', function () {
    $w = cin_story();
    $film = cin_video_scene($w['chapter'], 'film');
    $live = cin_publish($w['revision']);

    cin_publishing()->unpublish($live->fresh());

    expect($live->fresh()->status)->toBe('unpublished')->and($live->fresh()->live_slot)->toBeNull()->and($w['story']->fresh()->liveRevision)->toBeNull()
        ->and(CinematicScene::query()->where('revision_id', $live->id)->count())->toBe(1);

    app(CinematicMediaService::class)->retire($film->fresh()->video);
    expect(cin_reason(fn () => cin_publishing()->publish($live->fresh())))->toBe('not_ready')
        ->and(cin_codes($live))->toContain('video_retired');

    app(CinematicMediaService::class)->restore($film->fresh()->video);
    expect(cin_publishing()->publish($live->fresh())->status)->toBe('published');
});

test('PUB-07: a retired or deleted video file after publication turns the scene into a safe "unavailable" state instead of breaking the series', function () {
    $w = cin_story();
    $film = cin_video_scene($w['chapter'], 'film');
    cin_narrative($w['chapter'], 'after');
    cin_publish($w['revision']);

    app(CinematicMediaService::class)->retire($film->fresh()->video);
    $user = cin_user();
    $payload = app(App\Services\Cinematic\CinematicScenePayload::class)->make($film->fresh()->load('video'), $user, 'available');

    expect($payload['video']['state'])->toBe('unavailable')->and($payload['video']['src'])->toBeNull()->and($payload['transcript'])->not->toBe([]);

    $progress = cin_play()->begin($user, $w['story']);
    cin_play()->start($progress, $film->fresh());
    // لا وسيط ⇒ لا «شاهدت»؛ لكن النص البديل يتيح المتابعة (لا ينكسر المسار).
    expect(cin_reason(fn () => cin_play()->finish($progress, $film->fresh(), 'watched')))->toBe('no_media');
    cin_play()->finish($progress, $film->fresh(), 'read');
    expect(collect(cin_play()->state($progress->fresh())['scenes'])->firstWhere('key', 'film')['status'])->toBe('completed');
});

test('PUB-08: an archived story cannot be published', function () {
    $w = cin_story();
    cin_narrative($w['chapter'], 'sc_a');
    cin_publishing()->submitForReview($w['revision']->fresh());
    cin_authoring()->setArchived($w['story'], true);

    expect(cin_reason(fn () => cin_publishing()->publish($w['revision']->fresh())))->toBe('story_archived');
});
