<?php

use App\Filament\Pages\CinematicBuilderPage;
use App\Filament\Pages\CinematicMediaLibraryPage;
use App\Filament\Pages\CinematicStoriesPage;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\Admin\SceneFormCodec;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - لوحة الإدارة (Filament بالعربية): إخفاؤها حين يُغلق العلم، الصلاحيات الخمس، سير العمل الكامل من إنشاء قصة إلى نشرها عبر الصفحات نفسها،
| محرّر التفاعل بحقوله النصية البسيطة، عدم ظهور الحل لغير أصحاب manage، وسحب/استعادة الوسائط.
*/

beforeEach(fn () => cin_seed());

function cin_staff(array $permissions): App\Models\User
{
    $user = e16User();
    $user->givePermissionTo(array_merge(['admin.access'], $permissions));

    return $user;
}

test('FIL-01: with the flag closed the three admin pages are inaccessible and absent from navigation; with it open an administrator reaches them', function () {
    $admin = cin_admin();
    config(['cinematic_stories.enabled' => false]);

    expect(CinematicStoriesPage::canAccess())->toBeFalse();
    $this->actingAs($admin);
    expect(CinematicStoriesPage::canAccess())->toBeFalse()->and(CinematicMediaLibraryPage::canAccess())->toBeFalse()->and(CinematicStoriesPage::shouldRegisterNavigation())->toBeFalse();
    $this->get(CinematicStoriesPage::getUrl())->assertForbidden();
    $this->get(CinematicMediaLibraryPage::getUrl())->assertForbidden();

    config(['cinematic_stories.enabled' => true]);
    expect(CinematicStoriesPage::canAccess())->toBeTrue()->and(CinematicStoriesPage::shouldRegisterNavigation())->toBeTrue();
    $this->get(CinematicStoriesPage::getUrl())->assertOk()->assertSee('السلاسل السينمائية');
    $this->get(CinematicMediaLibraryPage::getUrl())->assertOk()->assertSee('وسائط السلاسل السينمائية');
});

test('FIL-02: the five permissions are separate - a viewer reads but cannot change anything, and a user with none is turned away from every page and action', function () {
    $w = cin_story();
    cin_narrative($w['chapter'], 'sc_one');

    $nobody = e16User();
    $this->actingAs($nobody)->get(CinematicStoriesPage::getUrl())->assertForbidden();
    $this->actingAs($nobody)->get(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id]))->assertForbidden();

    $viewer = cin_staff(['cinematic-stories.view']);
    $this->actingAs($viewer);
    $page = $this->get(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id]))->assertOk();
    expect($page->getContent())->not->toContain('wire:click="addStage"')->not->toContain('data-builder-story')->not->toContain('wire:click="publish"')->not->toContain('wire:click="submitReview"')->not->toContain('wire:click="saveScene"');

    Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class)
        ->set('newStage', 'محظورة')->call('addStage')->assertForbidden();
    Livewire::test(CinematicStoriesPage::class)->call('create')->assertForbidden();
    Livewire::test(CinematicMediaLibraryPage::class)->call('retire', 'x')->assertForbidden();
    expect($w['revision']->fresh()->stages()->count())->toBe(1);

    // manage ≠ publish ≠ media ≠ preview
    $author = cin_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $this->actingAs($author);
    Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class)->call('submitReview')->assertForbidden();
    Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class)->set('newStage', 'مسموحة')->call('addStage')->assertHasNoErrors();
    expect($w['revision']->fresh()->stages()->count())->toBe(2);

    $this->actingAs($viewer)->get('/cinematic/preview/'.$w['revision']->public_id)->assertForbidden();
});

