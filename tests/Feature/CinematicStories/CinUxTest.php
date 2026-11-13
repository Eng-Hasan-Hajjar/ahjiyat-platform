<?php

use App\Filament\Pages\CinematicBuilderPage;
use App\Filament\Pages\CinematicMediaLibraryPage;
use App\Filament\Pages\CinematicStoriesPage;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicSceneState;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../CreatorPremiumE43/_support.php';

/*
| CIN-1 UX & INTEGRATION - اختبارات جديدة (لا تعدّل أي اختبار قديم ولا توقّفه):
|  UX-01..04  محرّر المشهد: فتح المشهد الصحيح (و?scene=)، حفظ البيانات، عدم اختلاط بيانات المشاهد، سلامة العلاقات بعد الحفظ.
|  UX-05..06  صلاحيات الوسائط، ورسم القوائم المنسدلة (وجود الخيارات بنصوصها + عقد الألوان المصمتة + نسب التباين AA محسوبة من الأصل).
|  UX-07..12  رفع فيديو صالح/مرفوض، ربط فيديو بلا نسخ، رفض نوع وسيط خاطئ، معاينة فيديو مربوط، التعامل مع فيديو ناقص، سياق المكتبة.
|  UX-13..16  معاينة مسودة بلا تقدّم محفوظ، إتمام تفاعل الباب (الخادم)، إتمام المصباح، عدم تسريب الحل.
|  UX-17..19  حماية الروابط الموقَّعة، بقاء ملكية المحتوى المميز، العلم المغلق.
|  UX-20..24  عقود القوالب: مشغّل الصورة (إطار بنسبة الصورة، نقر مع هامش لمس، بديل لوحة المفاتيح)، أداة المعايرة، بلا إحداثيات مخمَّنة في الكود.
| لا شبكة ولا FFmpeg ولا متصفح هنا. فحص العرض البصري الفعلي (تطابق منطقة الباب مع المقبض) جرى في متصفح حقيقي على بيئة الإعداد ويُذكر في التقارير ولا يُحسب من هذه الاختبارات.
*/

beforeEach(fn () => cin_seed());

if (! function_exists('ux_staff')) {
    function ux_staff(array $permissions): App\Models\User
    {
        $user = e16User();
        $user->givePermissionTo(array_merge(['admin.access'], $permissions));

        return $user;
    }

    function ux_cfg(string $html): array
    {
        preg_match('/data-config="([^"]*)"/', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true) ?: [];
    }

    /**
     * مسودة بمشاهد: سرد A/B، فيديو بلا ملف، باب (hotspot بصورة)، مصباح (flashlight). الإحداثيات هنا لبيانات الاختبار وحدها.
     *
     * @return array<string, mixed>
     */
    function ux_world(): array
    {
        $w = cin_story();
        $a = cin_narrative($w['chapter'], 'scene_a', ['title' => 'مشهد ألف']);
        $b = cin_narrative($w['chapter'], 'scene_b', ['title' => 'مشهد باء', 'config' => ['lines' => [['speaker' => 'ياسمين', 'text' => 'نص باء']]]]);
        $film = cin_authoring()->addScene($w['chapter'], ['type' => 'video', 'title' => 'فيديو ناقص', 'stable_key' => 'film_wait', 'awaiting_media' => true, 'config' => ['transcript' => [['speaker' => 'ياسمين', 'text' => 'حوار']]]]);
        $door = cin_interaction($w['chapter'], 'door_scene', ['kind' => 'hotspot', 'prompt' => 'اضغط على المقبض', 'alt_text' => 'باب', 'areas' => [['id' => 'handle', 'label' => 'المقبض', 'x' => 60, 'y' => 45, 'w' => 10, 'h' => 10]]], ['correct' => ['handle']]);
        $door = cin_authoring()->replaceMedia($door->fresh(), 'poster', cin_poster_asset());
        $lamp = cin_interaction($w['chapter'], 'lamp_scene', ['kind' => 'flashlight', 'prompt' => 'ابحث', 'light_radius' => 14], ['targets' => [['id' => 'mark_first', 'x' => 38, 'y' => 42, 'w' => 14, 'h' => 14]], 'required' => 1]);

        return $w + ['a' => $a->fresh(), 'b' => $b->fresh(), 'film' => $film->fresh(), 'door' => $door->fresh(), 'lamp' => $lamp->fresh()];
    }

    /** ينشر العالم التجريبي: يربط فيديوًا حقيقيًا بمشهد الفيديو الناقص أولًا (النشر يرفض فيديو ناقصًا، وهذا مقصود). */
    function ux_publish(array $w): App\Models\Cinematic\CinematicRevision
    {
        cin_authoring()->replaceMedia($w['film']->fresh(), 'video', cin_video_asset($w['admin']));

        return cin_publish($w['revision']->fresh(), $w['admin']);
    }

    /** قصة منشورة للّعب الفعلي: الباب أولًا ثم المصباح ثم سرد ختامي (لا يُفتح مشهد قبل إتمام ما قبله). */
    function ux_play_world(): array
    {
        $w = cin_story();
        $door = cin_interaction($w['chapter'], 'door_scene', ['kind' => 'hotspot', 'prompt' => 'اضغط على المقبض', 'areas' => [['id' => 'handle', 'label' => 'المقبض', 'x' => 60, 'y' => 45, 'w' => 10, 'h' => 10]]], ['correct' => ['handle']]);
        cin_authoring()->replaceMedia($door->fresh(), 'poster', cin_poster_asset($w['admin']));
        $lamp = cin_interaction($w['chapter'], 'lamp_scene', ['kind' => 'flashlight', 'prompt' => 'ابحث', 'light_radius' => 14], ['targets' => [['id' => 'mark_first', 'x' => 38, 'y' => 42, 'w' => 14, 'h' => 14]], 'required' => 1]);
        cin_narrative($w['chapter'], 'the_end');
        $live = cin_publish($w['revision']->fresh(), $w['admin']);

        return $w + ['live' => $live, 'door' => $live->scenes()->where('stable_key', 'door_scene')->first(), 'lamp' => $live->scenes()->where('stable_key', 'lamp_scene')->first()];
    }

    function ux_builder(array $w, array $extra = [])
    {
        return Livewire::withQueryParams(['story' => $w['story']->public_id] + $extra)->test(CinematicBuilderPage::class);
    }

    /** نسبة التباين WCAG بين لونين سداسيين. */
    function ux_contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $hex = ltrim($hex, '#');
            [$r, $g, $bl] = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [0, 2, 4]);
            $f = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($bl);
        };
        [$x, $y] = [$lum($a), $lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** رموز الألوان من كتلة سمة في ملف الأنماط. @return array<string, string> */
    function ux_tokens(string $css, string $selector): array
    {
        $start = strpos($css, "\n".$selector.' {');
        $end = strpos($css, "\n}", (int) $start);
        $block = substr($css, (int) $start, (int) $end - (int) $start);
        preg_match_all('/--cin-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/', $block, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($row) => [$row[1] => $row[2]])->all();
    }
}

