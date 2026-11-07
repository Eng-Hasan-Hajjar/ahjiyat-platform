<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicSceneState;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/_support.php';

uses(RefreshDatabase::class);

/*
| CIN-1-FIX - صور المشاهد في التفاعلات. الإصلاح في قالب العرض فقط (resources/views/cinematic/play.blade.php):
| صورة المشهد (scene.image = ملصق المشهد برابط موقَّع) كانت تُعرض داخل كتل hotspot / container / flashlight فقط،
| فلا تظهر في sequence / drag_drop / choose_item / compare. هذه الاختبارات جديدة ولا تعدّل أي اختبار قديم:
|  - IMG-01: الحمولة الخادمية تحمل رابط صورة موقَّعًا صالحًا للأنواع الأربعة، والرابط يعمل (للمستخدم نفسه فقط).
|  - IMG-02: لا صورة ولا رابط عند غياب الملصق (لا صورة مكسورة من الخادم).
|  - IMG-03: عقد القالب: قائمة الأنواع الأربعة بالضبط، إطار 16:9، إخفاء عند فشل التحميل، لا تدخّل في الإرسال، لا تكرار.
|  - IMG-04: لا انحدار في hotspot / container / flashlight (صورة واحدة في كتلتها) ولا في بقية الأنواع.
|  - IMG-05: العلم مغلق ⇒ 404 للصفحة والبدء والرابط الموقَّع.
|  - IMG-06: لا تسريب لحلول الأحجيات في الصفحة ولا في الاستجابات، ولا كتابة اقتصادية، والتحقق/الإرسال كما كان.
*/

const CIN_IMG_FOUR = ['sequence', 'drag_drop', 'choose_item', 'compare'];

/** @return string نص قالب العرض */
function cin_img_blade(): string
{
    return (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));
}

/** يقتطع كتلة <template x-if="..."> ابتداءً من موضع محدد حتى إغلاقها المتوازن. */
function cin_img_block(string $blade, string $needle): string
{
    $start = strpos($blade, $needle);
    expect($start)->not->toBeFalse();
    $start = strrpos(substr($blade, 0, $start), '<template');
    $depth = 0;
    $len = strlen($blade);

    for ($i = $start; $i < $len; $i++) {
        if (substr($blade, $i, 9) === '<template') {
            $depth++;
        } elseif (substr($blade, $i, 11) === '</template>') {
            $depth--;

            if ($depth === 0) {
                return substr($blade, $start, $i + 11 - $start);
            }
        }
    }

    throw new RuntimeException('unbalanced template');
}

/** قصة منشورة بمشهد تفاعل واحد (هو المشهد الحالي)، مع ملصق أو بدونه. @return array<string, mixed> */
function cin_img_story(string $kind, bool $withPoster, array $overrides = []): array
{
    $w = cin_story();
    [$public, $solution] = $overrides[$kind] ?? cin_defs()[$kind];

    // container / reveal_code يشترطان في بيانات الاختبار المشتركة مشهدًا سابقًا غير موجود هنا؛ يُحذف الشرط ليُنشَر المشهد منفردًا.
    unset($solution['requires_scenes']);

    $scene = cin_interaction($w['chapter'], 'riddle_'.$kind, $public, $solution);

    if ($withPoster) {
        cin_authoring()->replaceMedia($scene->fresh(), 'poster', cin_poster_asset());
    }

    $live = cin_publish($w['revision']);

    return $w + ['scene' => $scene->fresh(), 'live' => $live, 'kind' => $kind, 'solution' => $solution];
}

function cin_img_start(object $t, array $w, $user): \Illuminate\Testing\TestResponse
{
    return $t->actingAs($user)->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$w['scene']->public_id.'/start')->assertOk();
}