test('FIL-03: an author builds a story end to end through the pages - create, stage, chapter, scenes, an interaction with its solution - and sees what blocks publishing', function () {
    $admin = cin_admin();
    $this->actingAs($admin);

    Livewire::test(CinematicStoriesPage::class)
        ->set('form.title', 'الصفحة التي لم تُكتب')->set('form.label_kind', 'long')->set('form.access_mode', 'free')->call('create');

    $story = CinematicStory::query()->where('title', 'الصفحة التي لم تُكتب')->firstOrFail();
    $builder = Livewire::withQueryParams(['story' => $story->public_id])->test(CinematicBuilderPage::class);

    $builder->set('newStage', 'المرحلة الأولى')->call('addStage');
    $stage = $story->revisions()->first()->stages()->first();
    $builder->set("newChapter.{$stage->public_id}", 'المكتبة الغامضة')->call('addChapter', $stage->public_id);
    $chapter = $stage->chapters()->first();

    $builder->set("newScene.{$chapter->public_id}", ['type' => 'video', 'title' => 'الوصول', 'key' => 'arrival'])->call('addScene', $chapter->public_id);
    $builder->set("newScene.{$chapter->public_id}", ['type' => 'interaction', 'title' => 'الألغاز', 'key' => 'riddles'])->call('addScene', $chapter->public_id);

    $riddles = $chapter->scenes()->where('stable_key', 'riddles')->firstOrFail();
    $builder->call('editScene', $riddles->public_id)
        ->set('sf.kind', 'sequence')->set('sf.ix.prompt', 'رتّب الأشكال')
        ->set('sf.ix.items', "circle|دائرة|●\ntriangle|مثلث|▲\nsquare|مربع|■")->set('sf.ix.order', 'circle,triangle,square')
        ->call('saveScene');

    $saved = $riddles->fresh();
    expect($saved->config['interaction']['kind'])->toBe('sequence')->and($saved->config['interaction']['items'])->toHaveCount(3)
        ->and(CinematicSceneSolution::query()->where('scene_id', $riddles->id)->first()->payload)->toBe(['order' => ['circle', 'triangle', 'square']])
        ->and(json_encode($saved->config))->not->toContain('"order"');

    // الفيديو ينتظر الإنتاج ⇒ يمنع النشر، ويظهر ذلك في المنشئ.
    $builder->call('submitReview')->assertHasNoErrors();
    expect($story->revisions()->first()->fresh()->status)->toBe('in_review');
    $builder->call('publish');
    expect($story->fresh()->liveRevision)->toBeNull();
    $builder->assertSee('ينتظر إنتاج الفيديو');

    // ربط الفيديو من المكتبة ثم النشر.
    $arrival = $chapter->scenes()->where('stable_key', 'arrival')->firstOrFail();
    $video = cin_video_asset($admin);
    $builder->call('requestChanges')->call('editScene', $arrival->public_id)->set('sf.media.video', $video->public_id)->call('saveMedia');
    expect($arrival->fresh()->video_asset_id)->toBe($video->id);
    $builder->call('submitReview')->call('publish');

    expect($story->fresh()->liveRevision)->not->toBeNull()->and($story->fresh()->liveRevision->status)->toBe('published');
});

test('FIL-04: the solution never reaches a viewer-only user, and an author sees it only in the editor fields', function () {
    $w = cin_story();
    $scene = cin_interaction($w['chapter'], 'secret_seq', ['kind' => 'sequence', 'prompt' => 'رتّب', 'items' => [['id' => 'sqa', 'label' => 'أ'], ['id' => 'sqb', 'label' => 'ب']]], ['order' => ['sqb', 'sqa']]);

    $viewer = cin_staff(['cinematic-stories.view']);
    $this->actingAs($viewer);
    $component = Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class)->call('editScene', $scene->public_id);
    expect($component->get('sf.ix.order'))->toBe('')->and(json_encode($component->snapshot ?? []))->not->toContain('sqb,sqa');
    $component->assertDontSee('sqb,sqa');

    $author = cin_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $this->actingAs($author);
    $component = Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class)->call('editScene', $scene->public_id);
    expect($component->get('sf.ix.order'))->toBe('sqb,sqa')->and($component->get('sf.kind'))->toBe('sequence');
});