// ───────────────────────────── محرّر المشهد ─────────────────────────────

test('UX-01: «تحرير» opens exactly the chosen scene (also through the ?scene= deep link); a scene of another story or a made-up id opens nothing', function () {
    $w = ux_world();
    $this->actingAs($w['admin']);

    $c = ux_builder($w)->call('editScene', $w['b']->public_id);
    expect($c->get('editingScene'))->toBe($w['b']->public_id)->and($c->get('sf.title'))->toBe('مشهد باء')->and($c->get('sf.key'))->toBe('scene_b')
        ->and($c->get('sf.lines'))->toContain('نص باء');
    $c->assertSee('data-scene-editor', false)->assertSee('مشهد باء');

    $deep = ux_builder($w, ['scene' => $w['door']->public_id]);
    expect($deep->get('editingScene'))->toBe($w['door']->public_id)->and($deep->get('sf.type'))->toBe('interaction')->and($deep->get('sf.key'))->toBe('door_scene');

    $other = cin_story();
    $foreign = cin_narrative($other['chapter'], 'foreign_scene');
    expect(ux_builder($w, ['scene' => $foreign->public_id])->get('editingScene'))->toBeNull()
        ->and(ux_builder($w, ['scene' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])->get('editingScene'))->toBeNull();
});

test('UX-02: editing a scene saves its data and the editor then shows the stored (normalised) values', function () {
    $w = ux_world();
    $this->actingAs($w['admin']);

    $c = ux_builder($w)->call('editScene', $w['a']->public_id)->set('sf.title', 'عنوان جديد')->set('sf.lines', "ياسمين|السطر الأول\nسطر بلا متحدث")->call('saveScene');
    $fresh = $w['a']->fresh();
    expect($fresh->title)->toBe('عنوان جديد')->and($fresh->config['lines'])->toBe([['speaker' => 'ياسمين', 'text' => 'السطر الأول'], ['speaker' => '', 'text' => 'سطر بلا متحدث']])
        ->and($c->get('sf.title'))->toBe('عنوان جديد');
});

test('UX-03: switching scenes never mixes their data (no stale Livewire state), and an unsaved edit of one scene never lands on another', function () {
    $w = ux_world();
    $this->actingAs($w['admin']);

    $c = ux_builder($w)->call('editScene', $w['a']->public_id)->set('sf.title', 'تعديل غير محفوظ لألف');
    $c->call('editScene', $w['door']->public_id);
    expect($c->get('editingScene'))->toBe($w['door']->public_id)->and($c->get('sf.title'))->toBe($w['door']->title)->and($c->get('sf.kind'))->toBe('hotspot')
        ->and($c->get('sf.ix.areas'))->toContain('handle|المقبض|60|45|10|10')->and($c->get('sf.media.poster'))->not->toBe('');

    $c->call('editScene', $w['b']->public_id);
    expect($c->get('sf.title'))->toBe('مشهد باء')->and($c->get('sf.media.poster'))->toBe('')->and($c->get('sf.ix.areas') ?? '')->toBe('');

    $c->set('sf.title', 'باء بعد التعديل')->call('saveScene');
    expect($w['b']->fresh()->title)->toBe('باء بعد التعديل')->and($w['a']->fresh()->title)->toBe('مشهد ألف')->and($w['door']->fresh()->title)->toBe($w['door']->title);

    $c->call('closeScene');
    expect($c->get('editingScene'))->toBeNull()->and($c->get('sf'))->toBe([]);
});

test('UX-04: saving a scene keeps every relation intact - chapter/stage, linked media, the hidden solution, the unlock rule and the logic hash of what was not touched', function () {
    $w = ux_world();
    $this->actingAs($w['admin']);
    $video = cin_video_asset($w['admin']);
    cin_authoring()->replaceMedia($w['film']->fresh(), 'video', $video);
    $before = $w['door']->fresh();
    $solution = CinematicSceneSolution::query()->where('scene_id', $before->id)->first()->payload;

    $c = ux_builder($w)->call('editScene', $w['door']->public_id)->set('sf.title', 'باب بعد التعديل')->call('saveScene');
    $after = $w['door']->fresh();
    expect($after->chapter_id)->toBe($before->chapter_id)->and($after->stage_id)->toBe($before->stage_id)->and($after->revision_id)->toBe($before->revision_id)
        ->and($after->stable_key)->toBe('door_scene')->and($after->poster_asset_id)->toBe($before->poster_asset_id)
        ->and(CinematicSceneSolution::query()->where('scene_id', $after->id)->first()->payload)->toBe($solution)
        ->and($after->config['interaction']['areas'])->toBe($before->config['interaction']['areas']);

    $c->call('editScene', $w['film']->public_id)->set('sf.title', 'فيديو معدّل')->call('saveScene');
    $film = $w['film']->fresh();
    expect($film->video_asset_id)->toBe($video->id)->and($film->awaiting_media)->toBeFalse()->and($film->title)->toBe('فيديو معدّل');
});

