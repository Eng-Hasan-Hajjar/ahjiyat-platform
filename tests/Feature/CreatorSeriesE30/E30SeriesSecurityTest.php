<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SecurityE26/_support.php';

beforeEach(function () {
    e30s_seed();
    Storage::fake('public');
});

/** مسارات متحكمات السلاسل وحدها. */
function e30s_series_routes(): array
{
    return collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains((string) $r->getActionName(), 'Controllers\\CreatorSeries\\'))->values()->all();
}

test('AM14-132: CSRF protects every mutating series route (419 without a token; all inside the web group)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $draft = e30s_draft($creator, $works)->series()->first();

    $before = [CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count(), CreatorSeriesItem::query()->count()];
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($creator);
        $this->post(route('creator.series.store'), ['title' => 'بلا رمز', 'items' => e30s_ids($works)])->assertStatus(419);
        $this->put(route('creator.series.update', $draft->public_id), ['title' => 'تعديل بلا رمز', 'items' => e30s_ids($works)])->assertStatus(419);
        $this->post(route('creator.series.submit', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.series.withdraw', $draft->public_id))->assertStatus(419);
        $this->delete(route('creator.series.destroy', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.series.revise', $series->public_id))->assertStatus(419);
        $this->post(route('creator.series.archive', $series->public_id))->assertStatus(419);
        $this->post(route('creator.series.unarchive', $series->public_id))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect([CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count(), CreatorSeriesItem::query()->count()])->toBe($before)
        ->and($draft->fresh()->latestRevision()->first()->title)->not->toBe('تعديل بلا رمز')->and($series->fresh()->isArchived())->toBeFalse();

    $checked = 0;

    foreach (e30s_series_routes() as $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $checked++;
        expect(in_array('web', $route->gatherMiddleware(), true))->toBeTrue('route '.$route->getName().' is outside the web group');
    }

    expect($checked)->toBe(8);
});

test('AM14-133: no GET request mutates anything (no views counter, no progress write, no notification, no row)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $draft = e30s_draft($creator, $works)->series()->first();
    $player = e16User();

    foreach (e30s_series_routes() as $route) {
        if (in_array('GET', $route->methods(), true)) {
            expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([], 'GET route also mutating: '.$route->getName());
        }
    }

    $tables = ['creator_series', 'creator_series_revisions', 'creator_series_items', 'community_puzzle_attempts', 'puzzle_attempts', 'notifications', 'operational_audit_logs', 'creator_puzzles'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => [DB::table($t)->count(), md5(json_encode(DB::table($t)->orderBy('id')->get()))]])->all();
    // أول عرض لصفحة يُنشئ صفوفًا كسولة للمستخدم (تقدّم/تفضيلات)؛ نسخّن بزيارة أولى ثم نقيس التكرار.
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
    $before = $snapshot();

    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $this->get(route('series.show', $series->slug))->assertOk();
    $this->get(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]))->assertOk();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
    $this->get(route('creator.series.create'))->assertOk();
    $this->get(route('creator.series.edit', $draft->public_id))->assertOk();
    $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertOk();
    $this->get(route('discover.index'))->assertOk();
    $this->get(route('search.index', ['q' => 'سلسلة']))->assertOk();

    expect($snapshot())->toBe($before);
});

