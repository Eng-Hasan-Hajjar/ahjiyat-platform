<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\OperationalAuditLog;
use App\Models\Puzzle;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Notifications\NotificationDispatcher;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('Y4-33 (33): a draft is submitted for review - pending status, timestamp, visible in the pending bucket', function () {
    $creator = e28_creator();
    $revision = e28_draft($creator);
    $publicId = $revision->work()->value('public_id');

    $this->actingAs($creator)->post(route('creator.puzzles.submit', $publicId))->assertRedirect(route('creator.puzzles.edit', $publicId))->assertSessionHas('success');

    $revision->refresh();
    expect($revision->status)->toBe('pending_review')->and($revision->submitted_at)->not->toBeNull();
    $this->get(route('creator.puzzles.index', ['status' => 'pending']))->assertOk()->assertSee($revision->title);
    expect($revision->work->fresh()->is_visible)->toBeFalse();
});

test('Y4-34 (34): a submitted draft is locked - no edit, no resubmit - until the creator withdraws it', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');
    $svc = app(CreatorPuzzleService::class);

    expect(fn () => $svc->updateDraft($creator, $publicId, e28_data('text', ['title' => 'تعديل محظور'])))->toThrow(CreatorException::class)
        ->and(fn () => $svc->submit($creator, $publicId))->toThrow(CreatorException::class);
    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'تعديل محظور']))->assertSessionHas('error');
    $this->get(route('creator.puzzles.edit', $publicId))->assertOk()->assertSee('قيد المراجعة');
    expect($revision->fresh()->title)->not->toBe('تعديل محظور');

    $svc->withdraw($creator, $publicId);
    expect($revision->fresh()->status)->toBe('draft')->and($revision->fresh()->submitted_at)->toBeNull();
});

test('Y4-35 (35): a reviewer requests changes - reason required and stored, creator notified, audit written', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    $svc = app(CreatorReviewService::class);

    expect(fn () => $svc->requestChanges($revision, $reviewer, 'x'))->toThrow(CreatorException::class);

    $svc->requestChanges($revision, $reviewer, 'وضّح صياغة السؤال أكثر من فضلك.');
    $revision->refresh();
    expect($revision->status)->toBe('changes_requested')->and($revision->review_note)->toBe('وضّح صياغة السؤال أكثر من فضلك.')->and($revision->reviewed_by)->toBe($reviewer->id)
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_changes_requested')->where('subject_id', $revision->id)->exists())->toBeTrue()
        ->and($creator->notifications()->count())->toBeGreaterThanOrEqual(2);   // اعتماد المبدع + طلب التعديل

    $this->actingAs($creator)->get(route('creator.puzzles.edit', $revision->work()->value('public_id')))->assertOk()->assertSee('وضّح صياغة السؤال');
});

test('Y4-36 (36): after changes are requested the creator edits and resubmits, and the second review approves', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');
    app(CreatorReviewService::class)->requestChanges($revision, $reviewer, 'الجواب غير واضح، حدّده بدقة.');

    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'عنوان معدّل بعد المراجعة', 'answer' => 'الممحاة']))->assertSessionHasNoErrors();
    $this->post(route('creator.puzzles.submit', $publicId))->assertSessionHas('success');

    $revision->refresh();
    expect($revision->status)->toBe('pending_review')->and($revision->title)->toBe('عنوان معدّل بعد المراجعة')->and($revision->review_note)->toBeNull();

    app(CreatorReviewService::class)->approve($revision, $reviewer);
    expect($revision->fresh()->status)->toBe('approved')->and($revision->work->fresh()->is_visible)->toBeTrue();
});

test('Y4-37 (37): a reviewer rejects - reason stored, notified, the revision is frozen, and the creator can start a fresh revision', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');

    app(CreatorReviewService::class)->reject($revision, e28_reviewer(), 'المحتوى لا يتوافق مع إرشادات النشر.');
    $revision->refresh();

    expect($revision->status)->toBe('rejected')->and($revision->review_note)->toBe('المحتوى لا يتوافق مع إرشادات النشر.')
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_rejected')->exists())->toBeTrue()
        ->and($revision->work->fresh()->is_visible)->toBeFalse()->and($revision->puzzle_id)->toBeNull();

    expect(fn () => app(CreatorPuzzleService::class)->updateDraft($creator, $publicId, e28_data()))->toThrow(CreatorException::class);
    expect(function () use ($revision) {
        $revision->title = 'تلاعب';
        $revision->save();
    })->toThrow(\LogicException::class);

    $next = app(CreatorPuzzleService::class)->revise($creator, $publicId);
    expect($next->revision_number)->toBe(2)->and($next->status)->toBe('draft');
});