// ───────────────────────────── الوسائط والصلاحيات ─────────────────────────────

test('UX-05: media permissions - view-only cannot link or upload, media permission can, preview permission can only look; guests and strangers get nothing', function () {
    $w = ux_world();
    $asset = cin_video_asset($w['admin']);
    $viewer = ux_staff(['cinematic-stories.view']);
    $mediaOnly = ux_staff(['cinematic-stories.view', 'cinematic-stories.media']);
    $previewer = ux_staff(['cinematic-stories.view', 'cinematic-stories.preview']);
    $png = fn () => new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true);

    $this->actingAs($viewer);
    ux_builder($w)->call('editScene', $w['film']->public_id)->set('sf.media.video', $asset->public_id)->call('saveMedia')->assertForbidden();
    $this->actingAs($viewer)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertForbidden();
    $this->actingAs($previewer)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertForbidden();
    expect($w['film']->fresh()->video_asset_id)->toBeNull();

    $unique = new UploadedFile(cin_tmp(cin_synthetic_mp4(['duration' => 3000]), 'mp4'), 'unique.mp4', 'video/mp4', null, true);
    $this->actingAs($mediaOnly)->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => $unique])->assertCreated();
    $this->actingAs($mediaOnly);
    ux_builder($w)->call('editScene', $w['film']->public_id)->set('sf.media.video', $asset->public_id)->call('saveMedia');
    expect($w['film']->fresh()->video_asset_id)->toBe($asset->id);

    $this->app['auth']->forgetGuards();
    $this->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertUnauthorized();
    $this->actingAs(e16User())->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertForbidden();
});

test('UX-06: dropdowns render real <option> elements with readable names, and the admin CSS gives every select/option solid colours (no translucent option background, no global select rule)', function () {
    $w = ux_world();
    $video = cin_video_asset($w['admin']);
    $this->actingAs($w['admin']);

    $html = $this->get(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id, 'scene' => $w['film']->public_id]))->assertOk()->getContent();
    expect($html)->toContain('<option value="'.$video->public_id.'"')->toContain('scene.mp4')->toContain('data-scene-media')->toContain('data-save-media');

    $css = (string) file_get_contents(resource_path('views/filament/pages/partials/cinematic-admin-styles.blade.php'));
    expect($css)->toContain('.cin-admin select option, .cin-admin select optgroup')->toContain('color-scheme: dark')->toContain('color-scheme: light');
    // كل محدِّد في الملف محصور بـ.cin-admin (لا قاعدة عامة على select/option/input/textarea ولا غيرها خارج المحيط).
    $clean = preg_replace('/\/\*.*?\*\//s', '', preg_replace('/\{\{--.*?--\}\}/s', '', $css));
    foreach (preg_split('/\}\s*/', $clean) as $rule) {
        $rule = trim($rule);
        if (! str_contains($rule, '{') || str_starts_with($rule, '@') || str_starts_with($rule, '<style') || str_contains($rule, '<style')) {
            continue;
        }
        [$selector] = explode('{', $rule, 2);
        $selector = preg_replace('/:(where|not)\((?:[^()]|\([^()]*\))*\)/', '', $selector);
        foreach (explode(',', $selector) as $part) {
            expect(str_contains(trim($part), '.cin-admin'))->toBeTrue('قاعدة غير محصورة بـ.cin-admin: '.trim($part));
        }
    }
    expect($css)->not->toMatch('/(select|option)[^{]*\{[^}]*background(-color)?:\s*(rgba|transparent)/');
});

test('UX-06b: WCAG AA contrast of the admin palette is computed from the real CSS tokens in dark and light (text 4.5:1, field borders and focus 3:1)', function () {
    $css = (string) file_get_contents(resource_path('views/filament/pages/partials/cinematic-admin-styles.blade.php'));
    $themes = ['light' => ux_tokens($css, '.cin-admin'), 'dark' => ux_tokens($css, '.dark .cin-admin')];
    $text = [['fg', 'surface'], ['fg', 'surface-2'], ['fg', 'field'], ['muted', 'surface'], ['muted', 'surface-2'], ['primary-fg', 'primary'], ['link', 'surface'], ['link', 'surface-2'],
        ['danger', 'danger-bg'], ['ok', 'ok-bg'], ['warn', 'warn-bg'], ['sel-fg', 'sel-bg'], ['disabled-fg', 'disabled-bg'], ['danger', 'surface'], ['ok', 'surface'], ['warn', 'surface'],
        ['t-video-fg', 't-video-bg'], ['t-narrative-fg', 't-narrative-bg'], ['t-puzzle-fg', 't-puzzle-bg'], ['t-interaction-fg', 't-interaction-bg'], ['t-exploration-fg', 't-exploration-bg'], ['t-transition-fg', 't-transition-bg']];
    $ui = [['border', 'surface'], ['border', 'field'], ['focus', 'surface']];

    foreach ($themes as $name => $t) {
        expect(count($t))->toBeGreaterThan(30, "{$name}: tokens parsed");
        foreach ($text as [$fg, $bg]) {
            expect(ux_contrast($t[$fg], $t[$bg]))->toBeGreaterThanOrEqual(4.5, "{$name}: {$fg} on {$bg}");
        }
        foreach ($ui as [$fg, $bg]) {
            expect(ux_contrast($t[$fg], $t[$bg]))->toBeGreaterThanOrEqual(3.0, "{$name}: {$fg} on {$bg}");
        }
        // خلفية القائمة وخيارها مصمتتان (لا شفافية): كلاهما سداسي صريح.
        expect($t['field'])->toMatch('/^#[0-9a-f]{6}$/i');
    }
});

// ───────────────────────────── رفع الوسائط وربطها ─────────────────────────────

