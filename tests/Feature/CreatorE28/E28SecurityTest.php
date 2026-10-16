<?php

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| E28-Y9 - الأمان: IDOR للمسودات/التعديل/الوسائط (404 بلا أثر)، صلاحية المراجعة، CSRF، حدود المعدل، وعدم تسريب الجواب/الحل.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
});

test('Y9-73 (73): draft IDOR - another creator, a player and a guest cannot reach a draft by any studio or public route', function () {
    $owner = e28_creator();
    $stranger = e28_creator();
    $work = e28_draft($owner, 'text', ['title' => 'مسودة المالك السرية', 'answer' => 'جوابمسودةسري'])->work;
    $id = $work->public_id;

    foreach ([$stranger, e16User()] as $intruder) {
        $this->actingAs($intruder);

        foreach ([
            ['get', route('creator.puzzles.edit', $id)],
            ['get', route('creator.puzzles.preview', $id)],
            ['post', route('creator.puzzles.preview.check', $id)],
            ['post', route('creator.puzzles.submit', $id)],
            ['post', route('creator.puzzles.withdraw', $id)],
            ['post', route('creator.puzzles.archive', $id)],
            ['get', route('community.puzzles.show', $id)],
        ] as [$method, $url]) {
            $status = $this->{$method}($url)->getStatusCode();
            expect($status)->toBeIn([403, 404, 302], "{$method} {$url} -> {$status}");   // 302 = تحويل لطلب الانضمام لمن ليس مبدعًا
            expect($status)->not->toBe(200);
        }

        auth()->logout();
    }

    $this->get(route('creator.puzzles.edit', $id))->assertRedirect(route('login'));
    expect($work->latestRevision()->first()->status)->toBe(CreatorPuzzleRevision::STATUS_DRAFT);
});

test('Y9-74 (74): edit IDOR - update/delete/revise of another creator\'s work changes nothing; ids are opaque ULIDs, not sequential', function () {
    $owner = e28_creator();
    $stranger = e28_creator();
    $work = e28_published($owner, 'text', ['title' => 'منشورة المالك']);
    $draft = e28_draft($owner, 'text', ['title' => 'مسودة المالك'])->work;

    $this->actingAs($stranger);
    $this->put(route('creator.puzzles.update', $draft->public_id), e28_data('text', ['title' => 'اختراق ناجح']))->assertNotFound();
    $this->delete(route('creator.puzzles.destroy', $draft->public_id))->assertNotFound();
    $this->post(route('creator.puzzles.revise', $work->public_id))->assertNotFound();
    $this->post(route('creator.puzzles.unarchive', $work->public_id))->assertNotFound();

    // معرّف رقمي تسلسلي لا يحلّ أي عمل (المسار whereUlid)
    $this->get('/creator/puzzles/'.$draft->id.'/edit')->assertNotFound();
    $this->get('/community/puzzles/'.$work->id)->assertNotFound();

    expect($draft->fresh()->latestRevision()->first()->title)->toBe('مسودة المالك')
        ->and(CreatorPuzzle::query()->count())->toBe(2)
        ->and(CreatorPuzzleRevision::query()->count())->toBe(2)
        ->and($work->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('Y9-75 (75): media IDOR - another creator cannot delete, replace or reference someone else\'s image', function () {
    $owner = e28_creator();
    $stranger = e28_creator();

    $this->actingAs($owner)->post(route('creator.puzzles.store'), e28_data('image', ['image' => UploadedFile::fake()->image('a.png', 200, 200)]))->assertSessionHasNoErrors();
    $revision = CreatorPuzzleRevision::query()->firstOrFail();
    $path = $revision->image_path;
    Storage::disk('public')->assertExists($path);
    auth()->logout();

    // المسار لا يُحلّ إلا لمالكه (ومنه لا حذف ولا نسخ لغيره)، وبنية .. و\0 مرفوضة
    $media = app(CreatorMediaService::class);
    $strangerProfile = $stranger->creatorProfile()->first();
    $ownerProfile = $owner->creatorProfile()->first();

    expect($media->ownedBy($path, $ownerProfile))->toBeTrue()
        ->and($media->ownedBy($path, $strangerProfile))->toBeFalse()
        ->and($media->ownedBy('community/puzzles/'.$ownerProfile->id.'/../'.$strangerProfile->id.'/x.png', $ownerProfile))->toBeFalse()
        ->and($media->ownedBy("community/puzzles/{$ownerProfile->id}/x\0.png", $ownerProfile))->toBeFalse()
        ->and($media->ownedBy('../.env', $ownerProfile))->toBeFalse()
        ->and($media->ownedBy(null, $ownerProfile))->toBeFalse()
        ->and($media->copy($path, $strangerProfile))->toBeNull();

    $media->delete($path, $strangerProfile);
    Storage::disk('public')->assertExists($path);                                                           // حذف الغريب تجاهَل الملف

    $work = $revision->work;
    $this->actingAs($stranger)->delete(route('creator.puzzles.image.destroy', $work->public_id))->assertNotFound();
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('image', ['image' => UploadedFile::fake()->image('b.png', 200, 200)]))->assertNotFound();
    Storage::disk('public')->assertExists($path);

    // المبدع نفسه لا يستطيع نسبة ملف غيره لمسودته: image_path حقل ممنوع (422)
    $mine = e28_draft($stranger, 'text', ['title' => 'مسودتي'])->work;
    $this->put(route('creator.puzzles.update', $mine->public_id), e28_data('text', ['image_path' => $path]))->assertSessionHasErrors('image_path');
    expect($mine->latestRevision()->first()->image_path)->toBeNull();
});

test('Y9-76 (76): the reviewer preview requires creators.review - guests redirect; players, creators and view-only users get 403; drafts stay 404', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'للمراجعة فقط']);
    $draft = e28_draft($creator, 'text', ['title' => 'مسودة لا تُراجع']);
    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $moderator = e28_reviewer('moderator');

    $show = route('community.review.preview', $revision);
    $check = route('community.review.check', $revision);

    $this->get($show)->assertRedirect(route('login'));
    $this->post($check, ['answer' => 'x'])->assertRedirect(route('login'));

    foreach ([e16User(), $creator, $viewOnly] as $user) {
        $this->actingAs($user);
        $this->get($show)->assertForbidden();
        $this->post($check, ['answer' => 'x'])->assertForbidden();
        auth()->logout();
    }

    $this->actingAs($moderator)->get($show)->assertOk()->assertSee('لوحة الحل');
    $this->get(route('community.review.preview', $draft))->assertNotFound();
    $this->post(route('community.review.check', $draft), ['answer' => 'x'])->assertNotFound();
});