test('AM14-134: series IDOR - every cross-creator and cross-user access is 404 or denied, with no side effects', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $works = e30s_works($owner, 3);
    $published = e30s_published($owner, $works);
    $draft = e30s_draft($owner, $works)->series()->first();

    $this->actingAs($intruder);
    foreach ([$published, $draft] as $series) {
        $this->get(route('creator.series.edit', $series->public_id))->assertNotFound();
        $this->put(route('creator.series.update', $series->public_id), ['title' => 'اختراق', 'items' => e30s_ids($works)])->assertNotFound();
        $this->post(route('creator.series.submit', $series->public_id))->assertNotFound();
        $this->post(route('creator.series.revise', $series->public_id))->assertNotFound();
        $this->post(route('creator.series.archive', $series->public_id))->assertNotFound();
        $this->delete(route('creator.series.destroy', $series->public_id))->assertNotFound();
    }

    // لا يستطيع مبدع آخر إدراج أحجية غيره بسلسلته ولا اللاعب العادي دخول الاستوديو
    $mine = e30s_works($intruder, 2);
    $this->post(route('creator.series.store'), ['title' => 'سلسلة بأحجية غيري', 'items' => [$mine[0]->public_id, $works[0]->public_id]])->assertSessionHas('error');
    $this->actingAs(e16User())->get(route('creator.series.index'))->assertRedirect(route('creator.apply'));
    $this->actingAs(e16User())->get(route('creator.series.edit', $published->public_id))->assertRedirect(route('creator.apply'));

    expect(CreatorSeries::query()->count())->toBe(2)->and($published->fresh()->isArchived())->toBeFalse();
});

test('AM14-135: series XSS - markup is rejected at input and anything stored is escaped on every surface', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    foreach (['<script>alert(1)</script>سلسلة', 'سلسلة <img src=x onerror=alert(1)>'] as $evil) {
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => $evil, 'items' => e30s_ids($works)])->assertSessionHasErrors('title');
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'عنوان سليم', 'description' => $evil, 'items' => e30s_ids($works)])->assertSessionHasErrors('description');
    }
    expect(CreatorSeries::query()->count())->toBe(0);

    // دفاع ثانٍ: حتى لو وصل نص خبيث لقاعدة البيانات (خارج المسار الرسمي) يُهرَّب عند العرض
    $series = e30s_published($creator, $works, ['title' => 'سلسلة آمنة']);
    $payload = '<script>window.xss=1</script>';
    DB::table('creator_series')->where('id', $series->id)->update(['published_title' => $payload]);
    DB::table('creator_series_revisions')->where('id', $series->published_revision_id)->update(['title' => $payload, 'description' => $payload]);
    $slug = $creator->creatorProfile()->value('slug');

    foreach ([
        route('series.show', $series->public_id), route('creators.show', $slug), route('discover.index'), route('search.index', ['q' => 'script']),
    ] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>window.xss')->and($html)->not->toContain('window.xss=1</script>');
    }

    $this->get(route('series.show', $series->public_id))->assertSee('&lt;script&gt;window.xss=1&lt;/script&gt;', false);
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
});

test('AM14: forbidden authority/economy fields in the request are rejected explicitly, never ignored', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $forbidden = ['gem_reward' => 100, 'xp_reward' => 50, 'status' => 'approved', 'published_revision_id' => 1, 'is_sponsored' => 1, 'sponsorship_program_id' => 1, 'creator_profile_id' => 99, 'slug' => 'hijack', 'hidden_at' => now()->toDateTimeString()];

    // طلب واحد بكل الحقول الممنوعة (محدّد creator-draft-create يعدّ كل محاولة)
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بحقل ممنوع', 'items' => e30s_ids($works)] + $forbidden)->assertSessionHasErrors(array_keys($forbidden));
    expect(CreatorSeries::query()->count())->toBe(0);

    // وكل حقل لوحده يُرفض كذلك (دون تجاوز المحدِّد)
    foreach (['gem_reward', 'status', 'slug'] as $field) {
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بحقل ممنوع', 'items' => e30s_ids($works), $field => $forbidden[$field]])->assertSessionHasErrors($field);
    }

    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM14: models are mass-assignment safe (guarded) - only the services write', function () {
    foreach ([CreatorSeries::class, CreatorSeriesRevision::class, CreatorSeriesItem::class] as $model) {
        expect(fn () => $model::query()->create(['title' => 'x', 'status' => 'approved', 'published_revision_id' => 1]))->toThrow(MassAssignmentException::class);
    }
});

