<?php

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** عمل منشور بنسخة جديدة معتمَدة فوقه. @return array{0: User, 1: CreatorPuzzle, 2: CreatorPuzzleRevision, 3: CreatorPuzzleRevision} */
function e28_revised(array $v1 = [], array $v2 = []): array
{
    $creator = e28_creator();
    $work = e28_published($creator, 'text', $v1 + ['title' => 'العنوان القديم', 'answer' => 'قديم']);
    $first = $work->publishedRevision ?? CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);

    $svc = app(CreatorPuzzleService::class);
    $second = $svc->revise($creator, $work->public_id);
    $svc->updateDraft($creator, $work->public_id, e28_data('text', $v2 + ['title' => 'العنوان الجديد', 'answer' => 'جديد']));
    $svc->submit($creator, $work->public_id);

    return [$creator, $work, $first, $second->fresh()];
}

test('Y5-43 (43): published content cannot be mutated directly - creator route, service, revision model and canonical puzzle all refuse', function () {
    $creator = e28_creator();
    $work = e28_published($creator);
    $publicId = $work->public_id;
    $revision = CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);

    expect(fn () => app(CreatorPuzzleService::class)->updateDraft($creator, $publicId, e28_data('text', ['title' => 'تعديل مباشر'])))->toThrow(CreatorException::class);
    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'تعديل مباشر']))->assertSessionHas('error');

    expect(function () use ($revision) {
        $revision->forceFill(['title' => 'تعديل مباشر'])->save();
    })->toThrow(\LogicException::class);
    expect(function () use ($work) {
        $work->publishedPuzzle->forceFill(['title' => 'تعديل مباشر'])->save();
    })->toThrow(\LogicException::class);

    expect($revision->fresh()->title)->not->toBe('تعديل مباشر')->and($work->fresh()->published_title)->not->toBe('تعديل مباشر');
});

test('Y5-44 (44): editing a published work creates a new revision while the published one stays live', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'المنشورة']);
    $publishedRevisionId = $work->published_revision_id;

    $this->actingAs($creator)->post(route('creator.puzzles.revise', $work->public_id))->assertSessionHas('success');

    $work->refresh();
    $new = CreatorPuzzleRevision::query()->findOrFail($work->latest_revision_id);
    expect($new->revision_number)->toBe(2)->and($new->status)->toBe('draft')->and($new->id)->not->toBe($publishedRevisionId)
        ->and($work->published_revision_id)->toBe($publishedRevisionId)->and($work->is_visible)->toBeTrue()
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->count())->toBe(2);

    // لا نسختان مفتوحتان معًا.
    expect(fn () => app(CreatorPuzzleService::class)->revise($creator, $work->public_id))->toThrow(CreatorException::class);
});

test('Y5-45 (45): the old version stays intact and public while a new revision is edited or pending', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'العنوان القديم', 'prompt' => 'سؤال النسخة القديمة الأصلي هنا']);
    $oldRevision = CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);
    $oldPuzzleId = $work->published_puzzle_id;
    $snapshot = $oldRevision->only(['title', 'status', 'puzzle_id', 'kind']);
    $svc = app(CreatorPuzzleService::class);

    $svc->revise($creator, $work->public_id);
    $svc->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'العنوان الجديد', 'prompt' => 'سؤال النسخة الجديدة المختلف هنا']));
    $svc->submit($creator, $work->public_id);

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('العنوان القديم')->and($html)->toContain('سؤال النسخة القديمة الأصلي هنا')->and($html)->not->toContain('سؤال النسخة الجديدة المختلف هنا');
    expect($oldRevision->fresh()->only(['title', 'status', 'puzzle_id', 'kind']))->toBe($snapshot)->and($work->fresh()->published_puzzle_id)->toBe($oldPuzzleId);
});

test('Y5-46 (46): historical attempts stay attached to the old version after a new version is published', function () {
    [$creator, $work, $first, $second] = e28_revised();
    $player = e16User();
    $oldPuzzle = Puzzle::query()->findOrFail($first->puzzle_id);

    // اللاعب يحاول النسخة الحالية (القديمة) قبل اعتماد الجديدة.
    app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'خطأ');
    $attempt = CommunityPuzzleAttempt::query()->where('user_id', $player->id)->firstOrFail();
    expect($attempt->puzzle_id)->toBe($oldPuzzle->id);

    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $attempt->refresh();
    expect($attempt->puzzle_id)->toBe($oldPuzzle->id)->and($attempt->is_correct)->toBeFalse()->and($attempt->attempt_number)->toBe(1);

    // الحالة بالنسخة الجديدة نظيفة (محاولاتها مستقلة) والتاريخ باقٍ بالقديمة.
    $newPuzzle = $work->fresh()->publishedPuzzle;
    $state = app(CommunityPlayService::class)->state($player, $newPuzzle);
    expect($state['used'])->toBe(0)->and(app(CommunityPlayService::class)->state($player, $oldPuzzle)['used'])->toBe(1)
        ->and(CommunityPuzzleAttempt::query()->where('puzzle_id', $oldPuzzle->id)->count())->toBe(1);
});

test('Y5-47 (47): approving the new revision creates a new canonical version - a second community puzzle, never an overwrite', function () {
    [, $work, $first, $second] = e28_revised();
    $oldPuzzleSnapshot = Puzzle::query()->findOrFail($first->puzzle_id)->only(['title', 'prompt', 'answer_hash']);

    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $second->refresh();
    expect($second->puzzle_id)->not->toBeNull()->and($second->puzzle_id)->not->toBe($first->puzzle_id)
        ->and(Puzzle::query()->where('origin', 'community')->count())->toBe(2)
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->whereNotNull('puzzle_id')->distinct()->count('puzzle_id'))->toBe(2)
        ->and(Puzzle::query()->findOrFail($first->puzzle_id)->only(['title', 'prompt', 'answer_hash']))->toBe($oldPuzzleSnapshot);
});

test('Y5-48 (48): the current publication moves to the new version - snapshot, public page and canonical id', function () {
    [, $work, $first, $second] = e28_revised();
    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $work->refresh();
    expect($work->published_revision_id)->toBe($second->id)->and($work->published_puzzle_id)->toBe($second->fresh()->puzzle_id)->and($work->published_title)->toBe('العنوان الجديد')->and($work->is_visible)->toBeTrue();

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('العنوان الجديد')->and($html)->not->toContain('العنوان القديم');

    // الجواب الجديد هو المعتمد للّاعبين من الآن.
    $player = e16User();
    $svc = app(CommunityPlayService::class);
    expect($svc->attempt($player, $work, 'قديم')['correct'])->toBeFalse()->and($svc->attempt($player, $work, 'جديد')['correct'])->toBeTrue();
});
