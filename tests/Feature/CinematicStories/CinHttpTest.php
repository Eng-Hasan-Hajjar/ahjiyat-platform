<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicSceneState;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../CreatorPremiumE43/_support.php';

/*
| CIN-1 - طبقة HTTP: العلم المغلق ⇒ 404، الضيف، فرض قرارات الخادم عبر الطلبات (لا تخطي ولا ادّعاء)، حماية الحل في كل استجابة،
| القصة المميزة بملكية E43 الحقيقية، الوسائط الموقَّعة (Range/انتحال/انتهاء/مستخدم آخر/ملف غريب)، رفع الوسائط، المعاينة الإشرافية.
*/

beforeEach(fn () => cin_seed());

/** قصة منشورة: فيديو (بوسيط حقيقي وقابل للتخطي) ← سرد ← تفاعل بحل سري مميز ← كشف رمز مشروط. @return array<string, mixed> */
function cin_live(?array $premium = null): array
{
    $w = cin_story($premium ? ['premium_series' => $premium['series']] : []);
    $film = cin_video_scene($w['chapter'], 'film', ['is_skippable' => true, 'config' => ['transcript' => [['speaker' => 'ياسمين', 'text' => 'حوار المشهد']]]]);
    cin_authoring()->replaceMedia($film->fresh(), 'poster', cin_poster_asset());
    $story = cin_narrative($w['chapter'], 'story_beat');
    $riddle = cin_interaction($w['chapter'], 'riddle_seq', ['kind' => 'sequence', 'prompt' => 'رتّب', 'items' => [['id' => 'sq1', 'label' => 'أول'], ['id' => 'sq2', 'label' => 'ثانٍ'], ['id' => 'sq3', 'label' => 'ثالث']]], ['order' => ['sq3', 'sq1', 'sq2']]);
    $code = cin_interaction($w['chapter'], 'code_reveal', ['kind' => 'reveal_code', 'prompt' => 'الرمز'], ['code' => 'SECRET-7421', 'requires_scenes' => ['riddle_seq']]);
    $live = cin_publish($w['revision']);

    return $w + ['film' => $film->fresh(), 'beat' => $story->fresh(), 'riddle' => $riddle->fresh(), 'code' => $code->fresh(), 'live' => $live];
}

function cin_cfg(string $html): array
{
    preg_match('/data-config="([^"]*)"/', $html, $m);

    return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true) ?: [];
}

test('HTTP-01: with the flag closed (the shipped default) every cinematic route is a 404 - public pages, JSON actions, media, upload and preview - even for an administrator', function () {
    $w = cin_live();
    $admin = $w['admin'];
    $user = cin_user();
    $sceneId = $w['beat']->public_id;
    config(['cinematic_stories.enabled' => false]);

    foreach (['/cinematic', '/cinematic/'.$w['story']->slug, '/cinematic/'.$w['story']->slug.'/play', '/cinematic/'.$w['story']->slug.'/state', '/cinematic/preview/'.$w['live']->public_id] as $path) {
        $this->actingAs($admin)->get($path)->assertNotFound();
    }

    $this->actingAs($user)->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$sceneId.'/start')->assertNotFound();
    $this->actingAs($user)->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$sceneId.'/finish', ['mode' => 'read'])->assertNotFound();
    $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => new UploadedFile(cin_tiny_png(), 'p.png', 'image/png', null, true)])->assertNotFound();
    $this->actingAs($user)->get(app(CinematicMediaLinks::class)->url($w['film']->video, 'scene', $w['film']->public_id, $user))->assertNotFound();

    // لا حذف: كل شيء ما زال في القاعدة، وعند فتح العلم تعود الصفحات.
    expect($w['story']->fresh()->revisions()->count())->toBe(1);
    config(['cinematic_stories.enabled' => true]);
    $this->get('/cinematic')->assertOk();
});

