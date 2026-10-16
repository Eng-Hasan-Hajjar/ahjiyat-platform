<?php

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorVisibilityService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function e28_slug(User $creator): string
{
    return $creator->creatorProfile()->value('slug');
}

test('Y6-49 (49): the creator public page is open to guests with name, bio, joined date and totals', function () {
    $creator = e28_creator(e16User(['name' => 'نور المبدعة']));
    e28_published($creator, 'text', ['title' => 'لغز نور الأول']);

    $response = $this->get(route('creators.show', e28_slug($creator)))->assertOk();
    $response->assertSee('نور المبدعة')->assertSee('مبدع')->assertSee('لغز نور الأول')->assertSee('مبدع ألغاز يحب صناعة التحديات الذهنية للمجتمع.');
    expect($response->getContent())->toContain('أحجيات منشورة');
});

test('Y6-50 (50): only published, visible content appears on the creator page', function () {
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'منشورة ظاهرة']);
    e28_draft($creator, 'text', ['title' => 'مسودة خاصة']);
    e28_pending($creator, 'text', ['title' => 'قيد المراجعة خاصة']);
    $rejected = e28_pending($creator, 'text', ['title' => 'مرفوضة خاصة']);
    app(\App\Services\Creators\CreatorReviewService::class)->reject($rejected, e28_reviewer(), 'لا يناسب الإرشادات تمامًا.');
    $archived = e28_published($creator, 'text', ['title' => 'مؤرشفة خاصة']);
    app(CreatorPuzzleService::class)->archive($creator, $archived->public_id);

    $html = $this->get(route('creators.show', e28_slug($creator)))->assertOk()->getContent();
    expect($html)->toContain('منشورة ظاهرة')->and($html)->not->toContain('مسودة خاصة')->and($html)->not->toContain('قيد المراجعة خاصة')->and($html)->not->toContain('مرفوضة خاصة')->and($html)->not->toContain('مؤرشفة خاصة');
});

test('Y6-51 (51): a draft (or pending work) is not reachable publicly - 404 for guests, players and other creators', function () {
    $creator = e28_creator();
    $draft = e28_draft($creator, 'text', ['title' => 'مسودة سرية'])->work;
    $pending = e28_pending($creator, 'text', ['title' => 'معلقة سرية'])->work;

    foreach ([$draft, $pending] as $work) {
        $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs(e16User())->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs(e28_creator())->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs($creator)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();   // حتى صاحبها: يعاينها من الاستوديو لا من الصفحة العامة
        auth()->logout();
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'x'])->assertRedirect(route('login'));
    }

    $this->get(route('community.puzzles.index'))->assertOk()->assertDontSee('مسودة سرية')->assertDontSee('معلقة سرية');
});

test('Y6-52 (52): a rejected work is not reachable publicly', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'لغز مرفوض']);
    app(\App\Services\Creators\CreatorReviewService::class)->reject($revision, e28_reviewer(), 'سبب الرفض مكتوب وواضح.');
    $work = $revision->work;

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $this->get(route('community.puzzles.index'))->assertDontSee('لغز مرفوض');
    $this->get(route('creators.show', e28_slug($creator)))->assertDontSee('لغز مرفوض');
});

test('Y6-53 (53): a moderator-hidden publication is not reachable (page, list, creator page), and returns on restore', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز سيُخفى']);
    $reviewer = e28_reviewer();

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    app(CreatorVisibilityService::class)->hide($work, $reviewer, 'مخالفة للإرشادات بعد المراجعة.');

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $this->get(route('community.puzzles.index'))->assertDontSee('لغز سيُخفى');
    $this->get(route('creators.show', e28_slug($creator)))->assertDontSee('لغز سيُخفى');
    expect($work->fresh()->is_visible)->toBeFalse();

    app(CreatorVisibilityService::class)->restore($work->fresh(), $reviewer);
    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
});

test('Y6-54 (54): the creator attribution is visible and links to the creator page', function () {
    $creator = e28_creator(e16User(['name' => 'سامي صانع الألغاز']));
    $work = e28_published($creator, 'text', ['title' => 'لغز سامي']);

    $show = $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    $show->assertSee('بقلم')->assertSee('سامي صانع الألغاز');
    expect($show->getContent())->toContain(route('creators.show', e28_slug($creator)));

    $this->get(route('community.puzzles.index'))->assertOk()->assertSee('سامي صانع الألغاز');
});

test('Y6-55 (55): the community label is on every public surface and the page states there are no rewards', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز موسوم']);

    foreach ([route('community.puzzles.show', $work->public_id), route('community.puzzles.index'), route('creators.show', e28_slug($creator))] as $url) {
        $this->get($url)->assertOk()->assertSee('من المجتمع');
    }

    $this->get(route('community.puzzles.show', $work->public_id))->assertSee('بلا جوائز');
    $official = \App\Models\Puzzle::factory()->create(['title' => 'لغز رسمي']);
    $this->get(route('puzzles.index'))->assertOk()->assertDontSee('من المجتمع');
});

test('Y6-56 (56): private creator data is absent - no email, status, review notes, user id, hidden reason', function () {
    $user = e16User(['name' => 'سلمى', 'email' => 'salma.private@example.test']);
    $creator = e28_creator($user);
    $revision = e28_pending($creator, 'text', ['title' => 'لغز سلمى']);
    app(\App\Services\Creators\CreatorReviewService::class)->approve($revision, e28_reviewer(), 'ملاحظة اعتماد داخلية سرية');
    $profile = CreatorProfile::query()->firstOrFail();
    app(CreatorVisibilityService::class)->hide($revision->work->fresh(), e28_reviewer(), 'ملاحظة إخفاء داخلية سرية');
    $second = e28_published($creator, 'text', ['title' => 'لغز ثانٍ ظاهر']);

    foreach ([route('creators.show', $profile->slug), route('community.puzzles.index'), route('community.puzzles.show', $second->public_id)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('salma.private@example.test')->and($html)->not->toContain('ملاحظة اعتماد داخلية سرية')->and($html)->not->toContain('ملاحظة إخفاء داخلية سرية')
            ->and($html)->not->toContain('account_frozen')->and($html)->not->toContain('moderation')->and($html)->not->toContain('review_note');
        e28_assert_no_secrets($html);
    }

    // مبدع غير معتمَد/معلَّق/مجمَّد = صفحته 404.
    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق لأغراض الاختبار هنا');
    $this->get(route('creators.show', $profile->slug))->assertNotFound();
    $this->get(route('community.puzzles.show', $second->public_id))->assertNotFound();

    $pending = app(CreatorApplicationService::class)->apply(e16User(), 'مقدّم جديد ينتظر قرار المراجعة هنا الآن.');
    $this->get(route('creators.show', $pending->slug))->assertNotFound();
    $this->get('/creators/does-not-exist')->assertNotFound();
});