test('UX-07: a valid MP4 uploads (real size/duration/codec shown in the library with a signed admin preview), and the same file again is reported as a duplicate without a second copy', function () {
    $admin = cin_admin();
    $this->actingAs($admin);
    $file = fn () => new UploadedFile(cin_tiny_mp4(), 'PLM_S01_V02_arrival.mp4', 'video/mp4', null, true);

    $first = $this->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => $file()])->assertCreated();
    expect($first->json('created'))->toBeTrue()->and($first->json('name'))->toBe('PLM_S01_V02_arrival.mp4')->and(json_encode($first->json()))->not->toContain('cinematic/');
    $stored = count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles());

    $again = $this->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => $file()])->assertOk();
    expect($again->json('created'))->toBeFalse()->and($again->json('id'))->toBe($first->json('id'))
        ->and(CinematicMediaAsset::query()->where('role', 'video')->count())->toBe(1)
        ->and(count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles()))->toBe($stored);

    $html = $this->get(CinematicMediaLibraryPage::getUrl(['role' => 'video']))->assertOk()->getContent();
    expect($html)->toContain('PLM_S01_V02_arrival.mp4')->toContain('avc1')->toContain('data-media-preview')->toContain('signature=')->toContain('غير مربوط بأي مشهد')->not->toContain('/storage/');
});

test('UX-08: invalid uploads are rejected with a precise Arabic reason and store nothing - fake MP4, wrong role, fake image, oversize, unknown role', function () {
    $admin = cin_admin();
    $this->actingAs($admin);
    $before = CinematicMediaAsset::query()->count();
    $files = count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles());

    $fakeVideo = $this->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => new UploadedFile(cin_tmp('هذا نص وليس فيديو', 'mp4'), 'x.mp4', 'video/mp4', null, true)])->assertUnprocessable();
    expect($fakeVideo->json('reason'))->toBe('video_type')->and($fakeVideo->json('message'))->toContain('MP4');

    $fakeImage = $this->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => new UploadedFile(cin_tmp('not an image', 'png'), 'x.png', 'image/png', null, true)])->assertUnprocessable();
    expect($fakeImage->json('reason'))->toBe('poster_type');

    $png = new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true);
    $wrongRole = $this->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => $png])->assertUnprocessable();
    expect($wrongRole->json('reason'))->toBe('video_type');

    config(['cinematic_stories.media.poster.max_bytes' => 10]);
    $big = $this->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true)])->assertUnprocessable();
    expect($big->json('reason'))->toBe('file_too_large')->and($big->json('message'))->toContain('الحد');
    $this->postJson('/admin/cinematic/media', ['role' => 'hologram', 'file' => new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true)])->assertUnprocessable();

    expect(CinematicMediaAsset::query()->count())->toBe($before)->and(count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles()))->toBe($files);
});

test('UX-09: linking a video to a scene only references the library file (no copy, no new asset row), marks the scene ready, and shows «معاينة هذا المشهد» in the confirmation', function () {
    $w = ux_world();
    $asset = cin_video_asset($w['admin']);
    $this->actingAs($w['admin']);
    $assets = CinematicMediaAsset::query()->count();
    $files = count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles());

    $c = ux_builder($w)->call('editScene', $w['film']->public_id)->set('sf.media.video', $asset->public_id)->call('saveMedia');
    $film = $w['film']->fresh();
    expect($film->video_asset_id)->toBe($asset->id)->and($film->awaiting_media)->toBeFalse()->and($c->get('sf.awaiting'))->toBeFalse()->and($c->get('sf.media.video'))->toBe($asset->public_id)
        ->and(CinematicMediaAsset::query()->count())->toBe($assets)->and(count(Storage::disk((string) config('cinematic_stories.media.disk'))->allFiles()))->toBe($files);

    $notifications = collect(session('filament.notifications', []));
    expect($notifications->pluck('title')->implode(' '))->toContain('فيديو جاهز')
        ->and(json_encode($notifications->all(), JSON_UNESCAPED_UNICODE))->toContain('معاينة هذا المشهد')->toContain($w['revision']->public_id);

    // إلغاء الربط لا يحذف الملف.
    $c->set('sf.media.video', '')->call('saveMedia');
    expect($w['film']->fresh()->video_asset_id)->toBeNull()->and($asset->fresh()->status)->toBe('ready');
});

test('UX-10: a wrong media type is refused at the link step - a poster as the video, a video as the poster - and the scene is unchanged', function () {
    $w = ux_world();
    $poster = cin_poster_asset($w['admin']);
    $video = cin_video_asset($w['admin']);
    $this->actingAs($w['admin']);

    ux_builder($w)->call('editScene', $w['film']->public_id)->set('sf.media.video', $poster->public_id)->call('saveMedia');
    expect($w['film']->fresh()->video_asset_id)->toBeNull();
    expect(cin_reason(fn () => cin_authoring()->replaceMedia($w['film']->fresh(), 'video', $poster)))->not->toBeNull();

    ux_builder($w)->call('editScene', $w['door']->public_id)->set('sf.media.poster', $video->public_id)->call('saveMedia');
    expect($w['door']->fresh()->poster_asset_id)->not->toBe($video->id);
    expect(cin_reason(fn () => cin_authoring()->replaceMedia($w['door']->fresh(), 'poster', $video)))->not->toBeNull();
});

test('UX-11: the preview of a linked video serves the real file through a signed, user-bound link (Range works), straight into the chosen scene', function () {
    $w = ux_world();
    $asset = cin_video_asset($w['admin']);
    cin_authoring()->replaceMedia($w['film']->fresh(), 'video', $asset);
    $this->actingAs($w['admin']);

    $page = $this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id, 'scene' => $w['film']->public_id]))->assertOk()->assertSee('data-cinematic-preview-banner', false);
    $cfg = ux_cfg($page->getContent());
    $scene = $cfg['scenes'][$w['film']->public_id];
    expect($cfg['start_scene'])->toBe($w['film']->public_id)->and($scene['video']['state'])->toBe('ready')->and($scene['video']['src'])->toContain('/cinematic/media/')->toContain('signature=')
        ->and($cfg['urls']['builder'])->toContain('scene=__SCENE__')->toContain($w['story']->public_id)->and($cfg['urls']['back'])->toBeString();

    $full = $this->get($scene['video']['src'])->assertOk();
    expect($full->headers->get('Content-Type'))->toBe('video/mp4')->and($full->headers->get('Accept-Ranges'))->toBe('bytes');
    expect($this->withHeaders(['Range' => 'bytes=0-49'])->get($scene['video']['src'])->getStatusCode())->toBe(206);
});