test('HTTP-02: guests can browse the list and the story page (no scenes, no media, no solutions) but cannot play or act; unpublished and archived stories do not exist publicly', function () {
    $w = cin_live();
    $draft = cin_story(['title' => 'مسودة سرّية']);

    $list = $this->get('/cinematic')->assertOk()->assertSee($w['story']->title)->assertDontSee('مسودة سرّية');
    expect($list->getContent())->not->toContain('riddle_seq')->not->toContain('SECRET-7421');

    $page = $this->get('/cinematic/'.$w['story']->slug)->assertOk()->assertSee('سجّل الدخول');
    expect($page->getContent())->not->toContain('SECRET-7421')->not->toContain('data-config')->not->toContain('story_beat');

    $this->get('/cinematic/'.$draft['story']->slug)->assertNotFound();
    $this->get('/cinematic/'.$w['story']->slug.'/play')->assertRedirect(route('login'));
    $this->getJson('/cinematic/'.$w['story']->slug.'/state')->assertUnauthorized();
    $this->postJson('/cinematic/'.$w['story']->slug.'/scenes/'.$w['beat']->public_id.'/finish', ['mode' => 'read'])->assertUnauthorized();
    $this->get('/cinematic/preview/'.$w['live']->public_id)->assertRedirect(route('login'));

    cin_authoring()->setArchived($w['story'], true);
    $this->get('/cinematic/'.$w['story']->slug)->assertNotFound();
    $this->get('/cinematic')->assertDontSee($w['story']->title);
    expect(CinematicProgress::query()->count())->toBe(0);   // لا GET كتب أي تقدّم
});

test('HTTP-03: a full play-through over HTTP - the server decides every step, a wrong answer advances nothing, and no response ever contains the solution or the code before the condition holds', function () {
    $w = cin_live();
    $user = cin_user();
    $slug = $w['story']->slug;
    $before = cin_economy_snapshot();

    $play = $this->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk()->assertSee('data-cinematic-player', false);
    $cfg = cin_cfg($play->getContent());
    expect($cfg['mode'])->toBe('play')->and($cfg['state']['current'])->toBe($w['film']->public_id)->and($cfg['urls']['finish'])->toContain('__SCENE__')
        ->and(CinematicProgress::query()->count())->toBe(0);

    $seen = [$play->getContent(), json_encode($cfg)];

    // فيديو قابل للتخطي: التخطي مسموح، ثم يتقدّم المسار.
    $r = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/start")->assertOk();
    expect($r->json('scene.video.state'))->toBe('ready')->and($r->json('scene.video.src'))->toContain('/cinematic/media/')->and($r->json('scene.image'))->toBeNull();
    $seen[] = $r->getContent();

    $r = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", ['mode' => 'skipped'])->assertOk();
    $seen[] = $r->getContent();
    expect($r->json('scene.key'))->toBe('story_beat');

    $r = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['beat']->public_id}/finish", ['mode' => 'read'])->assertOk();
    $seen[] = $r->getContent();
    expect($r->json('scene.key'))->toBe('riddle_seq')->and($r->json('scene.interaction.kind'))->toBe('sequence')->and($r->json('scene.interaction'))->not->toHaveKey('order')->not->toHaveKey('correct');

    // إجابة خاطئة: لا تقدّم، لا مشهد تالٍ.
    $wrong = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['riddle']->public_id}/submit", ['submission' => ['order' => ['sq1', 'sq2', 'sq3']]])->assertOk();
    $seen[] = $wrong->getContent();
    expect($wrong->json('result.correct'))->toBeFalse()->and($wrong->json('scene'))->toBeNull();

    // الرمز مقفل قبل حل اللغز (لا يُكشف ولا يُسرَّب).
    $locked = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['code']->public_id}/submit", ['submission' => ['confirm' => true]]);
    $seen[] = $locked->getContent();
    expect($locked->status())->toBe(403)->and($locked->json('reason'))->toBe('scene_locked');

    $right = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['riddle']->public_id}/submit", ['submission' => ['order' => ['sq3', 'sq1', 'sq2']]])->assertOk();
    $seen[] = $right->getContent();
    expect($right->json('result.correct'))->toBeTrue()->and($right->json('scene.key'))->toBe('code_reveal');

    $reveal = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['code']->public_id}/submit", ['submission' => ['confirm' => true]])->assertOk();
    expect($reveal->json('result.reveal'))->toBe('SECRET-7421')->and($reveal->json('state.progress.status'))->toBe('completed')->and($reveal->json('state.progress.percent'))->toBe(100);

    // السر لم يظهر في أي استجابة قبل أن يُستحق، ولا الحل في أي استجابة أبدًا.
    $joined = implode("\n", $seen);
    expect($joined)->not->toContain('SECRET-7421')->not->toContain('"order":["sq3"')->not->toContain('"correct":"')->not->toContain('requires_scenes');
    expect(cin_economy_snapshot())->toBe($before);

    // الاستئناف: تحميل المشغّل بعد الإكمال يعرض 100% وصفحة القصة تقرأ التقدّم.
    $this->actingAs($user)->get("/cinematic/{$slug}")->assertOk()->assertSee('شاهد من جديد');
});

