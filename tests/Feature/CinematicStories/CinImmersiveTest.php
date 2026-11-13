<?php

use App\Filament\Pages\CinematicBuilderPage;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicSceneState;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| CIN-1 IMMERSIVE PLAYER - اختبارات جديدة (لا تعدّل أي اختبار قديم ولا توقّفه). أرقام IMM-xx تقابل بنود الفحص في مهمة «المشغّل الغامر»:
|  IMM-01..03  الشاشة الغامرة (تخطيط بلا رأس/تذييل الموقع)، وجود الشاشة الكاملة الاختيارية دون الاعتماد عليها، الفيديو (حاسوب/جوال) ونسبته.
|  IMM-04..07  محاذاة المناطق، طبقات الواجهة وترتيبها، لوحة الخيارات، سلامة التفاعل.
|  IMM-08..12  صفحات الإدارة سليمة، لوحة المفاتيح واللمس، انتقالات المشاهد، فصل «معاينة الإدارة» عن «اللاعب» (خادميًا)، أمان المعاينة (لا تقدّم ولا مكافآت).
|  IMM-13..14  استجابة الجوال وعدم وجود تمرير أفقي (عقود الأنماط؛ الفحص البصري الفعلي جرى في متصفح حقيقي وهو موثّق في التقارير ولا يُدّعى هنا).
|  IMM-15..18  الفيديو الأول، مشهد الباب (النجاح بأسلوب القصة)، ربط الوسائط، حقل رسالة النجاح في المحرّر.
|  IMM-19..20  الصفحات العادية لم يتغير تخطيطها، وقوالب الغامر بلا x-html ولا innerHTML.
| لا شبكة ولا FFmpeg ولا متصفح هنا: هذه الاختبارات تفحص الاستجابات الخادمية وعقود القوالب والأنماط والسلوك الخادمي، لا الرسم الفعلي على الشاشة.
*/

beforeEach(fn () => cin_seed());

if (! function_exists('imm_world')) {
    /** @return array<string, mixed> قصة منشورة: فيديو جاهز ← باب (hotspot بصورة ورسالة نجاح) ← اختيار عنصر ← سرد ختامي */
    function imm_world(string $success = 'تحرّك المقبض ببطء، وانفتح الباب عن ممرّ مظلم.'): array
    {
        $w = cin_story();
        $film = cin_video_scene($w['chapter'], 'imm_film', ['title' => 'فيديو الوصول']);
        $door = cin_interaction($w['chapter'], 'imm_door', ['kind' => 'hotspot', 'prompt' => 'اضغط على مقبض الباب', 'alt_text' => 'باب خشبي', 'success_message' => $success, 'areas' => [['id' => 'handle', 'label' => 'مقبض الباب', 'x' => 62, 'y' => 48, 'w' => 8, 'h' => 8]]], ['correct' => ['handle']]);
        cin_authoring()->replaceMedia($door->fresh(), 'poster', cin_poster_asset($w['admin']));
        [$public, $solution] = cin_choose('b');
        $pick = cin_interaction($w['chapter'], 'imm_pick', $public, $solution);
        cin_narrative($w['chapter'], 'imm_end');
        $live = cin_publish($w['revision']->fresh(), $w['admin']);

        return $w + ['live' => $live, 'film' => $live->scenes()->where('stable_key', 'imm_film')->first(), 'door' => $live->scenes()->where('stable_key', 'imm_door')->first(), 'pick' => $live->scenes()->where('stable_key', 'imm_pick')->first(), 'film_draft' => $film];
    }

    function imm_cfg(string $html): array
    {
        preg_match('/data-config="([^"]*)"/', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true) ?: [];
    }

    function imm_file(string $relative): string
    {
        return (string) file_get_contents(resource_path('views/'.$relative));
    }

    /** يقدّم اللاعب إلى المشهد المطلوب (الفيديو ثم الباب ...). */
    function imm_advance_to_door($test, array $w, $user): void
    {
        $slug = $w['story']->slug;
        $test->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/start")->assertOk();
        $test->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", ['mode' => 'watched'])->assertOk();
        $test->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/start")->assertOk();
    }
}