test('Y9-77 (77): CSRF protects every mutating creator / community route (419 without a token; all inside the web group)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text');
    $draft = e28_draft($creator, 'text')->work;
    $player = e16User();

    // (1) مع تفعيل فحص CSRF (البيئة غير testing) يُرفض كل تعديل بلا رمز ولا يتغير شيء
    $before = [CreatorPuzzle::query()->count(), CreatorPuzzleRevision::query()->count(), \App\Models\CommunityPuzzleReport::query()->count()];
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($creator);
        $this->post(route('creator.puzzles.store'), e28_data('text'))->assertStatus(419);
        $this->put(route('creator.puzzles.update', $draft->public_id), e28_data('text', ['title' => 'تعديل بلا رمز']))->assertStatus(419);
        $this->post(route('creator.puzzles.submit', $draft->public_id))->assertStatus(419);
        $this->delete(route('creator.puzzles.destroy', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.puzzles.revise', $work->public_id))->assertStatus(419);
        $this->post(route('creator.puzzles.archive', $work->public_id))->assertStatus(419);
        $this->post(route('creator.apply.store'), ['bio' => str_repeat('ب', 40)])->assertStatus(419);

        $this->actingAs($player);
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'x'])->assertStatus(419);
        $this->post(route('community.puzzles.hint', $work->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect([CreatorPuzzle::query()->count(), CreatorPuzzleRevision::query()->count(), \App\Models\CommunityPuzzleReport::query()->count()])->toBe($before)
        ->and($draft->fresh()->latestRevision()->first()->title)->not->toBe('تعديل بلا رمز');

    // (2) ثابت: لا مسار تعديل creator.* / community.* خارج مجموعة web (الجلسة + CSRF)
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_contains((string) $route->getActionName(), 'Controllers\\Creators\\')) {   // مسارات E28 وحدها (وليس community.chat.* مثلًا)
            continue;
        }

        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $checked++;
        expect(in_array('web', $route->gatherMiddleware(), true))->toBeTrue("route {$name} is outside the web group");
    }

    expect($checked)->toBeGreaterThanOrEqual(15);
});

