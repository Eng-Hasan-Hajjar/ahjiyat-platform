<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - محتوى المرحلة الأولى التجريبي («المكتبة الغامضة»): هيكل مطابق لوثيقة ياسمين، لا نشر لأن الفيديوهات لم تُنتَج،
| وسيلة إدخال محصورة ببيئة التطوير، وإثبات أن الحلول المرجعية تُكمل المرحلة كاملةً عبر HTTP بوسائط مؤقتة داخل الاختبار فقط
| (لا شيء منها يُسلَّم، ولا فيديو مختلق في المحتوى).
*/

beforeEach(fn () => cin_seed());

function cin_demo(): array
{
    return app(Stage1DemoInstaller::class)->install();
}

/** إجابة مرجعية لمشهد تفاعلي، مشتقة من الحل المخزَّن في التعريف. @param array<string, mixed> $scene @return array<string, mixed> */
function cin_demo_answer(array $scene): array
{
    $kind = $scene['config']['interaction']['kind'];
    $s = $scene['solution'];

    return match ($kind) {
        'hotspot' => ['area' => $s['correct'][0]],
        'container' => ['area' => $s['correct']],
        'sequence' => ['order' => $s['order']],
        'drag_drop' => ['placements' => $s['map']],
        'choose_item' => ['item' => $s['correct']],
        'compare' => ['option' => $s['correct']],
        'flashlight' => ['points' => array_map(fn ($t) => ['x' => $t['x'] + $t['w'] / 2, 'y' => $t['y'] + $t['h'] / 2], array_slice($s['targets'], 0, (int) ($s['required'] ?? count($s['targets']))))],
        default => throw new RuntimeException("kind {$kind}"),
    };
}

/** @return list<array<string, mixed>> المشاهد بترتيب التعريف */
function cin_demo_scenes(): array
{
    $out = [];

    foreach (Stage1DemoInstaller::definition()['chapters'] as $chapter) {
        foreach ($chapter['scenes'] as $scene) {
            $out[] = $scene;
        }
    }

    return $out;
}

test('DEMO-01: the installed model follows the PDF - one stage with the five playable chapters, five riddles, every scene typed and ordered, and no invented stages 2-7', function () {
    $r = cin_demo();
    $revision = $r['revision'];

    $stages = CinematicStage::query()->where('revision_id', $revision->id)->get();
    expect($r['created'])->toBeTrue()->and($r['story']->slug)->toBe('unwritten-page')->and($revision->status)->toBe(CinematicRevision::STATUS_DRAFT)
        ->and($stages)->toHaveCount(1)->and($stages->first()->title)->toBe('المرحلة الأولى: المكتبة الغامضة')->and($stages->first()->stable_key)->toBe('stage_1');

    $chapters = $stages->first()->chapters()->orderBy('position')->pluck('title')->all();
    expect($chapters)->toBe([
        'الفصل الثاني: الرسالة الغامضة',
        'الفصل الثالث: الوصول إلى المكتبة',
        'الفصل الرابع: داخل المكتبة',
        'الفصل الخامس: الألغاز الخمسة',
        'الفصل السادس: المشهد الختامي',
    ]);

    $scenes = CinematicStructure::scenes($revision);
    $keys = $scenes->pluck('stable_key')->all();

    expect($scenes)->toHaveCount(19)->and($keys)->toBe(collect(cin_demo_scenes())->pluck('stable_key')->all());

    foreach ([1, 2, 3, 4, 5] as $n) {
        expect(collect($keys)->filter(fn ($k) => str_starts_with($k, "riddle_{$n}_"))->count())->toBeGreaterThanOrEqual(2, "riddle {$n}");
    }

    $types = $scenes->countBy('type')->all();
    expect($types['video'])->toBe(10)->and($types['transition'])->toBe(1)->and($types['exploration'])->toBe(1)->and($types['interaction'])->toBe(7)
        ->and($scenes->last()->type)->toBe('transition')->and($scenes->last()->config['title'])->toBe('المرحلة الثانية');
});