// ───────────────────────────── 1) الشاشة الغامرة ─────────────────────────────

test('IMM-01: the real player page is an immersive screen - game layers, no site header / footer / bottom bar / floating assistant, but the skip link and main#main-content stay for accessibility', function () {
    $w = imm_world();
    $user = cin_user();
    $html = $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play')->assertOk()->getContent();

    expect($html)->toContain('cin-immersive-body')->toContain('viewport-fit=cover')->toContain('data-cinematic-player')->toContain('imm-root')->toContain('data-imm-hud')->toContain('imm-backdrop')
        ->toContain('imm-stage')->toContain('imm-paper')->toContain('imm-veil')->toContain('data-skip-link')->toContain('id="main-content"');
    expect($html)->not->toContain('app-footer')->not->toContain('has-bottom-nav')->not->toContain('aurora-bg');
    // أول عنصر قابل للتركيز يبقى رابط التخطّي (قبل أي أزرار اللعب).
    expect(strpos($html, 'data-skip-link'))->toBeLessThan(strpos($html, 'data-imm-hud'));
    // عنوان h1 واحد في كل طور (الشريط العلوي في طور المشهد، وبطاقة الطور في غيره): الطورات متبادلة داخل x-if فلا يظهر h1 ثانٍ معًا.
    expect(substr_count($html, '<h1'))->toBe(2)->and($html)->toContain('x-ref="heading"')->and($html)->toContain("x-if=\"phase === 'scene' && scene\"");
});

test('IMM-02: the real Fullscreen API is optional - a button with feature detection and a webkit fallback, while the immersive layout itself never depends on it (fixed full-viewport root, dynamic-viewport units for the sheets)', function () {
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $play = imm_file('cinematic/play.blade.php');

    expect($play)->toContain('data-imm-fullscreen')->toContain('x-show="fsSupported"');
    expect($scripts)->toContain('requestFullscreen')->toContain('webkitRequestFullscreen')->toContain('exitFullscreen')->toContain('fullscreenEnabled')->toContain('fsSupported');
    expect($play)->toContain('x-on:fullscreenchange.document="syncFs()"')->toContain('x-on:webkitfullscreenchange.document="syncFs()"');
    // الغامر يعمل دون API: جذر ثابت يملأ النافذة + وحدات dvh.
    expect($styles)->toMatch('/\.imm-root[^{]*\{[^}]*position:\s*fixed;\s*inset:\s*0/s')->toContain('dvh');
});

test('IMM-03: a video scene fills the stage by the clip\'s real ratio (landscape or portrait), with inline playback for phones, a clear play button, ended overlay and an alt-text sheet; the stage never crops it', function () {
    $w = imm_world();
    $play = imm_file('cinematic/play.blade.php');
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');

    expect($play)->toContain('playsinline')->toContain('x-on:loadedmetadata="fitVideo($event)"')->toContain('data-cinematic-playoverlay')->toContain('data-cinematic-replay')->toContain('data-imm-transcript-sheet')
        ->toContain('data-cinematic-video-frame');
    expect($scripts)->toContain('fitVideo(ev)')->toContain('--vr')->toContain('videoWidth');
    expect($styles)->toContain('--vr: 1.7778')->toContain('aspect-ratio: var(--vr)')->toContain('calc(100cqh * var(--vr))')->toContain('object-fit: contain')->toContain('container-type: size');

    // الخادم: مشهد الفيديو الجاهز يصل بحالة ready ورابط وسائط موقَّع، دون أي تغيير في العقد.
    $user = cin_user();
    $this->actingAs($user);
    $started = $this->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$w['film']->public_id.'/start')->assertOk();
    expect($started->json('scene.video.state'))->toBe('ready')->and($started->json('scene.video.src'))->toContain('/cinematic/media/');
});