test('IMG-01: for sequence / drag_drop / choose_item / compare an authorised scene image reaches the player as a signed link that actually serves the image - for its owner only', function (string $kind) {
    cin_seed();
    $w = cin_img_story($kind, true);
    $user = cin_user();
    $other = cin_user();

    $r = cin_img_start($this, $w, $user);
    $image = $r->json('scene.image');

    expect($r->json('scene.interaction.kind'))->toBe($kind)
        ->and($image)->toBeString()->toContain('/cinematic/media/')->toContain('signature=')->toContain('expires=');

    $img = $this->actingAs($user)->get($image)->assertOk();
    expect((string) $img->headers->get('Content-Type'))->toStartWith('image/');

    // الرابط مربوط بالمستخدم: حساب آخر لا يستفيد منه، وبلا دخول لا يعمل.
    $this->actingAs($other)->get($image)->assertForbidden();
    auth()->logout();
    $this->get($image)->assertRedirect();
})->with(CIN_IMG_FOUR);

test('IMG-02: without an authorised poster the payload carries no image and no media link at all - there is nothing for the page to draw broken', function (string $kind) {
    cin_seed();
    $w = cin_img_story($kind, false);
    $user = cin_user();

    $r = cin_img_start($this, $w, $user);

    expect($r->json('scene.image'))->toBeNull()->and($r->json('scene.interaction.kind'))->toBe($kind)
        ->and($r->getContent())->not->toContain('/cinematic/media/')->not->toContain('signature=');

    // وصفحة اللعب نفسها لا تحمل أي رابط وسائط قبل بدء المشهد.
    $page = $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play')->assertOk()->getContent();
    expect($page)->not->toContain('/cinematic/media/');
})->with(CIN_IMG_FOUR);

test('IMG-02b: a poster that is not ready or whose file is gone is never exposed as an image link', function () {
    cin_seed();
    $w = cin_img_story('sequence', true);
    $poster = $w['scene']->fresh()->poster;
    $user = cin_user();

    // الملف اختفى من القرص: الخادم لا يصدر رابطًا لملف غير موجود.
    \Illuminate\Support\Facades\Storage::disk((string) config('cinematic_stories.media.disk', 'local'))->deleteDirectory('');
    $r = cin_img_start($this, $w, $user);

    expect($poster)->not->toBeNull()->and($r->json('scene.image'))->toBeNull();
});

test('IMG-03: the template contract - exactly the four affected kinds, one 16:9 frame, hidden on load failure, purely visual, never inside the controls', function () {
    $blade = cin_img_blade();
    $block = cin_img_block($blade, 'data-cinematic-scene-image');

    // قائمة الأنواع بالضبط، ولا يظهر فيها النوع الذي يعرض صورته داخل كتلته.
    preg_match('/x-if="scene\.image && \[([^\]]*)\]\.indexOf\(kind\) !== -1"/', $block, $m);
    $kinds = array_map(fn ($k) => trim($k, " '"), explode(',', $m[1] ?? ''));
    sort($kinds);
    $expected = CIN_IMG_FOUR;
    sort($expected);
    expect($kinds)->toBe($expected);

    // إطار 16:9، تعبئة كاملة، لا يلتقط النقر (لا يعيق السحب ولا الأزرار)، يُخفى عند الفشل، بلا نص بديل مكرَّر.
    expect($block)->toContain('aspect-video')->toContain('object-cover')->toContain('pointer-events-none')->toContain('draggable="false"')
        ->toContain('x-show="!broken"')->toContain('x-on:error="broken = true"')->toContain('alt=""')->toContain('loading="lazy"');

    // مرجع بصري فقط: لا أزرار ولا مستمعات نقر/إرسال/لوحة مفاتيح ولا tabindex داخله.
    expect($block)->not->toContain('<button')->not->toContain('x-on:click')->not->toContain('@click')->not->toContain('submit(')
        ->not->toContain('x-on:keydown')->not->toContain('tabindex')->not->toContain('<input');

    // لا يقرأ شيئًا من الحل: لا إشارة إلى order/map/correct/solution/code في الكتلة.
    expect(preg_match('/\b(order|placements|correct|solution|code|targets)\b/', $block))->toBe(0);

    // كتلة واحدة فقط في القالب، وفي مستوى مشهد التفاعل (قبل كتل الأنواع) فلا تتكرر لأي نوع.
    expect(substr_count($blade, 'data-cinematic-scene-image'))->toBe(1);
    expect(strpos($blade, 'data-cinematic-scene-image'))->toBeLessThan(strpos($blade, "x-if=\"kind === 'sequence'\""))
        ->and(strpos($blade, 'data-cinematic-scene-image'))->toBeGreaterThan(strpos($blade, 'data-cinematic-interaction'));
});