test('DEMO-02: every video is "awaiting production" with no file attached, the readiness check lists exactly those as blockers, and the stage cannot be published or seen by the public', function () {
    $r = cin_demo();
    $revision = $r['revision'];
    $videos = CinematicStructure::scenes($revision)->where('type', 'video');

    expect($videos)->toHaveCount(10);

    foreach ($videos as $video) {
        expect($video->awaiting_media)->toBeTrue()->and($video->video_asset_id)->toBeNull()->and($video->poster_asset_id)->toBeNull();
    }

    $codes = collect($r['readiness']['blockers'])->pluck('code')->unique()->all();
    expect($r['readiness']['ok'])->toBeFalse()->and($codes)->toBe(['video_missing'])->and(collect($r['readiness']['blockers'])->pluck('scene')->sort()->values()->all())->toBe($videos->pluck('stable_key')->sort()->values()->all());

    cin_publishing()->submitForReview($revision->fresh());
    expect(cin_reason(fn () => cin_publishing()->publish($revision->fresh())))->not->toBeNull();

    $fresh = $revision->fresh();
    expect($fresh->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED)->and(CinematicStory::query()->whereKey($r['story']->id)->first()->revisions()->where('status', CinematicRevision::STATUS_PUBLISHED)->count())->toBe(0);

    auth()->logout();
    $this->get('/cinematic')->assertOk()->assertDontSee('الصفحة التي لم تُكتب');
    $this->get('/cinematic/unwritten-page')->assertNotFound();
});

test('DEMO-03: the staff preview shows the whole draft with "awaiting production" states instead of fake videos, judged on the server, with no progress written', function () {
    $r = cin_demo();
    $staff = cin_admin();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];

    $page = $this->actingAs($staff)->get('/cinematic/preview/'.$r['revision']->public_id)->assertOk()->assertSee('data-cinematic-preview-banner', false);
    $html = $page->getContent();
    preg_match('/data-config="([^"]*)"/', $html, $m);
    $cfg = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true);

    expect($cfg['mode'])->toBe('preview')->and($cfg['scenes'])->toHaveCount(19);

    $awaiting = collect($cfg['scenes'])->filter(fn ($s) => ($s['video']['state'] ?? null) === 'awaiting')->count();
    expect($awaiting)->toBe(10);

    foreach (collect($cfg['scenes'])->filter(fn ($s) => isset($s['video'])) as $scene) {
        expect($scene['video']['src'])->toBeNull();
    }

    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('"correct"')->not->toContain('mark_first')->not->toContain('"solution"');
    expect([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});

test('DEMO-04: the installer is for isolated development only - production and staging are refused before any write, the command reports it, and re-running changes nothing', function () {
    $before = CinematicStory::query()->count();

    foreach (['production', 'staging'] as $env) {
        $this->app['env'] = $env;
        expect(cin_reason(fn () => app(Stage1DemoInstaller::class)->install()))->toBe('demo_forbidden');
        $this->artisan('cinematic:install-demo')->assertExitCode(1);
    }

    expect(CinematicStory::query()->count())->toBe($before);

    $this->app['env'] = 'testing';
    $this->artisan('cinematic:install-demo')->assertExitCode(0);
    $count = [CinematicStory::query()->count(), CinematicScene::query()->count()];

    $again = cin_demo();
    $this->artisan('cinematic:install-demo')->assertExitCode(0);

    expect($again['created'])->toBeFalse()->and([CinematicStory::query()->count(), CinematicScene::query()->count()])->toBe($count)->and($count)->toBe([1, 19]);
});

test('DEMO-05: installing and previewing change no economy, wallet or payment table and no legacy series table', function () {
    $before = cin_economy_snapshot();
    $legacy = collect(['creator_series', 'creator_series_items', 'creator_series_accesses'])->filter(fn ($t) => Illuminate\Support\Facades\Schema::hasTable($t))
        ->mapWithKeys(fn ($t) => [$t => Illuminate\Support\Facades\DB::table($t)->count()])->all();

    cin_demo();
    cin_demo();

    expect(cin_economy_snapshot())->toBe($before);

    foreach ($legacy as $table => $count) {
        expect(Illuminate\Support\Facades\DB::table($table)->count())->toBe($count, $table);
    }
});