// ───────────────────────────── 2) المناطق، الطبقات، الخيارات ─────────────────────────────

test('IMM-04: hotspot alignment is by the real image ratio - the stage takes the image\'s natural ratio inside the container, the click maths and area boxes use the same box, and areas are never outlined for players', function () {
    $play = imm_file('cinematic/play.blade.php');
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');

    expect($play)->toContain('naturalWidth / img.naturalHeight')->toContain('object-fit: fill')->toContain('stageClick($event)')->toContain('imgLoaded($event)')->toContain('Math.max(0, (44 - w) / 2)');
    expect($styles)->toContain('width: min(100cqw, calc(100cqh * var(--r, 1.7778)))')->toContain('.imm-stage .cin-stage');
    // لا حدود دائمة للاعب: الحدود المنقّطة للمناطق لا تُرسم إلا بلا صورة، ومخططات الإدارة خلف مفتاح «إظهار مناطق النقر».
    expect($play)->toContain('<template x-if="!scene.image">')->toContain('data-imm-zones-toggle')->toContain('showZones');
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');
    expect($scripts)->toContain("this.showZones = this.adminTools && store.get('cin-imm-zones') === '1'");
});

test('IMM-05: the admin tools are one collapsible drawer, closed by default and remembered per browser, with Escape to close - they never cover the scene until opened', function () {
    $w = imm_world();
    $this->actingAs($w['admin']);
    $html = $this->get(route('cinematic.preview', ['revision' => $w['live']->public_id, 'scene' => $w['door']->public_id]))->assertOk()->getContent();
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');

    expect($html)->toContain('data-imm-admin')->toContain('data-imm-admin-toggle')->toContain('data-cinematic-preview-banner')->toContain('x-show="adminOpen"')->toContain('data-imm-diagnostics')->toContain('data-preview-picker');
    expect($scripts)->toContain('adminOpen: false')->toContain("store.get('cin-imm-admin') === '1'")->toContain("ev.key === 'Escape' && this.adminOpen")->toContain('try {');
});

test('IMM-06: the layers keep a stable order - backdrop under stage under paper under floating feedback under the HUD under the admin drawer and the transition veil - and safe areas are respected', function () {
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $z = function (string $selector) use ($styles): int {
        preg_match('/\[data-cinematic-player\]\s+'.preg_quote($selector, '/').'\s*\{[^}]*z-index:\s*(\d+)/', $styles, $m);

        return (int) ($m[1] ?? -1);
    };

    expect($z('.imm-paper'))->toBeGreaterThan(0)->and($z('.imm-float'))->toBeGreaterThan($z('.imm-paper'))->and($z('.imm-hud'))->toBeGreaterThan($z('.imm-float'))
        ->and($z('.imm-admin'))->toBeGreaterThan($z('.imm-hud'))->and($z('.imm-veil'))->toBeGreaterThan($z('.imm-admin'));
    expect($styles)->toContain('env(safe-area-inset-top')->toContain('env(safe-area-inset-bottom')->toContain('env(safe-area-inset-left')->toContain('env(safe-area-inset-right');
    // الرسائل على صورة «الضغط على الصورة» تطفو فوق المسرح في الشاشات الضيقة لئلا تكبر الورقة السفلية فتتحرك الصورة تحت إصبع اللاعب.
    expect($styles)->toContain('.imm-float[data-imm-for="stage"]')->toContain('not all and (min-width: 721px) and (orientation: landscape)');
    expect(imm_file('cinematic/play.blade.php'))->toContain(":data-imm-for=\"layoutKind\"");
});