test('UX-12: a scene without its video previews as «awaiting» with its transcript, the readiness check names it, and the player template has an explicit message for it', function () {
    $w = ux_world();
    $this->actingAs($w['admin']);

    $cfg = ux_cfg($this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id, 'scene' => $w['film']->public_id]))->assertOk()->getContent());
    $scene = $cfg['scenes'][$w['film']->public_id];
    expect($scene['video']['state'])->toBe('awaiting')->and($scene['video']['src'])->toBeNull()->and($scene['transcript'][0]['text'])->toBe('حوار')
        ->and(collect($cfg['readiness']['blockers'])->where('scene', 'film_wait')->pluck('code')->all())->toContain('video_missing')
        ->and($cfg['readiness']['ok'])->toBeFalse();

    $blade = (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));
    expect($blade)->toContain('هذا المشهد قيد إنتاج الفيديو')->toContain('data-cinematic-video-state')->toContain('data-cinematic-playoverlay')->toContain('data-cinematic-replay')->toContain('retryVideo');

    $builder = ux_builder($w)->call('editScene', $w['film']->public_id);
    $builder->assertSee('data-scene-readiness', false);
});

test('UX-12b: the media library shows the scenes that use a file, warns when one video serves two scenes, and builds the «return to scene» link on the server from real records only', function () {
    $w = ux_world();
    $asset = cin_video_asset($w['admin']);
    cin_authoring()->replaceMedia($w['film']->fresh(), 'video', $asset);
    $second = cin_authoring()->addScene($w['chapter'], ['type' => 'video', 'title' => 'فيديو ثانٍ', 'stable_key' => 'film_two']);
    cin_authoring()->replaceMedia($second, 'video', $asset);
    $this->actingAs($w['admin']);

    $q = ['role' => 'video', 'story' => $w['story']->public_id, 'revision' => $w['revision']->public_id, 'scene' => $w['film']->public_id];
    $html = $this->get(CinematicMediaLibraryPage::getUrl($q))->assertOk()->getContent();
    expect($html)->toContain('data-media-context')->toContain('data-return-to-scene')->toContain('فيديو ناقص')->toContain('data-media-usage')->toContain('فيديو ثانٍ')->toContain('data-media-shared')
        ->toContain(e(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id, 'revision' => $w['revision']->public_id, 'scene' => $w['film']->public_id])));

    // سياق غير صالح: مشهد من قصة أخرى، أو قيمة عشوائية/رابط خارجي ⇒ لا زر عودة ولا إعادة توجيه.
    $other = cin_story();
    $foreign = cin_narrative($other['chapter'], 'foreign_one');
    foreach ([['scene' => $foreign->public_id, 'story' => $w['story']->public_id], ['scene' => 'https://evil.example/x'], ['scene' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ']] as $bad) {
        $page = $this->get(CinematicMediaLibraryPage::getUrl(['role' => 'video'] + $bad))->assertOk()->getContent();
        expect($page)->not->toContain('data-return-to-scene')->not->toMatch('/href="[^"]*evil\.example/');
    }

    // البحث لا يتأثر بأحرف _ و% في اسم الملف.
    $c = Livewire::withQueryParams(['role' => 'video'])->test(CinematicMediaLibraryPage::class);
    expect($c->set('search', 'scene.mp4')->html())->toContain('scene.mp4');
    expect($c->set('search', 'scene_mp4')->html())->not->toContain('data-media-row=');
    expect($c->set('search', '%')->html())->not->toContain('data-media-row=');
});

// ───────────────────────────── المعاينة والتفاعل ─────────────────────────────

test('UX-13: previewing a draft opens at the requested scene, writes no progress and no economy rows, and ignores a start scene from another revision', function () {
    $w = ux_world();
    $other = cin_story();
    $foreign = cin_narrative($other['chapter'], 'foreign_start');
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];
    $this->actingAs($w['admin']);

    $cfg = ux_cfg($this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id, 'scene' => $w['lamp']->public_id]))->assertOk()->getContent());
    expect($cfg['mode'])->toBe('preview')->and($cfg['start_scene'])->toBe($w['lamp']->public_id)->and(count($cfg['scenes']))->toBe(5);

    $none = ux_cfg($this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id, 'scene' => $foreign->public_id]))->assertOk()->getContent());
    expect($none['start_scene'])->toBeNull();
    $plain = ux_cfg($this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id]))->assertOk()->getContent());
    expect($plain['start_scene'])->toBeNull();

    foreach ([['door_scene', ['area' => 'handle']], ['lamp_scene', ['points' => [['x' => 45, 'y' => 49]]]]] as [$key, $submission]) {
        $scene = CinematicScene::query()->where('revision_id', $w['revision']->id)->where('stable_key', $key)->first();
        $this->postJson(route('cinematic.preview.submit', [$w['revision']->public_id, $scene->public_id]), ['submission' => $submission])->assertOk();
    }
    expect([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});

test('UX-14: the door (hotspot) interaction completes only on the right area - judged on the server, in preview and in real play - and wrong clicks never complete it', function () {
    $w = ux_play_world();
    $user = cin_user();
    [$progress] = cin_begin($w['story'], $user);

    $this->actingAs($w['admin']);
    $url = route('cinematic.preview.submit', [$w['live']->public_id, $w['door']->public_id]);
    expect($this->postJson($url, ['submission' => ['area' => 'handle']])->json('result.correct'))->toBeTrue()
        ->and($this->postJson($url, ['submission' => ['area' => 'nowhere']])->json('result.correct'))->toBeFalse()
        ->and($this->postJson($url, ['submission' => ['area' => 'handle']])->json('result.correct'))->toBeTrue();

    $this->actingAs($user);
    $slug = $w['story']->slug;
    $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/start")->assertOk();
    $bad = $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'nowhere']])->assertOk();
    expect($bad->json('result.correct'))->toBeFalse()->and(CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->count())->toBe(0);
    $good = $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'handle']])->assertOk();
    expect($good->json('result.correct'))->toBeTrue()->and(CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->count())->toBe(1);
});

