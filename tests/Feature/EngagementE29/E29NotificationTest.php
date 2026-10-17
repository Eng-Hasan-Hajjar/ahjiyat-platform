<?php

use App\Jobs\DispatchCreatorPublicationChunk;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_notes(User $user, string $type = 'creator_new_publication')
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', $type)->get();
}

/** يرسل عملًا للمراجعة دون اعتماد (للتحكم بلحظة الاعتماد). */
function e29_pending_for(User $creator, array $over = []): CreatorPuzzleRevision
{
    return e28_pending($creator, 'text', $over);
}

test('AA5-50: a new approved publication queues the fan-out job for followers only when there are followers', function () {
    Queue::fake();
    $creator = e28_creator();
    $lonely = e28_creator();
    e29_follow(e16User(), $creator);

    e28_published($creator, 'text', ['title' => 'له متابعون']);
    e28_published($lonely, 'text', ['title' => 'بلا متابعين']);

    Queue::assertPushed(DispatchCreatorPublicationChunk::class, 1);
});

test('AA5-51: a notification failure never rolls back the publication', function () {
    $creator = e28_creator();
    e29_follow(e16User(), $creator);

    $this->mock(NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andReturnUsing(function ($user, $type) {
            if ($type === NotificationType::CreatorNewPublication) {
                throw new RuntimeException('simulated fan-out failure');
            }

            return DispatchResult::Created;
        });
    });

    $work = e28_published($creator, 'text', ['title' => 'نشر رغم فشل الإشعار']);

    expect($work->fresh()->is_visible)->toBeTrue()->and($work->fresh()->published_puzzle_id)->not->toBeNull()
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->value('status'))->toBe('approved');
});

test('AA5-52: a follower receives exactly one correct internal notification', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);

    $work = e28_published($creator, 'text', ['title' => 'لغز الإشعار الصحيح']);

    $notes = e29_notes($follower);
    expect($notes)->toHaveCount(1);
    $data = $notes[0]->data;
    expect($data['category'])->toBe('social')->and($data['title'])->toContain('لغز الإشعار الصحيح')->and($data['body'])->toContain('بلا جوائز اقتصادية')
        ->and($data['action_route'])->toBe('community.puzzles.show')->and($data['action_params']['work'])->toBe($work->public_id)
        ->and($notes[0]->idempotency_key)->toStartWith('creator-publication:');
});

test('AA5-53: a non-follower receives nothing, and neither does the creator', function () {
    $creator = e28_creator();
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);

    e28_published($creator, 'text');

    expect(e29_notes($stranger))->toHaveCount(0)->and(e29_notes($creator))->toHaveCount(0)->and(e29_notes($follower))->toHaveCount(1);
});

test('AA5-54: running the same job twice never duplicates a notification', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $work = e28_published($creator, 'text');
    $revisionId = (int) $work->fresh()->published_revision_id;

    (new DispatchCreatorPublicationChunk($revisionId))->handle(app(NotificationDispatcher::class));
    (new DispatchCreatorPublicationChunk($revisionId))->handle(app(NotificationDispatcher::class));

    expect(e29_notes($follower))->toHaveCount(1);
});

test('AA5-55: a publication hidden before the worker runs is not announced', function () {
    Queue::fake();
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $work = e28_published($creator, 'text');
    Queue::assertPushed(DispatchCreatorPublicationChunk::class);

    e29_hide($work);   // قبل أن يعمل العامل

    (new DispatchCreatorPublicationChunk((int) $work->fresh()->published_revision_id))->handle(app(NotificationDispatcher::class));

    expect(e29_notes($follower))->toHaveCount(0);
});

test('AA5-56: a large follower set is processed in bounded chunks chained by id cursor', function () {
    config(['player_notifications.chunk_size' => 3]);
    Queue::fake();
    $creator = e28_creator();
    $followers = collect(range(1, 8))->map(fn () => e16User());
    $followers->each(fn ($u) => e29_follow($u, $creator));

    $work = e28_published($creator, 'text');
    $revisionId = (int) $work->fresh()->published_revision_id;

    $runs = 0;
    $job = new DispatchCreatorPublicationChunk($revisionId);

    while ($job !== null && $runs < 10) {
        Queue::fake();   // يسجّل ما يُدفع من الدفعة التالية فقط
        $job->handle(app(NotificationDispatcher::class));
        $runs++;
        $job = Queue::pushed(DispatchCreatorPublicationChunk::class)->first();
    }

    expect($runs)->toBe(3)->and(DatabaseNotification::query()->where('type_key', 'creator_new_publication')->count())->toBe(8);
});

test('AA5-57: favoriting never notifies anyone', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $before = DatabaseNotification::query()->count();

    e29_favorite(e16User(), $work);

    expect(DatabaseNotification::query()->count())->toBe($before);
});

test('AA5-58: rating or playing never notifies anyone', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $before = DatabaseNotification::query()->count();

    e29_ratings($work, [5, 4, 3]);

    expect(DatabaseNotification::query()->count())->toBe($before);
});

test('AA5-59: the existing social preference and the global switch are respected (no second preference system)', function () {
    $creator = e28_creator();
    $muted = e16User();
    $active = e16User();
    e29_follow($muted, $creator);
    e29_follow($active, $creator);
    app(NotificationPreferenceService::class)->update($muted, ['social_enabled' => false]);

    e28_published($creator, 'text');

    expect(e29_notes($muted))->toHaveCount(0)->and(e29_notes($active))->toHaveCount(1);
});

test('AA5-60: frozen or unverified followers are skipped and a new revision notifies again once', function () {
    $creator = e28_creator();
    $frozen = e16User();
    $ok = e16User();
    e29_follow($frozen, $creator);
    e29_follow($ok, $creator);
    $frozen->forceFill(['is_frozen' => true])->save();

    $work = e28_published($creator, 'text', ['title' => 'النشر الأول']);
    expect(e29_notes($frozen))->toHaveCount(0)->and(e29_notes($ok))->toHaveCount(1);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'النسخة المحدَّثة']));
    $revision = app(CreatorPuzzleService::class)->submit($creator, $work->public_id);
    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    expect(e29_notes($ok))->toHaveCount(2);
});

test('AA5-61: the revision notification can be switched off by config', function () {
    config(['engagement.notifications.on_revision' => false]);
    $creator = e28_creator();
    $ok = e16User();
    e29_follow($ok, $creator);
    $work = e28_published($creator, 'text');
    expect(e29_notes($ok))->toHaveCount(1);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'نسخة بلا إشعار']));
    app(CreatorReviewService::class)->approve(app(CreatorPuzzleService::class)->submit($creator, $work->public_id), e28_reviewer());

    expect(e29_notes($ok))->toHaveCount(1);
});