test('IMM-07: choice scenes use an in-world paper - a side column beside the art on wide landscape screens and a bottom sheet on phones - and the server contract of the choice is unchanged', function () {
    $w = imm_world();
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $play = imm_file('cinematic/play.blade.php');

    expect($play)->toContain('class="imm-paper"')->toContain('data-imm-layout')->toContain('imm-choice')->toContain('submit(kind === \'compare\' ? { option: pick } : { item: pick })');
    expect($styles)->toContain('[data-imm-layout="paper"] { grid-template-columns: min(26rem, 38vw) minmax(0, 1fr)')->toContain('(min-width: 721px) and (orientation: landscape)')->toContain('max-height: min(56dvh, 31rem)');

    $user = cin_user();
    imm_advance_to_door($this, $w, $user);
    $slug = $w['story']->slug;
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'handle']])->assertOk();
    $start = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['pick']->public_id}/start")->assertOk();
    expect($start->json('scene.interaction.kind'))->toBe('choose_item')->and($start->json('scene.interaction'))->not->toHaveKey('correct')->and($start->getContent())->not->toContain('"correct":"');
    $wrong = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['pick']->public_id}/submit", ['submission' => ['item' => 'a']])->assertOk();
    expect($wrong->json('result.correct'))->toBeFalse()->and($wrong->json('scene'))->toBeNull();
});

// ───────────────────────────── 3) الإدارة، لوحة المفاتيح، الانتقالات ─────────────────────────────

test('IMM-08: the admin pages are unharmed and gain the new links - builder, stories list and media library open, with player-like preview links and the success / failure message fields on the interaction itself', function () {
    $w = imm_world();
    $draft = cin_story(['admin' => $w['admin']]);
    $door = cin_interaction($draft['chapter'], 'imm_builder_door', ['kind' => 'hotspot', 'prompt' => 'اضغط', 'areas' => [['id' => 'handle', 'label' => 'المقبض', 'x' => 60, 'y' => 45, 'w' => 10, 'h' => 10]]], ['correct' => ['handle']]);
    cin_authoring()->replaceMedia($door->fresh(), 'poster', cin_poster_asset($w['admin']));
    $this->actingAs($w['admin']);

    $builder = $this->get(CinematicBuilderPage::getUrl(['story' => $draft['story']->public_id, 'scene' => $door->public_id]))->assertOk()->getContent();
    expect($builder)->toContain('data-scene-editor')->toContain('data-player-preview-all')->toContain('data-scene-player-preview-link')->toContain('mode=player')->toContain('data-ix-success-message')->toContain('data-ix-wrong-message')->toContain('data-calibrator="areas"');
    $this->get('/admin/cinematic-stories')->assertOk();
    $this->get('/admin/cinematic-media')->assertOk();
    $this->get('/admin/cinematic-builder?story='.$w['story']->public_id)->assertOk();
    $this->get(CinematicBuilderPage::getUrl(['story' => $w['story']->public_id]))->assertOk()->assertSee('data-player-preview-all', false);
});

test('IMM-09: keyboard and touch - every area has an alternative button list, the keyboard handler exists, touch targets are at least 2.5rem, and the stage opts out of browser gestures', function () {
    $play = imm_file('cinematic/play.blade.php');
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');

    expect($play)->toContain('المناطق (بديل لوحة المفاتيح وقارئ الشاشة)')->toContain('class="imm-kbd"')->toContain('touch-none')->toContain('data-imm-transcript-toggle')->toContain('data-cinematic-skip');
    expect($scripts)->toContain('onKey(ev)')->toContain("ev.code === 'KeyF'")->toContain("ev.key === 'Escape'");
    expect($styles)->toContain('.imm-btn { ')->toContain('min-height: 2.75rem')->toContain('.imm-btn-sm { min-height: 2.5rem')->toContain('focus-visible');
});

test('IMM-10: scene transitions are a short veil that is skipped for people who prefer reduced motion, and auto-advance is conservative (never in the admin view, never when a code is revealed, only for short story messages)', function () {
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');

    expect($styles)->toContain('.imm-veil')->toContain('prefers-reduced-motion: reduce');
    expect($scripts)->toContain('continueScene(mode)')->toContain('scheduleAuto')->toContain('cancelAuto');
    expect($scripts)->toContain('this.adminTools || reduced() || !this.isAreaKind || this.revealed')->toContain('90');
});

