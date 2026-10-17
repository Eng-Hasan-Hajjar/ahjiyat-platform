<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

function e30s_notes(\App\Models\User $user, string $type)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', $type)->get();
}

test('AM10-95: submitting locks the draft - no edit while pending, withdraw returns it to draft', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, $works);
    $publicId = $revision->series()->value('public_id');

    app(CreatorSeriesService::class)->submit($creator, $publicId);
    expect($revision->fresh()->status)->toBe('pending_review')->and($revision->fresh()->submitted_at)->not->toBeNull();

    expect(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل أثناء المراجعة', 'items' => e30s_ids($works)]))->toThrow(CreatorSeriesException::class)
        ->and(fn () => app(CreatorSeriesService::class)->submit($creator, $publicId))->toThrow(CreatorSeriesException::class)
        ->and(fn () => app(CreatorSeriesService::class)->destroy($creator, $publicId))->toThrow(CreatorSeriesException::class);

    $this->actingAs($creator)->put(route('creator.series.update', $publicId), ['title' => 'تعديل أثناء المراجعة', 'items' => e30s_ids($works)])->assertSessionHas('error');
    expect($revision->fresh()->title)->not->toBe('تعديل أثناء المراجعة');

    app(CreatorSeriesService::class)->withdraw($creator, $publicId);
    expect($revision->fresh()->status)->toBe('draft');
});

test('AM10-96: a reviewer requests changes with a mandatory reason; the creator is notified', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    $reviewer = e28_reviewer();

    expect(fn () => app(CreatorSeriesReviewService::class)->requestChanges($revision, $reviewer, 'x'))->toThrow(CreatorSeriesException::class);

    $closed = app(CreatorSeriesReviewService::class)->requestChanges($revision, $reviewer, 'أعد ترتيب الأحجيات من الأسهل للأصعب.');
    expect($closed->status)->toBe('changes_requested')->and($closed->review_note)->toContain('أعد ترتيب')->and($closed->reviewed_by)->toBe($reviewer->id);

    $notes = e30s_notes($creator, 'series_changes_requested');
    expect($notes)->toHaveCount(1)->and($notes[0]->data['action_route'])->toBe('creator.series.index');
});

test('AM10-97: the creator edits after a changes request and re-submits', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, [$works[0], $works[1]]);
    $publicId = $revision->series()->value('public_id');
    app(CreatorSeriesReviewService::class)->requestChanges($revision, e28_reviewer(), 'أضف أحجية ثالثة للسلسلة.');

    app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'عنوان بعد التعديل', 'items' => e30s_ids($works)]);
    app(CreatorSeriesService::class)->submit($creator, $publicId);

    $fresh = $revision->fresh();
    expect($fresh->status)->toBe('pending_review')->and($fresh->title)->toBe('عنوان بعد التعديل')->and($fresh->review_note)->toBeNull()
        ->and($fresh->items()->count())->toBe(3);
});

test('AM10-98: a reviewer approves - the series is published with a snapshot, and the creator is notified', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, $works, ['title' => 'سلسلة جاهزة للنشر']);
    $reviewer = e28_reviewer();

    $approved = app(CreatorSeriesReviewService::class)->approve($revision, $reviewer, 'عمل ممتاز.');
    $series = $approved->series()->first();

    expect($approved->status)->toBe('approved')->and($approved->reviewed_by)->toBe($reviewer->id)->and($series->published_revision_id)->toBe($approved->id)
        ->and($series->published_title)->toBe('سلسلة جاهزة للنشر')->and($series->published_items_count)->toBe(3)->and($series->published_at)->not->toBeNull()
        ->and($series->first_published_at)->not->toBeNull()->and(e30s_visible($series))->toBeTrue()
        ->and(e30s_notes($creator, 'series_approved'))->toHaveCount(1);
});

test('AM10-99: an unauthorized reviewer (player, content-manager without creators.review) is denied', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);

    foreach ([e16User(), e28_reviewer('content-manager'), $creator] as $actor) {
        foreach (['approve', 'requestChanges', 'reject'] as $method) {
            $args = $method === 'approve' ? [$revision, $actor] : [$revision, $actor, 'سبب واضح للاختبار هنا.'];
            expect(fn () => app(CreatorSeriesReviewService::class)->{$method}(...$args))->toThrow(HttpException::class);
        }
    }

    expect($revision->fresh()->status)->toBe('pending_review');
});

