<?php

use App\Filament\Pages\CinematicBuilderPage;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\Media\CinematicMediaPicker;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| SS-01..SS-14 — محدّد الوسائط في منشئ السلاسل السينمائية: بحث على الخادم ضمن كل ملفات النوع قبل تحديد العدد،
| عزل الأنواع والصلاحيات، تمييز الأسماء المكررة، بقاء الاختيار الحالي، «الاختيار لا يحفظ» ثم «حفظ الوسائط» يربط الملف الصحيح بعد تحديث الصفحة.
| لا شبكة ولا متصفح هنا (انظر دليل التسليم: ما شُغّل فعليًا وما بقي فحصًا يدويًا).
*/

beforeEach(fn () => cin_seed());

function ss_world(): array
{
    $w = cin_story();
    $film = cin_authoring()->addScene($w['chapter'], ['type' => 'video', 'title' => 'فيديو المقارنة', 'stable_key' => 'film_cmp', 'awaiting_media' => true, 'config' => ['transcript' => [['speaker' => 'ياسمين', 'text' => 'حوار']]]]);
    $door = cin_interaction($w['chapter'], 'door_scene', ['kind' => 'hotspot', 'prompt' => 'اضغط', 'alt_text' => 'باب', 'areas' => [['id' => 'handle', 'label' => 'المقبض', 'x' => 60, 'y' => 45, 'w' => 10, 'h' => 10]]], ['correct' => ['handle']]);

    return $w + ['film' => $film->fresh(), 'door' => $door->fresh()];
}

function ss_builder(array $w, ?string $scene = null)
{
    return Livewire::withQueryParams(array_filter(['story' => $w['story']->public_id, 'scene' => $scene]))->test(CinematicBuilderPage::class);
}

test('SS-01: a media file older than the newest 200 is found by name - the search runs on the server over the whole role BEFORE the limit', function () {
    $w = ss_world();
    $target = ss_asset('poster', 'PLM_S02_I03_comparison_v2.png', ['w' => 1920, 'h' => 1080]);
    ss_filler('poster', 230);
    $this->actingAs($w['admin']);

    // إثبات الشرط: الملف خارج أحدث 200 (أي أن القائمة القديمة كانت ستُخفيه).
    expect(CinematicMediaAsset::query()->where('role', 'poster')->orderByDesc('id')->limit(200)->pluck('id')->all())->not->toContain($target->id);
    expect(CinematicMediaAsset::query()->where('role', 'poster')->count())->toBeGreaterThan(200);

    $c = ss_builder($w, $w['door']->public_id);
    $r = ss_call($c, 'searchMedia', 'I03', 0, '', ['kind' => 'poster']);

    expect(collect($r['items'])->pluck('id')->all())->toBe([$target->public_id])
        ->and($r['total'])->toBe(1)->and($r['next'])->toBeNull();
    expect(ss_call($c, 'searchMedia', 'comparison', 0, '', ['kind' => 'poster'])['items'][0]['name'])->toBe('PLM_S02_I03_comparison_v2.png');

    // بلا نص: عدد صغير من الأحدث فقط، ولا تُحمَّل المكتبة إلى المتصفح.
    $open = ss_call($c, 'searchMedia', '', 0, '', ['kind' => 'poster']);
    expect($open['items'])->toHaveCount(CinematicMediaPicker::PAGE_SIZE)->and($open['next'])->toBe(CinematicMediaPicker::PAGE_SIZE)->and($open['total'])->toBe(230 + 1)
        ->and(collect($open['items'])->pluck('id')->all())->not->toContain($target->public_id);
});

test('SS-02: the page itself no longer ships the library - only the current selection travels with the HTML', function () {
    $w = ss_world();
    $old = ss_asset('poster', 'OLD_ONLY_IN_SEARCH_marker.png');
    ss_filler('poster', 30);
    $this->actingAs($w['admin']);

    $html = ss_builder($w, $w['door']->public_id)->html();

    expect($html)->not->toContain('OLD_ONLY_IN_SEARCH_marker')->not->toContain('filler_0001')->not->toContain($old->public_id)
        ->toContain('data-ss-root')->toContain('searchMedia');
});

