<?php

use App\Jobs\DispatchCreatorSeriesChunk;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

function e30s_series_notes(User $user)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', 'creator_new_series')->get();
}

test('AM13-123: a newly published series is announced to followers (queued, only when followers exist)', function () {
    Queue::fake();
    $creator = e28_creator();
    $lonely = e28_creator();
    e29_follow(e16User(), $creator);

    e30s_published($creator);
    e30s_published($lonely);

    Queue::assertPushed(DispatchCreatorSeriesChunk::class, 1);
});

test('AM13-123b: the follower gets exactly one correct internal notification pointing to the public series page', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);

    $series = e30s_published($creator, null, ['title' => 'سلسلة الإشعار الصحيح']);

    $notes = e30s_series_notes($follower);
    expect($notes)->toHaveCount(1);
    $data = $notes[0]->data;
    expect($data['category'])->toBe('social')->and($data['title'])->toContain('سلسلة الإشعار الصحيح')->and($data['action_route'])->toBe('series.show')
        ->and($data['action_params']['series'])->toBe($series->public_id)->and($notes[0]->idempotency_key)->toStartWith('creator-series:');
    // الرابط يُحلّ فعلًا إلى مسار حقيقي
    expect(route($data['action_route'], $data['action_params']))->toContain('/series/'.$series->public_id);
});

test('AM13-124: a non-follower (and the creator) is never notified', function () {
    $creator = e28_creator();
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);

    e30s_published($creator);

    expect(e30s_series_notes($stranger))->toHaveCount(0)->and(e30s_series_notes($creator))->toHaveCount(0)->and(e30s_series_notes($follower))->toHaveCount(1);
});

test('AM13-125: the fan-out is idempotent - running the job twice never duplicates', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $series = e30s_published($creator);
    $revisionId = (int) $series->fresh()->published_revision_id;

    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));
    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));

    expect(e30s_series_notes($follower))->toHaveCount(1);
});

test('AM13-126: a notification failure never rolls back the publication', function () {
    $creator = e28_creator();
    e29_follow(e16User(), $creator);

    $this->mock(NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andReturnUsing(function ($user, $type) {
            if ($type === NotificationType::CreatorNewSeries || $type === NotificationType::SeriesApproved) {
                throw new RuntimeException('simulated notification failure');
            }

            return DispatchResult::Created;
        });
    });

    $series = e30s_published($creator, null, ['title' => 'نشر رغم فشل الإشعار']);

    expect(e30s_visible($series))->toBeTrue()->and($series->fresh()->published_revision_id)->not->toBeNull()
        ->and(CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->value('status'))->toBe('approved');
});

test('AM13: a series hidden or blocked before the worker runs is not announced', function () {
    Queue::fake();
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $follower = e16User();
    e29_follow($follower, $creator);
    $series = e30s_published($creator, $works);
    Queue::assertPushed(DispatchCreatorSeriesChunk::class);

    e30s_hide_work($works[0]);
    $revisionId = (int) $series->fresh()->published_revision_id;
    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));

    expect(e30s_series_notes($follower))->toHaveCount(0);
});

test('AM13: a large follower set is processed in bounded chunks chained by id cursor', function () {
    config(['player_notifications.chunk_size' => 3]);
    $creator = e28_creator();
    collect(range(1, 8))->each(fn () => e29_follow(e16User(), $creator));
    Queue::fake();
    $series = e30s_published($creator);
    $revisionId = (int) $series->fresh()->published_revision_id;

    $runs = 0;
    $job = new DispatchCreatorSeriesChunk($revisionId);

    while ($job !== null && $runs < 10) {
        Queue::fake();
        $job->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));
        $runs++;
        $job = Queue::pushed(DispatchCreatorSeriesChunk::class)->first();
    }

    expect($runs)->toBe(3)->and(DatabaseNotification::query()->where('type_key', 'creator_new_series')->count())->toBe(8);
});

test('AM13: a new approved revision of a published series is announced as an update only when enabled', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة قابلة للتحديث']);
    expect(e30s_series_notes($follower))->toHaveCount(1);

    config(['creator_series.notifications.on_revision' => false]);
    $draft = app(CreatorSeriesService::class)->revise($creator, $series->public_id);
    app(CreatorSeriesService::class)->submit($creator, $series->public_id);
    app(CreatorSeriesReviewService::class)->approve($draft->fresh(), e28_reviewer());
    expect(e30s_series_notes($follower))->toHaveCount(1);

    config(['creator_series.notifications.on_revision' => true]);
    $draft2 = app(CreatorSeriesService::class)->revise($creator, $series->public_id);
    app(CreatorSeriesService::class)->submit($creator, $series->public_id);
    app(CreatorSeriesReviewService::class)->approve($draft2->fresh(), e28_reviewer());

    $notes = e30s_series_notes($follower);
    expect($notes)->toHaveCount(2)->and($notes->pluck('data.title')->implode(' '))->toContain('حدّث');
});

test('AM13: the notification master switch and the social-category preference are respected', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    config(['creator_series.notifications.on_new_series' => false]);

    e30s_published($creator);
    expect(e30s_series_notes($follower))->toHaveCount(0);
});