test('AM10-100: rejection stores the reason, notifies, and the series stays non-public', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    $closed = app(CreatorSeriesReviewService::class)->reject($revision, e28_reviewer(), 'السلسلة لا تحقق الحد الأدنى من الجودة.');

    expect($closed->status)->toBe('rejected')->and($closed->review_note)->toContain('الجودة')->and($closed->series->published_revision_id)->toBeNull()
        ->and(e30s_visible($closed->series))->toBeFalse()->and(e30s_notes($creator, 'series_rejected'))->toHaveCount(1);

    $this->get(route('series.show', $closed->series->public_id))->assertNotFound();
    $this->actingAs($creator)->get(route('creator.series.edit', $closed->series->public_id))->assertOk()->assertSee('الجودة');
});

test('AM10-101: editing a published series requires a new revision that is reviewed; the published one stays live meanwhile', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'العنوان المنشور']);
    $publicId = $series->public_id;

    // لا تعديل مباشر للنسخة المعتمدة
    expect(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل صامت', 'items' => e30s_ids($works)]))->toThrow(CreatorSeriesException::class);

    $draft = app(CreatorSeriesService::class)->revise($creator, $publicId);
    app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'العنوان الجديد', 'items' => e30s_ids(array_reverse($works))]);

    // المنشورة باقية حتى الاعتماد
    $page = $this->get(route('series.show', $publicId))->assertOk()->assertSee('العنوان المنشور')->assertDontSee('العنوان الجديد');
    expect($series->fresh()->published_title)->toBe('العنوان المنشور');

    app(CreatorSeriesService::class)->submit($creator, $publicId);
    $this->get(route('series.show', $publicId))->assertSee('العنوان المنشور');

    app(CreatorSeriesReviewService::class)->approve($draft->fresh(), e28_reviewer());
    $this->get(route('series.show', $publicId))->assertOk()->assertSee('العنوان الجديد')->assertDontSee('العنوان المنشور');
    expect($series->fresh()->published_revision_id)->toBe($draft->id)
        ->and(CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->count())->toBe(2)
        ->and(CreatorSeriesItem::query()->where('creator_series_revision_id', $series->published_revision_id)->count())->toBe(0 + 3);
});

test('AM10-102: approval, change request, rejection, hide and restore are audited', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $a = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->approve($a, $reviewer);
    $b = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->requestChanges($b, $reviewer, 'يحتاج مزيدًا من الوضوح.');
    $c = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->reject($c, $reviewer, 'مخالفة واضحة للإرشادات.');
    app(CreatorSeriesReviewService::class)->hide($a->series()->first(), $reviewer, 'سبب إخفاء إشرافي واضح.');
    app(CreatorSeriesReviewService::class)->restore($a->series()->first(), $reviewer);

    $actions = DB::table('operational_audit_logs')->pluck('action')->all();
    foreach (['creator_series_approved', 'creator_series_changes_requested', 'creator_series_rejected', 'creator_series_hidden', 'creator_series_restored'] as $action) {
        expect($actions)->toContain($action);
    }

    $row = DB::table('operational_audit_logs')->where('action', 'creator_series_approved')->first();
    expect((int) $row->actor_user_id)->toBe($reviewer->id)->and($row->subject_type)->toBe(CreatorSeriesRevision::class);
});

test('AM10: a revision that is no longer pending cannot be decided twice, and a stale item blocks approval', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, $works);
    $reviewer = e28_reviewer();

    // عنصر أُخفي بعد الإرسال: الاعتماد يرفض بدل نشر سلسلة لن تظهر
    e30s_hide_work($works[1]);
    expect(fn () => app(CreatorSeriesReviewService::class)->approve($revision, $reviewer))->toThrow(CreatorSeriesException::class);
    expect($revision->fresh()->status)->toBe('pending_review');

    e30s_restore_work($works[1]);
    app(CreatorSeriesReviewService::class)->approve($revision, $reviewer);
    expect(fn () => app(CreatorSeriesReviewService::class)->approve($revision, $reviewer))->toThrow(CreatorSeriesException::class);
});

test('AM10: submitting is blocked if an item was hidden after saving, and the pending cap is enforced', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, $works);
    e30s_hide_work($works[0]);

    expect(fn () => app(CreatorSeriesService::class)->submit($creator, $revision->series()->value('public_id')))->toThrow(CreatorSeriesException::class);

    e30s_restore_work($works[0]);
    config(['creator_series.limits.max_pending' => 1]);
    e30s_pending($creator, $works);
    $second = e30s_draft($creator, $works);
    expect(fn () => app(CreatorSeriesService::class)->submit($creator, $second->series()->value('public_id')))->toThrow(CreatorSeriesException::class);
});
