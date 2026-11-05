<?php

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStage;
use App\Models\Puzzle;
use App\Services\Cinematic\CinematicStructure;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - التأليف: إنشاء المشاهد وترتيبها وصلاحية المراحل/الفصول، حدود البنية، ربط الأحجيات، حماية الحل، قفل النسخ، النسخ إلى مسودة، تبديل الوسائط.
*/

beforeEach(fn () => cin_seed());

test('AUT-01: a story starts with one draft revision; short/long is only an organisational label; slugs are unique and never collide with the route words', function () {
    $a = cin_story(['title' => 'المكتبة الغامضة', 'label_kind' => 'short']);
    $b = cin_story(['title' => 'المكتبة الغامضة']);

    expect($a['story']->label_kind)->toBe('short')->and($b['story']->label_kind)->toBe('long')
        ->and($a['revision']->status)->toBe('draft')->and($a['revision']->revision_number)->toBe(1)
        ->and($a['story']->slug)->not->toBe($b['story']->slug)
        ->and(strlen($a['story']->public_id))->toBe(26);

    expect(cin_reason(fn () => cin_authoring()->createStory(['title' => 'x', 'label_kind' => 'epic'])))->toBe('story_invalid');
    expect(cin_reason(fn () => cin_authoring()->createStory(['title' => '   '])))->toBe('title_required');

    foreach (['media', 'preview', 'cover'] as $word) {
        expect(cin_authoring()->createStory(['title' => 't', 'slug' => $word])->slug)->not->toBe($word);
    }
});

test('AUT-02: scenes keep an explicit order inside chapters, chapters inside stages and stages inside the story - and can be moved and removed without gaps', function () {
    $w = cin_story();
    $stage2 = cin_authoring()->addStage($w['revision'], ['title' => 'المرحلة الثانية', 'stable_key' => 'stage_2']);
    $chapter2 = cin_authoring()->addChapter($stage2, ['title' => 'فصل 2-1', 'stable_key' => 'chapter_2_1']);

    $s1 = cin_narrative($w['chapter'], 's1');
    $s2 = cin_narrative($w['chapter'], 's2');
    $s3 = cin_narrative($w['chapter'], 's3');
    $t1 = cin_narrative($chapter2, 't1');

    expect(CinematicStructure::scenes($w['revision'])->pluck('stable_key')->all())->toBe(['s1', 's2', 's3', 't1']);

    cin_authoring()->moveScene($s3, 'up');
    expect(CinematicStructure::scenes($w['revision'])->pluck('stable_key')->all())->toBe(['s1', 's3', 's2', 't1']);

    cin_authoring()->moveScene($s3, 'up');
    cin_authoring()->moveScene($s3, 'up'); // يتوقف عند الحافة
    expect(CinematicStructure::scenes($w['revision'])->pluck('stable_key')->all())->toBe(['s3', 's1', 's2', 't1']);

    cin_authoring()->moveStage($stage2, 'up');
    expect(CinematicStructure::scenes($w['revision'])->pluck('stable_key')->all())->toBe(['t1', 's3', 's1', 's2']);

    cin_authoring()->removeScene($s1);
    expect($w['chapter']->scenes()->pluck('position')->all())->toBe([1, 2])->and(CinematicStructure::scenes($w['revision'])->pluck('stable_key')->all())->toBe(['t1', 's3', 's2']);
});

test('AUT-03: seven stages are supported and the ceiling is configuration, not code', function () {
    $w = cin_story();

    foreach (range(2, 7) as $n) {
        cin_authoring()->addStage($w['revision'], ['title' => "المرحلة {$n}"]);
    }

    expect(CinematicStage::query()->where('revision_id', $w['revision']->id)->count())->toBe(7)
        ->and((int) config('cinematic_stories.limits.max_stages'))->toBeGreaterThanOrEqual(7);

    config(['cinematic_stories.limits.max_stages' => 7]);
    expect(cin_reason(fn () => cin_authoring()->addStage($w['revision'], ['title' => 'الثامنة'])))->toBe('limit_stages');

    config(['cinematic_stories.limits.max_stages' => 9, 'cinematic_stories.limits.max_scenes_per_chapter' => 1]);
    cin_authoring()->addStage($w['revision'], ['title' => 'الثامنة']);
    cin_narrative($w['chapter'], 'only_one');
    expect(cin_reason(fn () => cin_narrative($w['chapter'], 'too_many')))->toBe('limit_scenes');
});