test('IMM-11: admin preview and player are separated on the SERVER - a real player and the player-like preview carry no admin DOM at all, while the staff preview carries the collapsible tools; non-staff cannot open any preview', function () {
    $w = imm_world();
    $user = cin_user();
    $adminDom = ['data-imm-admin', 'data-imm-admin-toggle', 'data-imm-prev', 'data-imm-next', 'data-cinematic-preview-banner', 'data-imm-zones-toggle', 'data-preview-picker', 'data-imm-mode-player'];

    $real = $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play')->assertOk()->getContent();
    foreach ($adminDom as $needle) {
        expect($real)->not->toContain($needle);
    }
    expect(imm_cfg($real)['mode'])->toBe('play');

    $this->flushSession();
    $this->actingAs($w['admin']);
    $adminHtml = $this->get(route('cinematic.preview', ['revision' => $w['live']->public_id, 'scene' => $w['door']->public_id]))->assertOk()->getContent();
    foreach (['data-imm-admin', 'data-imm-admin-toggle', 'data-cinematic-preview-banner', 'data-imm-mode-player'] as $needle) {
        expect($adminHtml)->toContain($needle);
    }
    expect(imm_cfg($adminHtml)['view'])->toBe('admin')->and(imm_cfg($adminHtml)['mode'])->toBe('preview');

    $likeHtml = $this->get(route('cinematic.preview', ['revision' => $w['live']->public_id, 'scene' => $w['door']->public_id, 'mode' => 'player']))->assertOk()->getContent();
    foreach ($adminDom as $needle) {
        expect($likeHtml)->not->toContain($needle);
    }
    expect($likeHtml)->toContain('data-imm-exit-player-like');
    expect(imm_cfg($likeHtml)['view'])->toBe('player')->and(imm_cfg($likeHtml)['mode'])->toBe('preview')->and(imm_cfg($likeHtml)['start_scene'])->toBe($w['door']->public_id);

    // الغريب (لاعب عادي) لا يفتح أي معاينة، ولا يمكنه فرض وضع المعاينة بوسيط في العنوان.
    $this->flushSession();
    $this->actingAs($user)->get(route('cinematic.preview', ['revision' => $w['live']->public_id, 'mode' => 'player']))->assertForbidden();
    $this->flushSession();
    $realWithMode = $this->actingAs($user)->get('/cinematic/'.$w['story']->slug.'/play?mode=admin&view=admin&preview=1')->assertOk()->getContent();
    foreach ($adminDom as $needle) {
        expect($realWithMode)->not->toContain($needle);
    }
    expect(imm_cfg($realWithMode)['mode'])->toBe('play');
});