test('UX-15: the flashlight (lamp) interaction completes only when a checked point falls on the hidden mark - in preview and in real play (after the door, as ordered)', function () {
    $w = ux_play_world();
    $user = cin_user();
    [$progress] = cin_begin($w['story'], $user);

    $this->actingAs($w['admin']);
    $url = route('cinematic.preview.submit', [$w['live']->public_id, $w['lamp']->public_id]);
    expect($this->postJson($url, ['submission' => ['points' => [['x' => 5, 'y' => 5]]]])->json('result.correct'))->toBeFalse()
        ->and($this->postJson($url, ['submission' => ['points' => [['x' => 45, 'y' => 49]]]])->json('result.correct'))->toBeTrue();

    $this->actingAs($user);
    $slug = $w['story']->slug;
    $this->postJson("/cinematic/{$slug}/scenes/{$w['lamp']->public_id}/start")->assertForbidden();           // الترتيب محفوظ: المصباح بعد الباب
    $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/start")->assertOk();
    $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'handle']])->assertOk();
    $this->postJson("/cinematic/{$slug}/scenes/{$w['lamp']->public_id}/start")->assertOk();
    expect($this->postJson("/cinematic/{$slug}/scenes/{$w['lamp']->public_id}/submit", ['submission' => ['points' => [['x' => 90, 'y' => 90]]]])->json('result.correct'))->toBeFalse()
        ->and($this->postJson("/cinematic/{$slug}/scenes/{$w['lamp']->public_id}/submit", ['submission' => ['points' => [['x' => 45, 'y' => 49]]]])->json('result.correct'))->toBeTrue()
        ->and(CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->count())->toBe(2);
});

test('UX-16: no solution leaks anywhere - preview config, player page, scene payloads, builder for a viewer, library; the calibrator for hidden marks never reaches a viewer', function () {
    $w = ux_play_world();
    $this->actingAs($w['admin']);

    $page = $this->get(route('cinematic.preview', ['revision' => $w['live']->public_id]))->assertOk()->getContent();
    $cfgJson = json_encode(ux_cfg($page));
    foreach (['"correct"', 'mark_first', '"targets"', '"hidden"', '"required"'] as $needle) {
        expect($cfgJson)->not->toContain($needle, "preview config leaks {$needle}");
    }

    $user = cin_user();
    cin_begin($w['story'], $user);
    $this->actingAs($user);
    $slug = $w['story']->slug;
    $state = $this->getJson("/cinematic/{$slug}/state")->assertOk();
    $door = $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/start")->assertOk();
    $this->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'handle']])->assertOk();
    $lamp = $this->postJson("/cinematic/{$slug}/scenes/{$w['lamp']->public_id}/start")->assertOk();
    foreach ([json_encode($state->json()), json_encode($door->json()), json_encode($lamp->json()), $this->get("/cinematic/{$slug}/play")->assertOk()->getContent()] as $blob) {
        expect($blob)->not->toContain('mark_first')->not->toContain('"correct"')->not->toContain('"targets"');
    }

    $viewer = ux_staff(['cinematic-stories.view']);
    $draft = cin_story();
    $this->flushSession();
    $secret = cin_interaction($draft['chapter'], 'secret_lamp', ['kind' => 'flashlight', 'prompt' => 'ابحث', 'light_radius' => 10], ['targets' => [['id' => 'hidden_mark_xyz', 'x' => 31, 'y' => 37, 'w' => 11, 'h' => 12]], 'required' => 1]);
    $html = $this->actingAs($viewer)->get(CinematicBuilderPage::getUrl(['story' => $draft['story']->public_id, 'scene' => $secret->public_id]))->assertOk()->getContent();
    expect($html)->not->toContain('hidden_mark_xyz')->not->toContain('data-calibrator="hidden"');

    // (الجلسة تحمل بصمة كلمة مرور المستخدم السابق؛ نفرّغها عند تبديل الحساب داخل الاختبار نفسه)
    $this->flushSession();
    $author = ux_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $authorHtml = $this->actingAs($author)->get(CinematicBuilderPage::getUrl(['story' => $draft['story']->public_id, 'scene' => $secret->public_id]))->assertOk()->getContent();
    expect($authorHtml)->toContain('hidden_mark_xyz')->toContain('data-solution-editor')->toContain('id="cin-src-hidden"')->not->toContain('data-calibrator="hidden"');   // بلا صلاحية وسائط/معاينة لا صورة ولا معايرة، لكن الحقل النصي للمحرّر متاح

    $this->flushSession();
    $lib = $this->actingAs($viewer)->get(CinematicMediaLibraryPage::getUrl())->assertOk()->getContent();
    expect($lib)->not->toContain('hidden_mark_xyz')->not->toContain('mark_first');
});

// ───────────────────────────── الروابط الموقَّعة والملكية والعلم ─────────────────────────────