test('HTTP-04: the server refuses every shortcut over HTTP - locked scenes, wrong modes, invalid payloads, scenes of another story, and a "watched" claim without real time', function () {
    $w = cin_live();
    $other = cin_live();
    $user = cin_user();
    $slug = $w['story']->slug;

    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['riddle']->public_id}/finish", ['mode' => 'read'])->assertForbidden()->assertJsonPath('reason', 'scene_locked');
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['riddle']->public_id}/submit", ['submission' => ['order' => ['sq3', 'sq1', 'sq2']]])->assertForbidden()->assertJsonPath('reason', 'scene_locked');
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", ['mode' => 'hacked'])->assertUnprocessable();
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", [])->assertUnprocessable();
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/submit", ['submission' => ['x' => 1]])->assertUnprocessable()->assertJsonPath('reason', 'mode_not_allowed');
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/submit", [])->assertUnprocessable();
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/submit", ['submission' => ['blob' => str_repeat('x', 9000)]])->assertUnprocessable()->assertJsonPath('reason', 'too_large');

    // مشهد قصة أخرى عبر مسار هذه القصة ⇒ غير موجود (لا تلاعب بالمعرّفات).
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$other['film']->public_id}/start")->assertNotFound();
    $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/not-a-ulid/start")->assertNotFound();
    $this->actingAs($user)->getJson('/cinematic/does-not-exist/state')->assertNotFound();

    // «شاهدت» بلا مرور وقت: مرفوض (فيديو بمدة طويلة).
    cin_authoring()->replaceMedia($other['film'], 'video', cin_long_video_asset(80000));
    cin_publishing()->unpublish($other['live']->fresh());
    $v2 = cin_publish(cin_authoring()->createDraft($other['story']));
    $film2 = $v2->scenes()->where('stable_key', 'film')->first();
    $this->actingAs($user)->postJson("/cinematic/{$other['story']->slug}/scenes/{$film2->public_id}/start")->assertOk();
    $this->actingAs($user)->postJson("/cinematic/{$other['story']->slug}/scenes/{$film2->public_id}/finish", ['mode' => 'watched'])->assertUnprocessable()->assertJsonPath('reason', 'too_early');
    expect(CinematicSceneState::query()->whereNotNull('completed_at')->count())->toBe(0);
});