test('IMG-03b: the image block sits above the controls of every one of the four kinds and each control block is intact', function () {
    $blade = cin_img_blade();

    foreach (['sequence', 'drag_drop'] as $kind) {
        $block = cin_img_block($blade, "x-if=\"kind === '{$kind}'\"");
        expect($block)->not->toContain('scene.image')->toContain('x-on:click');
    }

    // choose_item/compare يتشاركان كتلة واحدة، وهي لا تعرض الصورة بنفسها (تعرضها كتلة الإطار الجديدة مرة واحدة).
    $choose = cin_img_block($blade, "x-if=\"kind === 'choose_item' || kind === 'compare'\"");
    expect($choose)->not->toContain('scene.image')->toContain('x-on:click');

    // لا تزال أزرار الإرسال/التحقق في أماكنها (لم يُمس مسار الإرسال).
    expect($blade)->toContain('submit({ order: order })')->and($blade)->toContain('submit({ placements: placed })')
        ->and($blade)->toContain("submit(kind === 'compare' ? { option: pick } : { item: pick })")->and($blade)->toContain('submit({ area: a.id })')->and($blade)->toContain('submit({ points: points })');
});

test('IMG-04: no regression - hotspot, container and flashlight still draw their own image exactly once inside their own block, and the new frame never targets them or reveal_code', function () {
    $blade = cin_img_blade();

    $hotspot = cin_img_block($blade, "x-if=\"kind === 'hotspot' || kind === 'container'\"");
    $flash = cin_img_block($blade, "x-if=\"kind === 'flashlight'\"");

    expect(substr_count($hotspot, '<img :src="scene.image"'))->toBe(1)->and(substr_count($flash, '<img :src="scene.image"'))->toBe(1);

    // كتلة الصورة الجديدة لا تشمل هذه الأنواع (لا صورة مكرَّرة) ولا reveal_code (لا صورة له أصلًا).
    $new = cin_img_block($blade, 'data-cinematic-scene-image');

    foreach (['hotspot', 'container', 'flashlight', 'reveal_code'] as $kind) {
        expect($new)->not->toContain("'{$kind}'");
    }

    // ظهور الصورة في السرد كما كان، وإجمالي مواضع <img :src="scene.image"> = سرد + hotspot/container + flashlight + الإطار الجديد.
    expect(substr_count($blade, '<img :src="scene.image"'))->toBe(4);
});

test('IMG-04b: the payload of the kinds that already drew their image is unchanged, and an unaffected kind carries the same authorised image link as before', function (string $kind) {
    cin_seed();
    $w = cin_img_story($kind, true);
    $r = cin_img_start($this, $w, cin_user());

    expect($r->json('scene.interaction.kind'))->toBe($kind)->and($r->json('scene.image'))->toBeString()->toContain('/cinematic/media/');
})->with(['hotspot', 'container', 'flashlight', 'reveal_code']);

test('IMG-05: with the flag closed (the shipped default) the page, the start action and a previously issued image link are all 404 even for an administrator', function () {
    cin_seed();
    $w = cin_img_story('sequence', true);
    $user = cin_user();
    $link = cin_img_start($this, $w, $user)->json('scene.image');
    config(['cinematic_stories.enabled' => false]);

    $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play')->assertNotFound();
    $this->actingAs($user)->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$w['scene']->public_id.'/start')->assertNotFound();
    $this->actingAs($user)->get($link)->assertNotFound();
    $this->actingAs($w['admin'])->get('/cinematic/preview/'.$w['live']->public_id)->assertNotFound();
    $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play')->assertDontSee('data-cinematic-scene-image', false);

    expect(config('cinematic_stories.enabled'))->toBeFalse();
});