test('IMM-12: preview - in the admin view and in the player-like view - never writes progress, scene state or any economy table, and never grants a reward; real play is untouched by it', function () {
    $w = imm_world();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];
    $this->actingAs($w['admin']);

    foreach ([false, true] as $asPlayer) {
        $url = route('cinematic.preview', array_filter(['revision' => $w['live']->public_id, 'scene' => $w['door']->public_id, 'mode' => $asPlayer ? 'player' : null]));
        $this->get($url)->assertOk();
        $ok = $this->postJson(route('cinematic.preview.submit', [$w['live']->public_id, $w['door']->public_id]), ['submission' => ['area' => 'handle']])->assertOk();
        expect($ok->json('result.correct'))->toBeTrue();
        $no = $this->postJson(route('cinematic.preview.submit', [$w['live']->public_id, $w['door']->public_id]), ['submission' => ['area' => 'elsewhere']])->assertOk();
        expect($no->json('result.correct'))->toBeFalse();
    }

    expect([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});

// ───────────────────────────── 4) الجوال والتمرير ─────────────────────────────

test('IMM-13: phone responsiveness is built in - portrait bottom sheet, short-landscape and tiny-screen rules, a wide-screen rule, a rotate hint for videos in portrait, and a centred card for scenes without art', function () {
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');

    expect($styles)->toContain('@media (orientation: portrait)')->toContain('max-height: 520px')->toContain('max-width: 380px')->toContain('min-width: 1400px')->toContain('.imm-hint-rotate')
        ->toContain('[data-imm-layout="paper"][data-imm-art="no"]');
    expect(imm_file('cinematic/play.blade.php'))->toContain('أدر الجهاز أفقيًا');
});

test('IMM-14: no horizontal overflow by construction - the immersive body never scrolls, the root clips, text wraps, and nothing uses a fixed width wider than a 360px phone', function () {
    $styles = imm_file('cinematic/partials/immersive-styles.blade.php');

    expect($styles)->toContain('body.cin-immersive-body { margin: 0; overflow: hidden;')->toMatch('/\.imm-root[^{]*\{[^}]*overflow:\s*hidden/s')->toContain('overflow-wrap: anywhere');
    preg_match_all('/(?<![\w-])width:\s*(\d+(?:\.\d+)?)(px|rem)/', $styles, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $px = $row[2] === 'rem' ? (float) $row[1] * 16 : (float) $row[1];
        expect($px)->toBeLessThan(360.0, 'عرض ثابت أكبر من شاشة 360px: '.$row[0]);
    }
});

// ───────────────────────────── 5) V01 والباب والوسائط والمحرّر ─────────────────────────────

test('IMM-15: the first video scene plays through the server unchanged - start, watched, then the next scene opens - and the page opens on it', function () {
    $w = imm_world();
    $user = cin_user();
    $slug = $w['story']->slug;

    $page = $this->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk()->getContent();
    expect(imm_cfg($page)['state']['current'])->toBe($w['film']->public_id);

    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/start")->assertOk();
    $done = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", ['mode' => 'watched'])->assertOk();
    expect($done->json('scene.key'))->toBe('imm_door');
});

test('IMM-16: the door interaction - the right area completes it and shows the author\'s story message instead of "correct answer!", a wrong area completes nothing, and no response carries the solution', function () {
    $message = 'تحرّك المقبض ببطء، وانفتح الباب عن ممرّ مظلم.';
    $w = imm_world($message);
    $user = cin_user();
    $slug = $w['story']->slug;
    imm_advance_to_door($this, $w, $user);

    $bad = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'nowhere']])->assertOk();
    expect($bad->json('result.correct'))->toBeFalse()->and($bad->json('scene'))->toBeNull();
    $good = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/submit", ['submission' => ['area' => 'handle']])->assertOk();
    expect($good->json('result.correct'))->toBeTrue()->and($good->json('result.message'))->toBe($message)->and($good->json('scene.key'))->toBe('imm_pick');

    // العرض: عبارات النجاح القصصية الاحتياطية موجودة للأنواع، والنص الافتراضي المباشر لا يُعرض لمشاهد الباب.
    $scripts = imm_file('cinematic/partials/immersive-scripts.blade.php');
    expect($scripts)->toContain('OK_FALLBACK')->toContain('أصبتَ الموضع… ينفتح الطريق أمامك.')->toContain('لمستَ «')->toContain('lastLabel')->toContain('get resultText()')->toContain('get resultStory()');
    expect(imm_file('cinematic/partials/immersive-feedback.blade.php'))->toContain('x-text="resultText"')->toContain('is-story');

    $joined = $bad->getContent().$good->getContent();
    expect($joined)->not->toContain('"correct":["')->not->toContain('"solution"');
    expect($this->actingAs($user)->get("/cinematic/{$slug}/state")->assertOk()->getContent())->not->toContain('"correct":["');
});