test('FIL-05: the codec round-trips every interaction kind (form text → structures → form text) and the engine accepts what it builds', function () {
    $engine = new App\Services\Cinematic\Interactions\InteractionEngine;

    foreach (cin_defs() as $kind => [$public, $solution]) {
        $form = SceneFormCodec::encodeInteraction($public, $solution);
        [$p, $s] = SceneFormCodec::decodeInteraction($kind, $form);

        expect($engine->validate($kind, $p, $s))->toBe([], "kind {$kind}")
            ->and(SceneFormCodec::encodeInteraction($p, $s))->toBe($form, "round trip {$kind}");
    }

    expect(SceneFormCodec::textToLines("ياسمين|مرحبًا\nنص بلا متحدث\n\n"))->toBe([['speaker' => 'ياسمين', 'text' => 'مرحبًا'], ['speaker' => '', 'text' => 'نص بلا متحدث']])
        ->and(SceneFormCodec::linesToText(SceneFormCodec::textToLines("أ|ب\nج")))->toBe("أ|ب\nج")
        ->and(SceneFormCodec::csv('a, b،c  d,,a'))->toBe(['a', 'b', 'c', 'd']);
});

test('FIL-06: a published revision is locked in the builder, but the media permission can still swap a file without touching the scene logic; a new draft is cloned from it', function () {
    $admin = cin_admin();
    $w = cin_story(['admin' => $admin]);
    $film = cin_video_scene($w['chapter'], 'film');
    $hash = $film->fresh()->logic_hash;
    $live = cin_publish($w['revision']);
    $this->actingAs($admin);

    $builder = Livewire::withQueryParams(['story' => $w['story']->public_id])->test(CinematicBuilderPage::class);
    $builder->set('newStage', 'متأخرة')->call('addStage');
    expect($live->fresh()->stages()->count())->toBe(1);

    $newVideo = cin_long_video_asset(4000);
    $builder->call('editScene', $film->public_id)->set('sf.media.video', $newVideo->public_id)->call('saveMedia');
    expect($film->fresh()->video_asset_id)->toBe($newVideo->id)->and($film->fresh()->logic_hash)->toBe($hash)->and($live->fresh()->status)->toBe('published');

    $builder->call('newDraft');
    expect(CinematicRevision::query()->where('story_id', $w['story']->id)->count())->toBe(2);
    $builder->set('newStage', 'مسموحة الآن')->call('addStage');
    expect(CinematicRevision::query()->where('story_id', $w['story']->id)->where('status', 'draft')->first()->stages()->count())->toBe(2);
});

test('FIL-07: the media library lists real file properties, retires and restores without deleting, and its upload endpoint is the guarded server route', function () {
    $admin = cin_admin();
    $asset = cin_video_asset($admin);
    $this->actingAs($admin);

    $page = $this->get(CinematicMediaLibraryPage::getUrl(['role' => 'video']))->assertOk();
    expect($page->getContent())->toContain($asset->original_name)->toContain('avc1')->toContain(route('cinematic.admin.media.store'));

    Livewire::withQueryParams(['role' => 'video'])->test(CinematicMediaLibraryPage::class)->call('retire', $asset->public_id);
    expect($asset->fresh()->status)->toBe('retired');
    Livewire::test(CinematicMediaLibraryPage::class)->call('restore', $asset->public_id);
    expect($asset->fresh()->status)->toBe('ready');
});

test('FIL-08: a viewer sees the media library but no upload box and no retire buttons', function () {
    $asset = cin_video_asset(cin_admin());
    $viewer = cin_staff(['cinematic-stories.view']);

    $page = $this->actingAs($viewer)->get(CinematicMediaLibraryPage::getUrl())->assertOk();
    expect($page->getContent())->toContain($asset->original_name)->not->toContain('data-media-upload')->not->toContain('wire:click="retire');
});