test('DEMO-06: the definition is honest - every scene declares what is provisional, every video carries the full production brief, nothing outside the PDF is invented, and no markup or secrets sit in public text', function () {
    $def = Stage1DemoInstaller::definition();
    $briefKeys = ['place_time', 'duration', 'visual', 'characters', 'voice_over', 'effects', 'music', 'transition', 'consistency'];
    $all = cin_demo_scenes();

    expect($def['story']['access_mode'])->toBe('free')->and($def['production_notes']['pdf_chapter'])->toBe(1)->and(array_keys($def['production_notes']['levels']))->toBe(['الحوار', 'المؤثرات', 'الموسيقى', 'الصمت'])
        ->and(json_encode($def['not_in_pdf'], JSON_UNESCAPED_UNICODE))->toContain('من الثانية إلى السابعة');

    foreach ($all as $scene) {
        expect($scene)->toHaveKeys(['stable_key', 'type', 'title', 'provisional', 'production']);

        if ($scene['type'] === 'video') {
            expect($scene['awaiting_media'])->toBeTrue()->and($scene['production'])->toHaveKeys($briefKeys)->and($scene['config']['transcript'])->not->toBeEmpty();

            foreach ($briefKeys as $k) {
                expect(trim((string) $scene['production'][$k]))->not->toBe('', "{$scene['stable_key']}.{$k}");
            }

            if ($scene['duration_hint_seconds'] === null) {
                expect($scene['production']['duration'])->toContain('غير محددة');
            }
        }

        if (in_array($scene['type'], ['interaction', 'exploration'], true)) {
            expect($scene['production'])->toHaveKeys(['laravel'])->and($scene['solution'])->not->toBeNull();
        }
    }

    // كل مصدر قيمة غير موجودة في الوثيقة موسوم مؤقتًا (الإحداثيات ومدد التقسيم وموضع الكتاب وربط الرموز).
    $provisional = collect($all)->mapWithKeys(fn ($s) => [$s['stable_key'] => implode(' ', $s['provisional'])]);
    expect($provisional['arrival_door'])->toContain('areas')->and($provisional['riddle_1_lamp'])->toContain('mark_first')->and($provisional['riddle_2_move'])->toContain('pos_3')
        ->and($provisional['riddle_5_link'])->not->toBe('')->and($provisional['arrival_video'])->toContain('duration_hint_seconds');

    $keys = collect($all)->pluck('stable_key');
    expect($keys->duplicates()->all())->toBe([]);

    $public = json_encode(collect($all)->map(fn ($s) => $s['config'])->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toMatch('/<[a-z!\/]/i')->and($public)->not->toContain('http');
});

test('DEMO-07: with temporary test media the stage publishes, and the reference solutions complete all 19 scenes over HTTP - no response ever carries a solution, the economy never moves', function () {
    $r = cin_demo();
    $revision = $r['revision'];
    $asset = cin_video_asset();

    foreach (CinematicStructure::scenes($revision)->where('type', 'video') as $scene) {
        cin_authoring()->replaceMedia($scene, 'video', $asset);
    }

    $live = cin_publish($revision->fresh());
    expect($live->status)->toBe(CinematicRevision::STATUS_PUBLISHED);

    $user = cin_user();
    $slug = $r['story']->slug;
    $before = cin_economy_snapshot();
    $seen = [];
    $answers = collect(cin_demo_scenes())->keyBy('stable_key');
    $byKey = CinematicStructure::scenes($live)->keyBy('stable_key');

    $this->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk();

    foreach ($answers as $key => $def) {
        $scene = $byKey[$key];
        $id = $scene->public_id;

        $started = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/start")->assertOk();
        $seen[] = $started->getContent();

        if (in_array($def['type'], ['video', 'transition'], true)) {
            $res = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/finish", ['mode' => 'read'])->assertOk();
        } else {
            $wrong = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/submit", ['submission' => ['area' => 'nowhere', 'item' => 'nothing', 'order' => ['x'], 'placements' => ['x' => 'y'], 'points' => [['x' => 0.1, 'y' => 0.1]]]])->assertOk();
            expect($wrong->json('result.correct'))->toBeFalse($key);
            $seen[] = $wrong->getContent();

            $res = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/submit", ['submission' => cin_demo_answer($def)])->assertOk();
            expect($res->json('result.correct'))->toBeTrue($key);
        }

        $seen[] = $res->getContent();
    }

    $progress = CinematicProgress::query()->where('user_id', $user->id)->firstOrFail();
    expect($progress->status)->toBe('completed')->and(CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->count())->toBe(19);

    // لا استجابة تحمل حلًّا: لا مفاتيح الحل ولا مواضع العنصر المخفي ولا شرط الفتح الداخلي.
    $joined = implode("\n", $seen);
    // «correct» يظهر فقط كحكم منطقي في نتيجة الإرسال (true/false)، لا كقيمة حل (نص أو قائمة).
    expect($joined)->not->toContain('"correct":"')->not->toContain('"correct":[')->not->toContain('"order":[')->not->toContain('"map"')->not->toContain('mark_first')->not->toContain('requires_scenes')->not->toContain('"solution"');
    expect(cin_economy_snapshot())->toBe($before);
});