test('IMM-17: media linkage still works through the immersive player - the linked poster reaches the door scene as the stage image and the linked video as the video source, in real play and in the player-like preview', function () {
    $w = imm_world();
    $user = cin_user();
    $slug = $w['story']->slug;
    imm_advance_to_door($this, $w, $user);

    $door = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['door']->public_id}/start")->assertOk();
    expect($door->json('scene.image'))->toContain('/cinematic/media/')->and($door->json('scene.interaction.areas.0.id'))->toBe('handle');

    $this->flushSession();
    $this->actingAs($w['admin']);
    $cfg = imm_cfg($this->get(route('cinematic.preview', ['revision' => $w['live']->public_id, 'scene' => $w['door']->public_id, 'mode' => 'player']))->assertOk()->getContent());
    expect($cfg['scenes'][$w['door']->public_id]['image'])->toContain('/cinematic/media/')->and($cfg['scenes'][$w['film']->public_id]['video']['src'])->toContain('/cinematic/media/')
        ->and($cfg['scenes'][$w['film']->public_id]['video']['state'])->toBe('ready');
});

test('IMM-18: the editor writes the success / failure message on the interaction itself and the player receives it - the old top-level pair is only for classic puzzles', function () {
    $w = imm_world();
    $this->actingAs($w['admin']);
    $draft = cin_story();
    $door = cin_interaction($draft['chapter'], 'imm_edit_door', ['kind' => 'hotspot', 'prompt' => 'اضغط', 'areas' => [['id' => 'handle', 'label' => 'المقبض', 'x' => 60, 'y' => 45, 'w' => 10, 'h' => 10]]], ['correct' => ['handle']]);

    $c = Livewire::withQueryParams(['story' => $draft['story']->public_id])->test(CinematicBuilderPage::class)->call('editScene', $door->public_id);
    $c->set('sf.ix.success_message', 'انفتح الباب بصرير طويل.')->set('sf.ix.wrong_message', 'لا شيء هنا…')->call('saveScene');

    $fresh = $door->fresh();
    expect($fresh->config['interaction']['success_message'])->toBe('انفتح الباب بصرير طويل.')->and($fresh->config['interaction']['wrong_message'])->toBe('لا شيء هنا…');
    expect($c->get('sf.ix.success_message'))->toBe('انفتح الباب بصرير طويل.');

    $eval = cin_play()->evaluateWith($fresh, ['area' => 'handle'], []);
    expect($eval[0])->toBeTrue()->and($eval[2])->toBe('انفتح الباب بصرير طويل.');
    $miss = cin_play()->evaluateWith($fresh, ['area' => 'x'], []);
    expect($miss[0])->toBeFalse()->and($miss[2])->toBe('لا شيء هنا…');

    $builder = imm_file('filament/pages/cinematic-builder.blade.php');
    expect($builder)->toContain('wire:model="sf.ix.success_message"')->toContain('wire:model="sf.ix.wrong_message"');
});

// ───────────────────────────── 6) سلامة بقية الموقع ─────────────────────────────

test('IMM-19: ordinary pages keep their normal layout - the stories list and the story page still render the site header, footer and bottom bar, and never the immersive body class', function () {
    $w = imm_world();
    $user = cin_user();

    foreach (['/cinematic', '/cinematic/'.$w['story']->slug] as $path) {
        $html = $this->actingAs($user)->get($path)->assertOk()->getContent();
        expect($html)->toContain('app-footer')->toContain('aurora-bg')->not->toContain('cin-immersive-body')->not->toContain('viewport-fit=cover')->toContain('id="main-content"')->toContain('data-skip-link');
        $this->flushSession();
    }
    $layout = imm_file('layouts/app.blade.php');
    expect($layout)->toContain("\$immersive = \$__env->hasSection('immersive')")->toContain('@unless ($immersive)');
});

test('IMM-20: the immersive templates never render raw HTML - no x-html, v-html, innerHTML or unescaped output anywhere in the player or its partials', function () {
    foreach (['cinematic/play.blade.php', 'cinematic/partials/immersive-styles.blade.php', 'cinematic/partials/immersive-scripts.blade.php', 'cinematic/partials/immersive-feedback.blade.php'] as $file) {
        $src = imm_file($file);
        expect($src)->not->toContain('x-html')->not->toContain('v-html')->not->toContain('innerHTML')->not->toContain('{!!')->not->toContain('arrival_door')->not->toContain('door_handle')->not->toContain('PLM_S01');
    }
});