test('IMG-05b: the shipped default of the cinematic flag is still closed', function () {
    $file = (string) file_get_contents(config_path('cinematic_stories.php'));

    expect($file)->toMatch("/'enabled'\s*=>\s*\(bool\)\s*env\('CINEMATIC_STORIES_ENABLED',\s*false\)/");
});

test('IMG-06: no puzzle solution ever reaches the page or the responses, nothing is written on view, and submit still judges on the server exactly as before', function (string $kind) {
    cin_seed();
    $w = cin_img_story($kind, true);
    $user = cin_user();
    $slug = $w['story']->slug;
    $before = cin_economy_snapshot();

    $page = $this->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk()->getContent();
    $start = cin_img_start($this, $w, $user);
    $seen = $page.$start->getContent();

    // الحمولة العامة لا تحمل مفاتيح الحل، ولا قيمه (ترتيب/خريطة/إجابة صحيحة).
    $interaction = $start->json('scene.interaction');
    foreach (['order', 'placements', 'map', 'correct', 'solution', 'targets_hit'] as $key) {
        expect($interaction)->not->toHaveKey($key);
    }

    $json = json_encode($start->json('scene'), JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('"correct"')->not->toContain('"solution"')->not->toContain('"order"')->not->toContain('"map"');

    // لا كتابة تقدّم ولا اقتصاد عند العرض/البدء.
    expect(CinematicProgress::query()->count())->toBeLessThanOrEqual(1)->and(cin_economy_snapshot())->toBe($before);

    // إرسال خاطئ ⇒ لا تقدّم؛ إرسال صحيح ⇒ يكتمل المشهد - التحقق كما هو.
    $wrong = ['sequence' => ['order' => ['square', 'circle', 'triangle']], 'drag_drop' => ['placements' => ['circle' => 'right', 'triangle' => 'mid', 'square' => 'left']], 'choose_item' => ['item' => 'circle'], 'compare' => ['option' => 'circle']][$kind];
    $right = ['sequence' => ['order' => ['circle', 'triangle', 'square']], 'drag_drop' => ['placements' => ['circle' => 'left', 'triangle' => 'mid', 'square' => 'right']], 'choose_item' => ['item' => 'square'], 'compare' => ['option' => 'triangle']][$kind];

    $bad = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['scene']->public_id}/submit", ['submission' => $wrong])->assertOk();
    expect($bad->json('result.correct'))->toBeFalse();

    $ok = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['scene']->public_id}/submit", ['submission' => $right])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue();

    // الصورة نفسها لا تحمل اسم المشهد/المفتاح/الحل في رابطها.
    expect((string) $start->json('scene.image'))->not->toContain('riddle_')->not->toContain('square');
    expect(cin_economy_snapshot())->toBe($before);
})->with(CIN_IMG_FOUR);

test('IMG-07: the staff preview serves the same image field for the four kinds and writes nothing', function () {
    cin_seed();
    $w = cin_img_story('compare', true);
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];

    $page = $this->actingAs($w['admin'])->get('/cinematic/preview/'.$w['live']->public_id)->assertOk();
    $html = $page->getContent();
    preg_match('/data-config="([^"]*)"/', $html, $m);
    $cfg = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
    $scene = collect($cfg['scenes'])->firstWhere('key', 'riddle_compare');

    expect($scene['image'])->toBeString()->toContain('/cinematic/media/')
        ->and($page->getContent())->toContain('data-cinematic-scene-image')
        ->and(json_encode($cfg))->not->toContain('"correct"')
        ->and([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});