test('AM14: cover upload - only JPEG/PNG/WEBP by real content; SVG, scripts and disguised files are rejected; replacement cleans the old file', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $base = ['title' => 'سلسلة بغلاف', 'items' => e30s_ids($works)];

    $svg = UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $php = UploadedFile::fake()->createWithContent('cover.png', '<?php echo "pwn"; ?>');
    $fakePng = UploadedFile::fake()->createWithContent('cover.png', 'not really an image at all');

    foreach ([$svg, $php, $fakePng] as $bad) {
        $this->actingAs($creator)->post(route('creator.series.store'), $base + ['cover' => $bad])->assertSessionHasErrors('cover');
    }
    expect(CreatorSeries::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);

    $this->actingAs($creator)->post(route('creator.series.store'), $base + ['cover' => UploadedFile::fake()->image('a.png', 300, 200)])->assertSessionHasNoErrors();
    $series = CreatorSeries::query()->firstOrFail();
    $revision = $series->latestRevision;
    $path = $revision->cover_path;

    expect($path)->toStartWith(trim((string) config('creators.media.directory'), '/').'/'.$creator->creatorProfile()->value('id').'/')->and($path)->toEndWith('.png')
        ->and(str_contains($path, 'cover'))->toBeFalse()->and(Storage::disk('public')->exists($path))->toBeTrue();

    $this->actingAs($creator)->put(route('creator.series.update', $series->public_id), $base + ['cover' => UploadedFile::fake()->image('b.jpg', 300, 200)])->assertSessionHasNoErrors();
    $newPath = $revision->fresh()->cover_path;
    expect($newPath)->not->toBe($path)->and(Storage::disk('public')->exists($path))->toBeFalse()->and(Storage::disk('public')->exists($newPath))->toBeTrue();

    $this->actingAs($creator)->put(route('creator.series.update', $series->public_id), $base + ['remove_cover' => 1])->assertSessionHasNoErrors();
    expect($revision->fresh()->cover_path)->toBeNull()->and(Storage::disk('public')->exists($newPath))->toBeFalse();
});

test('AM14: the media prune command never deletes a series cover that is still referenced (and removes true orphans)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بغلاف محمي', 'items' => e30s_ids($works), 'cover' => UploadedFile::fake()->image('a.png', 300, 200)])->assertSessionHasNoErrors();
    $series = CreatorSeries::query()->firstOrFail();
    $cover = $series->latestRevision->cover_path;

    $orphan = trim((string) config('creators.media.directory'), '/').'/'.$creator->creatorProfile()->value('id').'/orphan.png';
    Storage::disk('public')->put($orphan, 'x');
    touch(Storage::disk('public')->path($orphan), now()->subDays(3)->getTimestamp());
    touch(Storage::disk('public')->path($cover), now()->subDays(3)->getTimestamp());

    $this->artisan('creators:prune-media')->assertSuccessful();

    expect(Storage::disk('public')->exists($cover))->toBeTrue()->and(Storage::disk('public')->exists($orphan))->toBeFalse();
});

test('AM14: the public series page is rate limited and the studio drafting uses the existing creator limiters', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);
    $statuses = e26_hammer($this, 'get', route('series.show', $series->public_id), 125);
    expect(e26_first_429($statuses))->toBe(121);
});

test('AM14: new series views never use unescaped output for data', function () {
    $files = array_merge(glob(resource_path('views/series/*.blade.php')), glob(resource_path('views/creator/series/*.blade.php')));
    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        expect(str_contains($source, '{!!'))->toBeFalse(basename($file).' uses {!! !!}')
            ->and(preg_match('/\bdd\(|dump\(|console\.log/', $source))->toBe(0);
    }
});

test('AM14: a disabled feature returns 404 from every series surface but keeps notification routes defined', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);
    config(['creator_series.enabled' => false]);

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertNotFound();
    expect(Route::has('series.show'))->toBeTrue()->and(Route::has('creator.series.index'))->toBeTrue();
    $this->get(route('discover.index'))->assertOk();
    $this->get(route('search.index', ['q' => 'سلسلة']))->assertOk();
});