test('SS-03: partial names, Arabic names, English case-insensitivity, several words (AND) and literal _ and % are all matched on the server', function () {
    $w = ss_world();
    ss_asset('video', 'PLM_S01_V01_Arrival.mp4');
    ss_asset('video', 'plm_s01_v02_hallway.mp4');
    ss_asset('video', 'مقدمة_الفصل_الأول.mp4');
    ss_asset('video', 'ARRIVALxS01.mp4');
    ss_asset('video', '100%_final.mp4');
    $this->actingAs($w['admin']);
    $c = ss_builder($w, $w['film']->public_id);
    $names = fn (string $t) => collect(ss_call($c, 'searchMedia', $t, 0, '', ['kind' => 'video'])['items'])->pluck('name')->sort()->values()->all();

    expect($names('v01'))->toBe(['PLM_S01_V01_Arrival.mp4'])
        ->and($names('PLM_S01'))->toBe(['PLM_S01_V01_Arrival.mp4', 'plm_s01_v02_hallway.mp4'])
        ->and($names('arrival'))->toBe(['ARRIVALxS01.mp4', 'PLM_S01_V01_Arrival.mp4'])
        ->and($names('الفصل'))->toBe(['مقدمة_الفصل_الأول.mp4'])
        ->and($names('مقدمة الأول'))->toBe(['مقدمة_الفصل_الأول.mp4'])
        ->and($names('s01 hallway'))->toBe(['plm_s01_v02_hallway.mp4'])
        ->and($names('s01_v'))->toBe(['PLM_S01_V01_Arrival.mp4', 'plm_s01_v02_hallway.mp4'])   // الشرطة السفلية حرفية لا بدل
        ->and($names('s01v'))->toBe([])
        ->and($names('%'))->toBe(['100%_final.mp4'])                                            // % حرفية لا بدل
        ->and($names('zzz-no-such'))->toBe([]);
});

test('SS-04: type isolation - every kind searches only its own role (an image search never shows video, audio or subtitle files)', function () {
    $w = ss_world();
    foreach (['video' => 'v', 'poster' => 'p', 'subtitle' => 's', 'audio' => 'a'] as $role => $x) {
        ss_asset($role, 'shared_name_'.$role.'.dat');
    }
    $this->actingAs($w['admin']);
    $c = ss_builder($w, $w['door']->public_id);

    foreach (['video', 'poster', 'subtitle', 'audio'] as $kind) {
        $r = ss_call($c, 'searchMedia', 'shared_name', 0, '', ['kind' => $kind]);
        expect(collect($r['items'])->pluck('name')->all())->toBe(['shared_name_'.$kind.'.dat']);
    }

    // الغلاف ملصقات فقط.
    expect(collect(ss_call($c, 'searchMedia', 'shared_name', 0, '', ['kind' => 'cover'])['items'])->pluck('name')->all())->toBe(['shared_name_poster.dat']);

    // اسم مرسَل كاختيار حالي من نوع آخر لا يُثبَّت ولا يُكشف.
    $video = CinematicMediaAsset::query()->where('role', 'video')->first();
    $r = ss_call($c, 'searchMedia', '', 0, $video->public_id, ['kind' => 'poster']);
    expect(collect($r['items'])->pluck('id')->all())->not->toContain($video->public_id);
});