test('Y4-38 (38): a reviewer approves - the work is published and visible, only by an authorized reviewer', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);

    expect(fn () => app(CreatorReviewService::class)->approve($revision, e16User()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($revision->fresh()->status)->toBe('pending_review');

    app(CreatorReviewService::class)->approve($revision, e28_reviewer(), 'ممتازة');

    $work = $revision->work->fresh();
    expect($revision->fresh()->status)->toBe('approved')->and($work->is_visible)->toBeTrue()->and($work->published_revision_id)->toBe($revision->id)
        ->and($work->published_title)->toBe($revision->title)->and($work->first_published_at)->not->toBeNull();

    // لا يُعتمد مرتين.
    expect(fn () => app(CreatorReviewService::class)->approve($revision->fresh(), e28_reviewer()))->toThrow(CreatorException::class);
});

test('Y4-39 (39): approval materializes exactly one canonical community puzzle through the official engine', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'sequence');
    expect(Puzzle::query()->where('origin', 'community')->count())->toBe(0);

    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    $revision->refresh();
    $puzzle = Puzzle::query()->findOrFail($revision->puzzle_id);
    expect(Puzzle::query()->where('origin', 'community')->count())->toBe(1)
        ->and($puzzle->origin)->toBe('community')->and($puzzle->is_active)->toBeFalse()->and((int) $puzzle->gem_reward)->toBe(0)->and((int) $puzzle->xp_reward)->toBe(0)
        ->and($puzzle->game_type)->toBe('sequence')->and($puzzle->solution_data)->not->toBeEmpty()
        ->and($revision->work->fresh()->published_puzzle_id)->toBe($puzzle->id)->and((int) $puzzle->max_attempts)->toBe((int) config('creators.puzzle.max_attempts'));
});

test('Y4-40 (40): the public payload is safe - no answer, hash, solution or config reaches a public page', function () {
    $creator = e28_creator();
    $text = e28_published($creator, 'text', ['answer' => 'جواب-فريد-٩٩٩', 'hint' => 'تلميح فريد عام']);
    $sequence = e28_published($creator, 'sequence', ['items' => ['ألف', 'باء', 'جيم', 'دال']]);

    foreach ([$text, $sequence] as $work) {
        $this->actingAs(e16User());
        $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
        e28_assert_no_secrets($html, ['جواب-فريد-٩٩٩']);
    }

    $guestHtml = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    e28_assert_no_secrets($guestHtml, ['جواب-فريد-٩٩٩']);

    // الحمولة العامة لا تتضمن الجواب المشفَّر للنسخة أيضًا (content مخفي من التسلسل).
    expect(array_key_exists('content', $text->latestRevision->toArray()))->toBeFalse();
});

test('Y4-41 (41): approval is audited with actor, work, revision and canonical puzzle', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    app(CreatorReviewService::class)->approve($revision, $reviewer);

    $log = OperationalAuditLog::query()->where('action', 'creator_submission_approved')->firstOrFail();
    expect($log->actor_user_id)->toBe($reviewer->id)->and($log->subject_id)->toBe($revision->id)->and($log->subject_type)->toBe(CreatorPuzzleRevision::class)
        ->and($log->metadata['work'])->toBe($revision->work()->value('public_id'))->and($log->metadata['revision'])->toBe(1)->and($log->metadata['puzzle_id'])->toBe($revision->fresh()->puzzle_id);
});

test('Y4-42 (42): a notification failure never rolls back an approval', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);

    $this->mock(NotificationDispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->atLeast()->once()->andThrow(new \RuntimeException('notification backend down')));   // يثبت أن مسار الفشل نُفِّذ فعلًا

    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    expect($revision->fresh()->status)->toBe('approved')->and($revision->work->fresh()->is_visible)->toBeTrue()->and(Puzzle::query()->where('origin', 'community')->count())->toBe(1)
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_approved')->count())->toBe(1);
});