test('AUT-04: all six scene types are accepted, unknown types and bad keys are refused, and a duplicate stable key is a clean conflict', function () {
    $w = cin_story();

    foreach (['video', 'narrative', 'puzzle', 'interaction', 'exploration', 'transition'] as $type) {
        expect(cin_authoring()->addScene($w['chapter'], ['type' => $type, 'title' => "مشهد {$type}"])->type)->toBe($type);
    }

    expect(cin_reason(fn () => cin_authoring()->addScene($w['chapter'], ['type' => 'hologram', 'title' => 'x'])))->toBe('scene_type');
    expect(cin_reason(fn () => cin_authoring()->addScene($w['chapter'], ['type' => 'narrative', 'title' => 'x', 'stable_key' => 'Bad Key!'])))->toBe('key_invalid');

    cin_narrative($w['chapter'], 'same_key');
    expect(cin_reason(fn () => cin_narrative($w['chapter'], 'same_key')))->toBe('key_taken');
});

test('AUT-05: the stage and chapter structure is checked for validity (a scene cannot be added to a chapter of another revision or a locked one)', function () {
    $w = cin_story();
    $other = cin_story();

    cin_narrative($w['chapter'], 'sc_a');
    $published = cin_publish($w['revision']);

    expect(cin_reason(fn () => cin_narrative($w['chapter'], 'late')))->toBe('revision_locked')
        ->and(cin_reason(fn () => cin_authoring()->addChapter($w['stage'], ['title' => 'متأخر'])))->toBe('revision_locked')
        ->and(cin_reason(fn () => cin_authoring()->addStage($published, ['title' => 'متأخرة'])))->toBe('revision_locked')
        ->and(cin_reason(fn () => cin_authoring()->removeScene($w['chapter']->scenes()->first())))->toBe('revision_locked');

    // مشهد بمفتاح في نسخة أخرى لا يتداخل: المفاتيح فريدة ضمن النسخة فقط.
    expect(cin_narrative($other['chapter'], 'sc_a')->stable_key)->toBe('sc_a');
});

test('AUT-06: puzzle scenes link only to active, official, classic puzzles - never community, inactive or interactive-type puzzles', function () {
    $w = cin_story();
    $scene = cin_authoring()->addScene($w['chapter'], ['type' => 'puzzle', 'title' => 'أحجية']);

    $good = Puzzle::factory()->create(['answer_raw' => 'مفتاح']);
    expect(cin_authoring()->updateScene($scene, ['puzzle_id' => $good->id])->puzzle_id)->toBe($good->id);

    $inactive = Puzzle::factory()->create(['is_active' => false]);
    $interactive = Puzzle::factory()->create(['game_type' => 'sequence', 'game_config' => ['sequence' => ['a', 'b']]]);

    foreach ([$inactive->id, $interactive->id, 999999] as $bad) {
        expect(cin_reason(fn () => cin_authoring()->updateScene($scene, ['puzzle_id' => $bad])))->toBe('puzzle_ineligible');
    }

    expect($scene->fresh()->puzzle_id)->toBe($good->id);
});

test('AUT-07: the solution is encrypted at rest, stored apart from the scene, and never part of its public config or serialisation', function () {
    $w = cin_story();
    [$public, $solution] = cin_choose('b');
    $scene = cin_interaction($w['chapter'], 'pick', $public, ['correct' => 'b']);

    $raw = (string) DB::table('cinematic_scene_solutions')->where('scene_id', $scene->id)->value('payload');

    expect($raw)->not->toContain('"correct"')->not->toContain('correct')
        ->and(CinematicSceneSolution::query()->where('scene_id', $scene->id)->first()->payload)->toBe(['correct' => 'b'])
        ->and(json_encode($scene->fresh()->toArray()))->not->toContain('"correct"')
        ->and(json_encode($scene->fresh()->config))->not->toContain('correct')
        ->and(json_encode(CinematicSceneSolution::query()->first()->toArray()))->not->toContain('payload');

    // محاولة تهريب الحل داخل الإعداد العام: القائمة البيضاء تُسقطه.
    $leaky = cin_authoring()->addScene($w['chapter'], ['type' => 'interaction', 'title' => 'x', 'config' => ['interaction' => $public + ['correct' => 'b', 'solution' => ['correct' => 'b']]]]);
    expect(json_encode($leaky->config))->not->toContain('correct')->not->toContain('solution');

    expect(cin_authoring()->setInteractionSolution($scene, ['correct' => 'zzz']))->not->toBe([])
        ->and(CinematicSceneSolution::query()->where('scene_id', $scene->id)->first()->payload)->toBe(['correct' => 'b']);
});