test('UX-17: library preview links are signed, user-bound, permission-bound and flag-bound; tampering, another account, a guest, a stranger, a wrong scope id and a retired file are all refused', function () {
    $admin = cin_admin();
    $asset = cin_video_asset($admin);
    $links = app(CinematicMediaLinks::class);
    $link = $links->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $admin);

    $ok = $this->actingAs($admin)->get($link)->assertOk();
    expect($ok->headers->get('Content-Type'))->toBe('video/mp4')->and($ok->headers->get('Cache-Control'))->toContain('no-store')->and($ok->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($this->actingAs($admin)->withHeaders(['Range' => 'bytes=0-9'])->get($link)->getStatusCode())->toBe(206);
    $this->flushHeaders();

    $this->actingAs($admin)->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $link))->assertForbidden();
    $this->actingAs($admin)->get(str_replace('p=0', 'p=1', $link))->assertForbidden();
    $this->actingAs(cin_admin())->get($link)->assertForbidden();                                   // رابط مسرَّب لمسؤول آخر
    $stranger = e16User();
    $this->actingAs($stranger)->get($links->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $stranger))->assertForbidden();
    $viewer = ux_staff(['cinematic-stories.view']);
    $this->actingAs($viewer)->get($links->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $viewer))->assertForbidden();
    $previewer = ux_staff(['cinematic-stories.view', 'cinematic-stories.preview']);
    $this->actingAs($previewer)->get($links->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $previewer))->assertOk();
    $this->actingAs($admin)->get($links->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, 'some-scene-id', $admin))->assertStatus(404);
    $this->app['auth']->forgetGuards();
    $this->get($link)->assertRedirect(route('login'));

    app(App\Services\Cinematic\Media\CinematicMediaService::class)->retire($asset);
    $this->actingAs($admin)->get($link)->assertNotFound();

    // الصفحة لا تُنتج رابطًا لملف مسحوب ولا لمن لا يملك الصلاحية، ولا تكشف مسار التخزين.
    $page = $this->actingAs($admin)->get(CinematicMediaLibraryPage::getUrl(['role' => 'video']))->assertOk()->getContent();
    expect($page)->not->toContain('signature=')->not->toContain($asset->path);
});

test('UX-18: premium (E43) ownership is untouched - a stranger sees the lock, the buyer plays, a stranger\'s signed link fails, and the staff preview/library never grant player access', function () {
    e43_seed(['cinematic_stories.enabled' => true]);
    $world = e43_world();
    $w = cin_story(['premium_series' => $world['series']]);
    $film = cin_video_scene($w['chapter'], 'film_p');
    $live = cin_publish($w['revision']);
    $slug = $w['story']->slug;
    $stranger = cin_user();

    $this->actingAs($stranger)->get("/cinematic/{$slug}")->assertOk()->assertSee('data-cinematic-locked', false);
    $this->actingAs($stranger)->getJson("/cinematic/{$slug}/state")->assertForbidden()->assertJsonPath('reason', 'premium_locked');
    $this->actingAs($stranger)->get(route('cinematic.preview', $live->public_id))->assertForbidden();
    $link = app(CinematicMediaLinks::class)->url($film->fresh()->video, 'scene', $film->public_id, $stranger);
    $this->actingAs($stranger)->get($link)->assertForbidden();

    $buyer = e43_buy($world)['user'];
    $this->actingAs($buyer)->get("/cinematic/{$slug}/play")->assertOk();
    $this->actingAs($buyer)->get(route('cinematic.preview', $live->public_id))->assertForbidden();   // المالك لاعب لا موظف
    expect(CinematicProgress::query()->where('user_id', $stranger->id)->count())->toBe(0);
});

test('UX-19: with CINEMATIC_STORIES_ENABLED=false the whole feature is closed - admin pages, preview (with ?scene=), media links (scene and library), upload - even for an administrator', function () {
    $w = ux_world();
    $asset = cin_video_asset($w['admin']);
    $link = app(CinematicMediaLinks::class)->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $w['admin']);
    $this->actingAs($w['admin']);
    config(['cinematic_stories.enabled' => false]);

    $this->get(route('cinematic.preview', ['revision' => $w['revision']->public_id, 'scene' => $w['door']->public_id]))->assertNotFound();
    $this->get($link)->assertNotFound();
    $this->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true)])->assertNotFound();
    $this->get(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id, 'scene' => $w['door']->public_id]))->assertForbidden();
    $this->get(CinematicMediaLibraryPage::getUrl(['scene' => $w['door']->public_id]))->assertForbidden();
    expect(CinematicBuilderPage::canAccess())->toBeFalse()->and(CinematicMediaLibraryPage::canAccess())->toBeFalse();

    $config = (string) file_get_contents(base_path('config/cinematic_stories.php'));
    expect($config)->toMatch("/'enabled'\s*=>\s*\(bool\)\s*env\('CINEMATIC_STORIES_ENABLED',\s*false\)/");
});

// ───────────────────────────── عقود القوالب (مشغّل الصورة وأداة المعايرة) ─────────────────────────────

test('UX-20: the image player draws hotspot/container/flashlight on a stage that takes the real image ratio (no fixed 16:9 + cover crop), LTR, with click-on-image primary and a keyboard alternative', function () {
    $blade = (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));
    $from = strpos($blade, "x-if=\"kind === 'hotspot' || kind === 'container'\"");
    $to = strpos($blade, '<!-- ترتيب -->');
    $block = substr($blade, (int) $from, (int) $to - (int) $from);

    expect($from)->not->toBeFalse()->and($block)->toContain('data-cinematic-hotspot-stage')->toContain('dir="ltr"')->toContain('stageStyle')->toContain('stageClick($event)')->toContain('imgLoaded($event)')
        ->toContain('المناطق (بديل لوحة المفاتيح وقارئ الشاشة)')->toContain('submit({ area: a.id })')
        ->and(substr_count($block, '<img :src="scene.image"'))->toBe(1)
        ->and($block)->not->toContain('aspect-video')->not->toContain('object-cover')->not->toContain('x-html');
    expect($blade)->toContain('object-fit: fill')->toContain('naturalWidth / img.naturalHeight')->toContain('Math.max(0, (44 - w) / 2)')->toContain('pointer-events: none')
        ->toContain("this.submit({ area: a.id })")->toContain('لا شيء هنا')->toContain('aspect-ratio:');

    // لا حدود دائمة على مناطق الصورة: الحدود المنقّطة لا تُرسم إلا حين لا توجد صورة أصلًا.
    expect($block)->toContain('<template x-if="!scene.image">')->toContain('cin-outline')->toContain('cin-glow');
    // الإرسال إلى الخادم فقط عند إصابة منطقة، والنقر الخاطئ ملاحظة محلية بلا طلب.
    expect($blade)->toMatch('/if \(a\) \{ this\.nudge = \'\'; this\.submit\(\{ area: a\.id \}\); return; \}/');
});