test('Y9-78 (78): rate limits bite - apply, draft create, submit and report', function () {
    // طلب الانضمام: 2/دقيقة
    $applicant = e16User();
    $statuses = e26_hammer($this, 'post', route('creator.apply.store'), 4, fn () => ['bio' => 'سيرة مبدع طويلة بما يكفي لتجاوز الحد الأدنى للأحرف.'], false);
    expect(e26_first_429($statuses))->toBeNull();                                // غير مسجَّل: لا يصل للمحدِّد (تحويل لتسجيل الدخول)

    $this->actingAs($applicant);
    $statuses = e26_hammer($this, 'post', route('creator.apply.store'), 4, ['bio' => 'سيرة مبدع طويلة بما يكفي لتجاوز الحد الأدنى للأحرف.']);
    expect(e26_first_429($statuses))->toBe(3);
    auth()->logout();

    // إنشاء المسودات: 5/دقيقة
    $creator = e28_creator();
    $this->actingAs($creator);
    $statuses = e26_hammer($this, 'post', route('creator.puzzles.store'), 7, ['title' => 'x']);              // تحقق فاشل يستهلك المحاولة أيضًا
    expect(e26_first_429($statuses))->toBe(6);

    // الإرسال للمراجعة: 5/دقيقة (على عمل بلا نسخة صالحة = رفض لطيف، لكن المحدِّد يعدّ كل طلب)
    $draft = e28_draft(e28_creator($other = e16User()), 'text')->work;
    auth()->logout();
    $this->actingAs($other);
    $statuses = e26_hammer($this, 'post', route('creator.puzzles.withdraw', $draft->public_id), 7);
    expect(e26_first_429($statuses))->toBe(6);
    auth()->logout();

    // البلاغات: 5/دقيقة
    $work = e28_published(e28_creator(), 'text');
    $this->actingAs(e16User());
    $statuses = e26_hammer($this, 'post', route('community.puzzles.report', $work->public_id), 7, ['reason' => 'spam']);
    expect(e26_first_429($statuses))->toBe(6);
});

test('Y9-79 (79): the answer and the solution never leak - public page, wrong attempts, exhausted state, owner preview, API and web puzzle routes', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز بلا تسريب', 'answer' => 'سرجوهرةفريد', 'hint' => 'تلميحمحجوب']);
    $canonical = Puzzle::query()->findOrFail($work->published_puzzle_id);
    $player = e16User();
    $secrets = ['سرجوهرةفريد'];

    // الصفحة العامة (ضيف ولاعب): لا جواب ولا تلميح قبل طلبه
    $guest = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    e28_assert_no_secrets($guest, $secrets);
    expect(str_contains($guest, 'تلميحمحجوب'))->toBeFalse();

    // محاولات خاطئة حتى الاستنفاد: لا جواب في أي استجابة
    $this->actingAs($player);
    $max = (int) config('creators.puzzle.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'خطأ'.$i])->assertRedirect();
        $page = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
        e28_assert_no_secrets($page, $secrets);
    }

    $this->get(route('community.puzzles.show', $work->public_id))->assertSee('استنفدت');

    // المعاينة: صاحب العمل لا يرى لوحة الحل (للمراجع فقط)
    auth()->logout();
    $owner = $this->actingAs($creator)->get(route('creator.puzzles.preview', $work->public_id))->assertOk()->getContent();
    expect(str_contains($owner, 'لوحة الحل'))->toBeFalse();
    e28_assert_no_secrets($owner, $secrets);
    auth()->logout();

    // المراجع يراها (لقطة نسخة مرسلة)
    $revision = e28_pending($creator, 'text', ['title' => 'للمراجع', 'answer' => 'جوابالمراجع']);
    $this->actingAs(e28_reviewer())->get(route('community.review.preview', $revision))->assertOk()->assertSee('لوحة الحل')->assertSee('جوابالمراجع');
    auth()->logout();

    // الأحجية القانونية لا تُكشف عبر مسارات الأحجيات الرسمية (ويب وAPI) ولا عبر المعرّف الرقمي
    $this->get(route('puzzles.show', $canonical))->assertNotFound();
    $this->actingAs($player);
    $this->getJson('/api/puzzles/'.$canonical->id)->assertNotFound();
    $list = $this->getJson('/api/puzzles')->assertOk()->getContent();
    expect(str_contains($list, 'لغز بلا تسريب'))->toBeFalse();
    expect(str_contains($list, 'سرجوهرةفريد'))->toBeFalse();
});