test('AUT-08: creating a draft from the live revision clones the whole structure with the same stable keys; only one open draft at a time; editing a review returns it to draft', function () {
    $w = cin_story();
    cin_narrative($w['chapter'], 'intro');
    [$public, $solution] = cin_choose('c');
    cin_interaction($w['chapter'], 'riddle', $public, $solution);
    $v1 = cin_publish($w['revision']);

    $draft = cin_authoring()->createDraft($w['story'], $w['admin']);

    expect($draft->revision_number)->toBe(2)->and($draft->status)->toBe('draft')
        ->and(CinematicStructure::scenes($draft)->pluck('stable_key')->all())->toBe(['intro', 'riddle'])
        ->and(CinematicStructure::scenes($draft)->pluck('id')->intersect(CinematicStructure::scenes($v1)->pluck('id'))->count())->toBe(0)
        ->and(CinematicSceneSolution::query()->whereIn('scene_id', CinematicStructure::scenes($draft)->pluck('id'))->count())->toBe(1)
        ->and(CinematicStructure::scenes($draft)->firstWhere('stable_key', 'riddle')->logic_hash)->toBe(CinematicStructure::scenes($v1)->firstWhere('stable_key', 'riddle')->logic_hash);

    expect(cin_reason(fn () => cin_authoring()->createDraft($w['story'])))->toBe('draft_exists');

    cin_publishing()->submitForReview($draft);
    expect($draft->fresh()->status)->toBe('in_review');
    cin_authoring()->updateRevision($draft->fresh(), ['notes' => 'تعديل أثناء المراجعة']);
    expect($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->readiness)->toBeNull();

    cin_authoring()->deleteDraft($draft->fresh());
    expect(CinematicRevision::query()->where('story_id', $w['story']->id)->count())->toBe(1);
    expect(cin_reason(fn () => cin_authoring()->deleteDraft($v1->fresh())))->toBe('revision_locked');
});

test('AUT-09: replacing a media file is allowed on a published revision without touching scene logic; wrong role, retired files and superseded revisions are refused', function () {
    $w = cin_story();
    $scene = cin_video_scene($w['chapter'], 'film');
    $hash = $scene->fresh()->logic_hash;
    $v1 = cin_publish($w['revision']);

    $poster = cin_poster_asset();
    cin_authoring()->replaceMedia($scene->fresh(), 'poster', $poster);
    expect($scene->fresh()->poster_asset_id)->toBe($poster->id)->and($scene->fresh()->logic_hash)->toBe($hash);

    expect(cin_reason(fn () => cin_authoring()->replaceMedia($scene->fresh(), 'video', $poster)))->toBe('media_not_ready');
    app(App\Services\Cinematic\Media\CinematicMediaService::class)->retire($poster);
    expect(cin_reason(fn () => cin_authoring()->replaceMedia($scene->fresh(), 'poster', $poster->fresh())))->toBe('media_not_ready');

    cin_authoring()->replaceMedia($scene->fresh(), 'poster', null);
    expect($scene->fresh()->poster_asset_id)->toBeNull();

    // نسخة جديدة منشورة تستبدل الأولى ⇒ الأولى مستبدلة ولا تقبل تبديلًا.
    $draft = cin_authoring()->createDraft($w['story']);
    cin_publish($draft);
    expect($v1->fresh()->status)->toBe('superseded')
        ->and(cin_reason(fn () => cin_authoring()->replaceMedia($scene->fresh(), 'poster', null)))->toBe('revision_locked');
});