test('UX-21: the scene-image block for the other four kinds is untouched (still pinned by IMG-03) and the template still avoids x-html / innerHTML / raw output', function () {
    $blade = (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));

    expect($blade)->toContain("['sequence', 'drag_drop', 'choose_item', 'compare'].indexOf(kind) !== -1")->toContain('data-cinematic-scene-image')
        ->and(substr_count($blade, '<img :src="scene.image"'))->toBe(4)
        ->and($blade)->not->toContain('x-html')->not->toContain('innerHTML')->not->toContain('{!!');
});

test('UX-22: the preview toolbar exposes context, picker, previous/next/replay, back-to-editor and warnings, and states that preview is not proof of real play', function () {
    $blade = (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));

    foreach (['data-cinematic-preview-banner', 'data-cinematic-preview-context', 'data-preview-picker', 'data-preview-prev', 'data-preview-next', 'data-preview-replay', 'data-preview-edit', 'data-preview-notes',
        'لا يُحفظ أي تقدّم ولا تُمنح مكافآت', 'لا يُعدّ دليلًا على نجاحه في اللعب الفعلي', 'جاهزة للنشر', 'غير جاهزة للنشر', 'previewGroups'] as $needle) {
        expect($blade)->toContain($needle);
    }
});

test('UX-23: the visual calibrator is wired to the existing text field (no new save path), covers drag/resize/click-to-centre/keyboard, and appears only for editors with the manage permission', function () {
    $scripts = (string) file_get_contents(resource_path('views/filament/pages/partials/cinematic-admin-scripts.blade.php'));
    $cal = (string) file_get_contents(resource_path('views/filament/pages/partials/cinematic-calibrator.blade.php'));
    $builder = (string) file_get_contents(resource_path('views/filament/pages/cinematic-builder.blade.php'));

    expect($scripts)->toContain('window.cinCalibrator')->toContain("dispatchEvent(new Event('input', { bubbles: true }))")->toContain('setPointerCapture')->toContain('stageDown')->toContain('ArrowRight')->toContain('syncFromText')
        ->and($cal)->toContain('wire:ignore')->toContain('data-cal-stage')->toContain('dir="ltr"')->toContain("'aspect-ratio:' + ratio")->toContain('data-cal-fields')
        ->and($builder)->toContain('data-calibrator-block')->toContain('id="cin-src-areas"')->toContain('id="cin-src-hidden"');

    $w = ux_world();
    $viewer = ux_staff(['cinematic-stories.view']);
    $author = ux_staff(['cinematic-stories.view', 'cinematic-stories.manage', 'cinematic-stories.preview']);
    $textOnly = ux_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $url = CinematicBuilderPage::getUrl(['story' => $w['story']->public_id, 'scene' => $w['door']->public_id]);

    $html = $this->actingAs($author)->get($url)->assertOk()->getContent();
    expect($html)->toContain('data-calibrator="areas"')->toContain('data-cal-stage')->toContain('signature=')->toMatch('/handle\|[^|]*\|60\|45\|10\|10/')->not->toContain('data-calibrator-noaccess');

    // من يملك manage فقط يرى الحقل النصي لكن لا صورة (الملف بلا صلاحية وسائط/معاينة) مع شرح واضح، لا رابط ملف.
    $this->flushSession();
    $noImage = $this->actingAs($textOnly)->get($url)->assertOk()->getContent();
    expect($noImage)->toContain('data-calibrator-noaccess')->not->toContain('data-cal-stage')->not->toContain('signature=');

    $this->flushSession();
    $viewerHtml = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
    expect($viewerHtml)->not->toMatch('/handle\|[^|]*\|60\|45\|10\|10/')->not->toContain('wire:click="saveScene"')->not->toContain('data-cal-stage');
});

test('UX-24: no guessed door-handle coordinates were added to code - the calibrator, player, controller and library carry no scene keys or coordinates; the area lives only in scene data set from the admin panel', function () {
    foreach (['views/filament/pages/partials/cinematic-calibrator.blade.php', 'views/filament/pages/partials/cinematic-admin-scripts.blade.php', 'views/cinematic/play.blade.php', 'views/filament/pages/cinematic-media-library.blade.php'] as $file) {
        $src = (string) file_get_contents(resource_path($file));
        expect($src)->not->toContain('arrival_door')->not->toContain('door_handle')->not->toContain('PLM_S01');
    }
    foreach (['Http/Controllers/Cinematic/CinematicPreviewController.php', 'Filament/Pages/CinematicMediaLibraryPage.php', 'Filament/Pages/CinematicBuilderPage.php'] as $file) {
        $src = (string) file_get_contents(app_path($file));
        expect($src)->not->toContain('arrival_door')->not->toContain('door_handle');
    }
});

test('UX-25: the stories list page uses the scoped admin styles - real selects with readable options, no translucent field classes, the create form only for managers', function () {
    $admin = cin_admin();
    $this->actingAs($admin);

    $html = Livewire::test(CinematicStoriesPage::class)->set('showCreate', true)->html();
    expect($html)->toContain('data-cin-root')->toContain('data-cin-admin-styles')->toContain('data-cinematic-create')->toContain('data-cinematic-table')
        ->toContain('<select wire:model="form.label_kind">')->toContain('قصة قصيرة')->toContain('قصة طويلة')->toContain('مجانية')
        ->not->toContain('dark:bg-white/5')->not->toContain('rounded-lg border border-gray-300');

    $viewer = ux_staff(['cinematic-stories.view']);
    $this->flushSession();
    $this->actingAs($viewer);
    $viewerHtml = Livewire::test(CinematicStoriesPage::class)->html();
    expect($viewerHtml)->toContain('data-cin-root')->not->toContain('قصة جديدة')->not->toContain('wire:click="create"');
});