test('SS-05: permissions - view-only cannot search, media permission searches scene files but not the cover, manage searches the cover and puzzles but not scene media, an unknown kind is rejected', function () {
    $w = ss_world();
    ss_asset('poster', 'visible.png');
    $viewer = ss_user(['cinematic-stories.view']);
    $mediaOnly = ss_user(['cinematic-stories.view', 'cinematic-stories.media']);
    $manageOnly = ss_user(['cinematic-stories.view', 'cinematic-stories.manage']);

    $this->actingAs($viewer);
    ss_builder($w, $w['door']->public_id)->call('searchMedia', '', 0, '', ['kind' => 'poster'])->assertForbidden();
    ss_builder($w, $w['door']->public_id)->call('searchPuzzles', '', 0, '', [])->assertForbidden();

    $this->actingAs($mediaOnly);
    $c = ss_builder($w, $w['door']->public_id);
    expect(ss_call($c, 'searchMedia', 'visible', 0, '', ['kind' => 'poster'])['items'])->toHaveCount(1);
    // بعد ردّ 403 لا يبقى مكوّن الاختبار صالحًا للاستدعاء التالي (404)، فكل رفض بمكوّن جديد.
    ss_builder($w, $w['door']->public_id)->call('searchMedia', '', 0, '', ['kind' => 'cover'])->assertForbidden();
    ss_builder($w, $w['door']->public_id)->call('searchPuzzles', '', 0, '', [])->assertForbidden();

    $this->actingAs($manageOnly);
    $c = ss_builder($w, $w['door']->public_id);
    expect(ss_call($c, 'searchMedia', 'visible', 0, '', ['kind' => 'cover'])['items'])->toHaveCount(1);
    ss_builder($w, $w['door']->public_id)->call('searchMedia', '', 0, '', ['kind' => 'poster'])->assertForbidden();

    $this->actingAs($w['admin']);
    ss_builder($w, $w['door']->public_id)->call('searchMedia', '', 0, '', ['kind' => 'document'])->assertStatus(422);
    ss_builder($w, $w['door']->public_id)->call('searchMedia', '', 0, '', [])->assertStatus(422);
});