test('HTTP-05: a premium story shows only its cover and summary to anyone without E43 ownership, and opens fully for a real buyer', function () {
    e43_seed(['cinematic_stories.enabled' => true]);
    $world = e43_world();
    $w = cin_live(['series' => $world['series']]);
    $slug = $w['story']->slug;
    $stranger = cin_user();

    $page = $this->actingAs($stranger)->get("/cinematic/{$slug}")->assertOk()->assertSee('data-cinematic-locked', false);
    expect($page->getContent())->not->toContain('data-config')->not->toContain('المرحلة الأولى');

    $this->actingAs($stranger)->get("/cinematic/{$slug}/play")->assertRedirect(route('cinematic.show', $slug));
    $this->actingAs($stranger)->getJson("/cinematic/{$slug}/state")->assertForbidden()->assertJsonPath('reason', 'premium_locked');
    $this->actingAs($stranger)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/start")->assertForbidden();
    $this->actingAs($stranger)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/finish", ['mode' => 'skipped'])->assertForbidden();
    expect(CinematicProgress::query()->count())->toBe(0);

    // رابط وسيط موقَّع لمستخدم لا يملك الوصول لا يعمل (الرابط وحده لا يكفي).
    $link = app(CinematicMediaLinks::class)->url($w['film']->video, 'scene', $w['film']->public_id, $stranger);
    $this->actingAs($stranger)->get($link)->assertForbidden();

    $buyer = e43_buy($world)['user'];
    $this->actingAs($buyer)->get("/cinematic/{$slug}/play")->assertOk();
    $r = $this->actingAs($buyer)->postJson("/cinematic/{$slug}/scenes/{$w['film']->public_id}/start")->assertOk();
    $media = $this->actingAs($buyer)->get($r->json('scene.video.src'))->assertOk();
    expect($media->headers->get('Cache-Control'))->toContain('no-store')->and($media->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('HTTP-06: signed media links - Range streaming works; tampering, expiry, another account, a guest, a foreign file and an unpublished scene are all refused', function () {
    $w = cin_live();
    $user = cin_user();
    $links = app(CinematicMediaLinks::class);
    $link = $links->url($w['film']->video, 'scene', $w['film']->public_id, $user);

    $full = $this->actingAs($user)->get($link)->assertOk();
    expect($full->headers->get('Content-Type'))->toBe('video/mp4')->and($full->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($full->headers->get('Content-Disposition'))->not->toContain('.mp4')->and($full->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');

    $part = $this->actingAs($user)->withHeaders(['Range' => 'bytes=0-99'])->get($link);
    expect($part->getStatusCode())->toBe(206)->and($part->headers->get('Content-Range'))->toStartWith('bytes 0-99/');

    $this->actingAs($user)->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $link))->assertForbidden();
    $this->actingAs($user)->get(str_replace('p=0', 'p=1', $link))->assertForbidden();
    $this->actingAs(cin_user())->get($link)->assertForbidden();                       // رابط مسرَّب لحساب آخر
    $this->app['auth']->forgetGuards();                                                // actingAs يدوم داخل الاختبار الواحد
    $this->get($link)->assertRedirect(route('login'));                                 // بلا دخول

    $foreign = $links->url($w['film']->poster, 'scene', $w['beat']->public_id, $user);  // ملف ليس من ملفات هذا المشهد
    $this->actingAs($user)->get($foreign)->assertNotFound();

    $draft = cin_story();
    $draftScene = cin_video_scene($draft['chapter'], 'film_draft');
    $draftLink = $links->url($draftScene->video, 'scene', $draftScene->public_id, $user);
    $this->actingAs($user)->get($draftLink)->assertNotFound();                          // مسودة غير منشورة

    Carbon::setTestNow(now()->addSeconds((int) config('cinematic_stories.media.link_ttl_seconds') + 5));
    $this->actingAs($user)->get($link)->assertForbidden();                              // انتهت الصلاحية
    Carbon::setTestNow();
});

test('HTTP-07: media upload is admin-only, validates the real file, dedupes, and never reveals a storage path', function () {
    $admin = cin_admin();
    $user = cin_user();
    $png = fn () => new UploadedFile(cin_tiny_png(), 'poster.png', 'image/png', null, true);

    $this->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertUnauthorized();
    $this->actingAs($user)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertForbidden();
    $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'hologram', 'file' => $png()])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'poster'])->assertUnprocessable()->assertJsonPath('reason', 'upload_failed');

    $fake = new UploadedFile(cin_tmp('<?php echo 1;', 'mp4'), 'movie.mp4', 'video/mp4', null, true);
    $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => $fake])->assertUnprocessable();

    $first = $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertCreated();
    $again = $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'poster', 'file' => $png()])->assertOk();
    expect($first->json('created'))->toBeTrue()->and($again->json('created'))->toBeFalse()->and($again->json('id'))->toBe($first->json('id'))
        ->and($first->getContent())->not->toContain('path')->not->toContain('cinematic/')->not->toContain(storage_path());

    $video = $this->actingAs($admin)->postJson('/admin/cinematic/media', ['role' => 'video', 'file' => new UploadedFile(cin_tiny_mp4(), 'scene.mp4', 'video/mp4', null, true)])->assertCreated();
    expect($video->json('duration_ms'))->toBeGreaterThan(900);
});