test('SS-06: the output never exposes storage paths, disks, numeric ids, uploader or the full content hash', function () {
    $w = ss_world();
    $a = ss_asset('poster', 'safe_output.png', ['w' => 640, 'h' => 360]);
    $this->actingAs($w['admin']);
    $r = ss_call(ss_builder($w, $w['door']->public_id), 'searchMedia', 'safe_output', 0, '', ['kind' => 'poster']);
    $json = json_encode($r, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('secret-path')->not->toContain('cinematic/poster')->not->toContain('"disk"')->not->toContain('uploaded_by')->not->toContain($a->sha256)
        ->and(array_keys($r['items'][0]))->toBe(['id', 'name', 'parts', 'dist', 'retired', 'current', 'note'])
        ->and($r['items'][0]['id'])->toBe($a->public_id)
        ->and($r['items'][0]['parts'])->toContain('640×360')->toContain('image/png');
});

test('SS-07: duplicate names are told apart (upload date + start of the content fingerprint); unique names carry no extra line', function () {
    $w = ss_world();
    $first = ss_asset('video', 'PLM_S02_V03.mp4', ['at' => '2026-10-01 09:15:00', 'duration' => 12000, 'w' => 1920, 'h' => 1080]);
    $second = ss_asset('video', 'PLM_S02_V03.mp4', ['at' => '2026-11-05 18:40:00', 'duration' => 15000, 'w' => 1280, 'h' => 720]);
    $unique = ss_asset('video', 'PLM_S02_V04.mp4');
    $this->actingAs($w['admin']);
    $items = collect(ss_call(ss_builder($w, $w['film']->public_id), 'searchMedia', 'PLM_S02', 0, '', ['kind' => 'video'])['items'])->keyBy('id');

    expect($items[$first->public_id]['dist'])->toBe(['رُفع '.\Illuminate\Support\Carbon::parse('2026-10-01 09:15:00')->timezone(config('app.timezone'))->format('Y-m-d H:i'), 'بصمة '.substr($first->sha256, 0, 8)])
        ->and($items[$second->public_id]['dist'][1])->toBe('بصمة '.substr($second->sha256, 0, 8))
        ->and($items[$first->public_id]['dist'])->not->toBe($items[$second->public_id]['dist'])
        ->and($items[$first->public_id]['parts'])->toContain('1920×1080')->toContain('12 ث')
        ->and($items[$second->public_id]['parts'])->toContain('1280×720')->toContain('15 ث')
        ->and($items[$unique->public_id]['dist'])->toBe([]);
});

test('SS-08: paging - "load more" continues exactly where the page stopped, without gaps or repeats, newest first', function () {
    $w = ss_world();
    ss_filler('audio', 27, 'track_');
    $this->actingAs($w['admin']);
    $c = ss_builder($w, $w['film']->public_id);
    $seen = [];
    $offset = 0;
    $pages = 0;

    do {
        $r = ss_call($c, 'searchMedia', 'track_', $offset, '', ['kind' => 'audio']);
        expect(count($r['items']))->toBeLessThanOrEqual(CinematicMediaPicker::PAGE_SIZE)->and($r['total'])->toBe(27);
        array_push($seen, ...collect($r['items'])->pluck('name')->all());
        $offset = $r['next'];
        $pages++;
    } while ($offset !== null && $pages < 10);

    expect($pages)->toBe(3)->and($seen)->toHaveCount(27)->and(array_unique($seen))->toHaveCount(27)->and($seen[0])->toBe('track_0027.png')->and($seen[26])->toBe('track_0001.png');
});

test('SS-09: a retired (withdrawn) file is never offered as a new choice, but stays visible as the current selection when it is the saved link', function () {
    $w = ss_world();
    $linked = ss_asset('poster', 'withdrawn_linked.png');
    $strayRetired = ss_asset('poster', 'withdrawn_stray.png', ['status' => 'retired']);
    $ready = ss_asset('poster', 'ready_choice.png');
    cin_authoring()->replaceMedia($w['door'], 'poster', $linked);
    DB::table('cinematic_media_assets')->where('id', $linked->id)->update(['status' => 'retired', 'retired_at' => now()]);
    $this->actingAs($w['admin']);

    $c = ss_builder($w, $w['door']->public_id);
    $all = ss_call($c, 'searchMedia', '', 0, $linked->public_id, ['kind' => 'poster']);
    $ids = collect($all['items'])->pluck('id')->all();

    expect($ids[0])->toBe($linked->public_id)->and($all['items'][0]['retired'])->toBeTrue()->and($all['items'][0]['current'])->toBeTrue()
        ->and($ids)->toContain($ready->public_id)->not->toContain($strayRetired->public_id);

    // مسحوب غير مربوط لا يُثبَّت حتى لو أُرسل كاختيار حالي (لا كشف عبر معرّف مُرسَل).
    $r = ss_call($c, 'searchMedia', '', 0, $strayRetired->public_id, ['kind' => 'poster']);
    expect(collect($r['items'])->pluck('id')->all())->not->toContain($strayRetired->public_id);

    // الصفحة تُظهر حالة المسحوب ولا تفشل عند «حفظ الوسائط» بلا تغيير.
    $html = $c->html();
    expect($html)->toContain('الملف المربوط مسحوب من المكتبة')->toContain('withdrawn_linked.png');
    $c->call('saveMedia');
    expect($w['door']->fresh()->poster_asset_id)->toBe($linked->id);
});

test('SS-10: the current selection is pinned first when it matches the text (even if far older than the newest page) and stays selected after searching and reopening', function () {
    $w = ss_world();
    $old = ss_asset('poster', 'PLM_S02_I03_old_selection.png');
    ss_filler('poster', 60);
    cin_authoring()->replaceMedia($w['door'], 'poster', $old);
    $this->actingAs($w['admin']);
    $c = ss_builder($w, $w['door']->public_id);

    $open = ss_call($c, 'searchMedia', '', 0, $old->public_id, ['kind' => 'poster']);
    expect($open['items'][0]['id'])->toBe($old->public_id)->and($open['items'][0]['current'])->toBeTrue()
        ->and($open['items'])->toHaveCount(CinematicMediaPicker::PAGE_SIZE + 1);

    // نص يطابقه: يبقى مثبّتًا. نص لا يطابقه: لا يظهر في النتائج، لكن القيمة المختارة في الصفحة لا تتغيّر.
    expect(ss_call($c, 'searchMedia', 'I03', 0, $old->public_id, ['kind' => 'poster'])['items'][0]['id'])->toBe($old->public_id);
    expect(ss_call($c, 'searchMedia', 'filler_0007', 0, $old->public_id, ['kind' => 'poster'])['items'])->toHaveCount(1)->and(ss_call($c, 'searchMedia', 'filler_0007', 0, $old->public_id, ['kind' => 'poster'])['items'][0]['name'])->toBe('filler_0007.png');
    $c->assertSet('sf.media.poster', $old->public_id);

    // «إعادة الفتح» = طلب جديد بلا نص: الاختيار الحالي ما زال أول عنصر.
    expect(ss_call($c, 'searchMedia', '', 0, $old->public_id, ['kind' => 'poster'])['items'][0]['id'])->toBe($old->public_id);
    // ولا يتكرر في الصفحة التالية.
    $next = ss_call($c, 'searchMedia', '', CinematicMediaPicker::PAGE_SIZE, $old->public_id, ['kind' => 'poster']);
    expect(collect($next['items'])->pluck('id')->all())->not->toContain($old->public_id);
});

test('SS-11: merely choosing a file does not change the saved link and shows "unsaved selection"; «حفظ الوسائط» links exactly that file and survives a page refresh', function () {
    $w = ss_world();
    $video = ss_asset('video', 'PLM_S02_V01_choice.mp4', ['duration' => 9000]);
    $other = ss_asset('video', 'PLM_S02_V02_other.mp4');
    $this->actingAs($w['admin']);

    $c = ss_builder($w, $w['film']->public_id);
    $c->set('sf.media.video', $video->public_id);
    expect($w['film']->fresh()->video_asset_id)->toBeNull();
    $c->assertSee('اختيار غير محفوظ')->assertSee('data-media-state="pending"', false)->assertSee('PLM_S02_V01_choice.mp4');

    // تحديث الصفحة قبل الحفظ: لا ربط محفوظ.
    $fresh = ss_builder($w, $w['film']->public_id);
    $fresh->assertSet('sf.media.video', '')->assertDontSee('اختيار غير محفوظ');
    expect($w['film']->fresh()->video_asset_id)->toBeNull();

    $c->call('saveMedia');
    expect($w['film']->fresh()->video_asset_id)->toBe($video->id)->and($w['film']->fresh()->video_asset_id)->not->toBe($other->id);

    // بعد التحديث: الملف الصحيح ظاهر كاختيار حالي ومربوط.
    $after = ss_builder($w, $w['film']->public_id);
    $after->assertSet('sf.media.video', $video->public_id)->assertSee('PLM_S02_V01_choice.mp4')->assertSee('data-media-state="saved"', false)->assertDontSee('اختيار غير محفوظ');
});

test('SS-12: «بلا» (none) still works and removing the media from the scene is a saved change only after «حفظ الوسائط»', function () {
    $w = ss_world();
    $poster = ss_asset('poster', 'door_bg.png', ['w' => 800, 'h' => 450]);
    cin_authoring()->replaceMedia($w['door'], 'poster', $poster);
    $this->actingAs($w['admin']);

    $c = ss_builder($w, $w['door']->public_id);
    $c->set('sf.media.poster', '');
    expect($w['door']->fresh()->poster_asset_id)->toBe($poster->id);
    $c->assertSee('اختيار غير محفوظ');
    $c->call('saveMedia');
    expect($w['door']->fresh()->poster_asset_id)->toBeNull();
});

test('SS-13: a file of another type cannot be linked even if the field is tampered with, and the tampered value is neither previewed nor described', function () {
    $w = ss_world();
    $video = ss_asset('video', 'tamper_target_video.mp4');
    $this->actingAs($w['admin']);

    $c = ss_builder($w, $w['door']->public_id);
    $c->set('sf.media.poster', $video->public_id);
    $c->assertDontSee('tamper_target_video.mp4');
    $c->call('saveMedia');
    expect($w['door']->fresh()->poster_asset_id)->toBeNull();

    // وسيط مسحوب غير مربوط لا يُربط ولا يُوصَف.
    $retired = ss_asset('poster', 'tamper_retired.png', ['status' => 'retired']);
    $c = ss_builder($w, $w['door']->public_id);
    $c->set('sf.media.poster', $retired->public_id);
    $c->assertDontSee('tamper_retired.png')->call('saveMedia');
    expect($w['door']->fresh()->poster_asset_id)->toBeNull();
});

test('SS-14: the story cover - searched among posters only, unsaved until «حفظ القصة», and a withdrawn cover no longer blocks saving other story settings', function () {
    $w = ss_world();
    $cover = ss_asset('poster', 'cover_option_blue.png');
    ss_filler('poster', 220);
    $this->actingAs($w['admin']);
    $c = ss_builder($w);

    expect(ss_call($c, 'searchMedia', 'cover_option', 0, '', ['kind' => 'cover'])['items'][0]['id'])->toBe($cover->public_id);

    $c->set('cover', $cover->public_id);
    expect($w['story']->fresh()->cover_asset_id)->toBeNull();
    $c->call('saveStory');
    expect($w['story']->fresh()->cover_asset_id)->toBe($cover->id);

    // سُحب الغلاف المربوط: يبقى ظاهرًا، وحفظ عنوان القصة لا يفشل (الغلاف لا يُبدَّل إن لم يتغيّر اختياره).
    DB::table('cinematic_media_assets')->where('id', $cover->id)->update(['status' => 'retired', 'retired_at' => now()]);
    $c = ss_builder($w);
    $c->assertSee('cover_option_blue.png');
    $c->set('storyForm.title', 'عنوان جديد للقصة')->call('saveStory');
    expect($w['story']->fresh()->title)->toBe('عنوان جديد للقصة')->and($w['story']->fresh()->cover_asset_id)->toBe($cover->id);
});

test('SS-38: the file card splits properties into data segments - every number/type token is flagged left-to-right, and the Blade prints them escaped (no raw HTML)', function () {
    $segments = App\Services\Cinematic\Media\CinematicMediaPicker::segments('2.3 م.ب · 42 ث · 1920×1080 · video/mp4');
    $ltr = array_column(array_filter($segments, fn ($s) => $s['ltr']), 't');

    expect($ltr)->toBe(['2.3', '42', '1920×1080', 'video/mp4'])
        // إعادة التركيب لا تفقد أي حرف.
        ->and(implode('', array_column($segments, 't')))->toBe('2.3 م.ب · 42 ث · 1920×1080 · video/mp4')
        ->and(array_column(App\Services\Cinematic\Media\CinematicMediaPicker::segments('<script>alert(1)</script> 12'), 't'))->toContain('<', 'script', '12');

    // لا {!! !!} في المصدر (حارس W3)، والقالب يمرّ بالمقاطع عبر {{ }} المهرَّبة.
    $blade = (string) file_get_contents(resource_path('views/filament/pages/cinematic-builder.blade.php'));
    expect($blade)->not->toContain('{!!')->toContain('CinematicMediaPicker::segments(')->toContain('<bdi dir="ltr">{{ $seg[\'t\'] }}</bdi>');
});