test('HTTP-08: the staff preview shows every scene (even a draft with missing media) with the real solution judged on the server, and writes no progress at all', function () {
    $w = cin_story();
    cin_authoring()->addScene($w['chapter'], ['type' => 'video', 'title' => 'ينتظر', 'stable_key' => 'film_wait']);
    [$public, $solution] = cin_choose('b');
    $quiz = cin_interaction($w['chapter'], 'quiz_it', $public, $solution);
    $revision = $w['revision']->fresh();
    $staff = cin_admin();
    $viewer = cin_user();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];

    $this->actingAs($viewer)->get('/cinematic/preview/'.$revision->public_id)->assertForbidden();

    $page = $this->actingAs($staff)->get('/cinematic/preview/'.$revision->public_id)->assertOk()->assertSee('data-cinematic-preview-banner', false);
    $cfg = cin_cfg($page->getContent());
    expect($cfg['mode'])->toBe('preview')->and(array_keys($cfg['scenes']))->toHaveCount(2)
        ->and($cfg['scenes'][array_key_first($cfg['scenes'])]['video']['state'])->toBe('awaiting')
        ->and(json_encode($cfg))->not->toContain('"correct"');

    $this->actingAs($viewer)->postJson('/cinematic/preview/'.$revision->public_id.'/scenes/'.$quiz->public_id.'/submit', ['submission' => ['item' => 'b']])->assertForbidden();
    $ok = $this->actingAs($staff)->postJson('/cinematic/preview/'.$revision->public_id.'/scenes/'.$quiz->public_id.'/submit', ['submission' => ['item' => 'b']])->assertOk();
    $no = $this->actingAs($staff)->postJson('/cinematic/preview/'.$revision->public_id.'/scenes/'.$quiz->public_id.'/submit', ['submission' => ['item' => 'a']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($no->json('result.correct'))->toBeFalse();

    expect([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});

test('HTTP-09: the flag is closed by default in the shipped config and .env.example, and every route name avoids the E18 economy words', function () {
    $config = (string) file_get_contents(base_path('config/cinematic_stories.php'));
    $example = (string) file_get_contents(base_path('.env.example'));

    expect(preg_match('/env\(\'CINEMATIC_STORIES_ENABLED\',\s*false\)/', $config))->toBe(1)
        ->and(preg_match('/^CINEMATIC_STORIES_ENABLED=false$/m', $example))->toBe(1);

    foreach (app('router')->getRoutes() as $route) {
        if (str_contains($route->uri(), 'cinematic')) {
            expect(strtolower($route->uri().'|'.$route->getName()))->not->toMatch('/(reward|grant|claim|payout|prize)/');

            foreach ($route->parameterNames() as $parameter) {
                expect(strtolower($parameter))->not->toMatch('/(reward|grant|claim|payout|prize)/');
            }
        }
    }
});

test('HTTP-10: author text is escaped everywhere - the service strips markup on write, and even markup forced straight into the database is rendered inert and never reaches an unescaped sink', function () {
    $w = cin_story(['title' => 'قصة <script>alert(1)</script> مميزة']);
    $beat = cin_narrative($w['chapter'], 'xss_beat', ['title' => 'مشهد <img src=x onerror=alert(1)> أول', 'config' => ['lines' => [['speaker' => '<b>x</b>', 'text' => '<script>alert(2)</script>نص']]]]);
    expect($w['story']->title)->not->toContain('<')->and($beat->title)->not->toContain('<')->and(json_encode($beat->config))->not->toContain('<script');

    cin_publish($w['revision']);
    $evil = '<script>alert(9)</script>';
    DB::table('cinematic_stories')->where('id', $w['story']->id)->update(['title' => $evil, 'summary' => $evil]);
    DB::table('cinematic_scenes')->where('id', $beat->id)->update(['title' => $evil, 'config' => json_encode(['lines' => [['speaker' => $evil, 'text' => $evil]]])]);
    $user = cin_user();

    foreach (['/cinematic', '/cinematic/'.$w['story']->slug, '/cinematic/'.$w['story']->slug.'/play'] as $path) {
        $html = $this->actingAs($user)->get($path)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert(9)');
    }

    expect($this->actingAs($user)->get('/cinematic/'.$w['story']->slug)->getContent())->toContain('&lt;script&gt;alert(9)&lt;/script&gt;');

    // لا مصبّ غير مهرَّب في القوالب: لا x-html ولا v-html ولا إخراج Blade غير مهرَّب.
    foreach (['index', 'show', 'play'] as $view) {
        $source = (string) file_get_contents(resource_path("views/cinematic/{$view}.blade.php"));
        expect($source)->not->toContain('x-html')->not->toContain('v-html')->not->toContain('innerHTML')->not->toContain('{!!');
    }
}
);
