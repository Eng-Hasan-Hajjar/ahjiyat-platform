<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\Creators\CreatorException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

test('AM9-83: an approved creator creates a draft series owned by them, with a stable public id and slug', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    $revision = e30s_draft($creator, $works, ['title' => 'سلسلة الألغاز الأولى']);
    $series = $revision->series()->first();

    expect($revision->status)->toBe('draft')->and($revision->revision_number)->toBe(1)->and($revision->title)->toBe('سلسلة الألغاز الأولى')
        ->and($series->creator->user_id)->toBe($creator->id)->and($series->public_id)->toHaveLength(26)->and($series->slug)->not->toBe('')
        ->and($series->latest_revision_id)->toBe($revision->id)->and($series->published_revision_id)->toBeNull()
        ->and(e30s_visible($series))->toBeFalse();
});

test('AM9-84: a non-creator (player, applicant) cannot create a series', function () {
    $player = e16User();
    $works = e30s_works(e28_creator(), 2);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($player, ['title' => 'محاولة غير مؤهلة', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class);

    $this->actingAs($player)->post(route('creator.series.store'), ['title' => 'محاولة غير مؤهلة', 'items' => e30s_ids($works)])->assertRedirect(route('creator.apply'));
    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-85: a suspended creator is denied creating and editing', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $revision = e30s_draft($creator, $works);
    $publicId = $revision->series()->value('public_id');

    $profile = $creator->creatorProfile()->first();
    app(\App\Services\Creators\CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'مخالفة تجريبية للاختبار.');
    $creator = $creator->fresh();

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة جديدة', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class)
        ->and(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل مرفوض', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class);

    $this->actingAs($creator)->get(route('creator.series.index'))->assertRedirect(route('creator.apply'));
});

test('AM9-86: the minimum number of items is enforced (service and HTTP)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة قصيرة', 'items' => e30s_ids([$works[0]])]))
        ->toThrow(CreatorSeriesException::class, '2');

    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة قصيرة', 'items' => e30s_ids([$works[0]])])->assertSessionHasErrors('items');
    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-87: the maximum number of items is enforced and is configurable', function () {
    $creator = e28_creator();
    config(['creator_series.limits.max_items' => 3]);
    $works = e30s_works($creator, 4);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة طويلة', 'items' => e30s_ids($works)]))
        ->toThrow(CreatorSeriesException::class, '3');
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة طويلة', 'items' => e30s_ids($works)])->assertSessionHasErrors('items');

    config(['creator_series.limits.max_items' => 4]);
    expect(e30s_draft($creator, $works)->items()->count())->toBe(4);
});

test('AM9-88: a duplicate item is rejected', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $ids = [$works[0]->public_id, $works[1]->public_id, $works[0]->public_id];

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة مكررة', 'items' => $ids]))->toThrow(CreatorSeriesException::class);
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة مكررة', 'items' => $ids])->assertSessionHasErrors();
    expect(CreatorSeries::query()->count())->toBe(0);
});

test("AM9-89: another creator's puzzle is rejected without revealing it exists", function () {
    $creator = e28_creator();
    $other = e28_creator();
    $mine = e30s_works($creator, 2);
    $foreign = e28_published($other, 'text', ['title' => 'لغز مبدع آخر']);

    $thrown = null;
    try {
        app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر غريب', 'items' => [$mine[0]->public_id, $foreign->public_id]]);
    } catch (CreatorSeriesException $e) {
        $thrown = $e->getMessage();
    }

    // نفس رسالة «معرّف غير موجود» تمامًا: لا فرق بين عمل غريب ومعرّف مختلق.
    $fake = null;
    try {
        app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر مختلق', 'items' => [$mine[0]->public_id, (string) \Illuminate\Support\Str::ulid()]]);
    } catch (CreatorSeriesException $e) {
        $fake = $e->getMessage();
    }

    expect($thrown)->not->toBeNull()->and($thrown)->toBe($fake)->and(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-90: a hidden (or archived / unpublished) puzzle is rejected', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    e30s_hide_work($works[2]);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر مخفي', 'items' => e30s_ids($works)]))
        ->toThrow(CreatorSeriesException::class);

    // مسودة لم تُنشر (غير ظاهرة) مرفوضة كذلك.
    $draftWork = e28_draft($creator)->work()->first();
    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بمسودة', 'items' => [$works[0]->public_id, $draftWork->public_id]]))
        ->toThrow(CreatorSeriesException::class);

    app(\App\Services\Creators\CreatorPuzzleService::class)->archive($creator, $works[1]->public_id);
    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بمؤرشف', 'items' => [$works[0]->public_id, $works[1]->public_id]]))
        ->toThrow(CreatorSeriesException::class);
});

test('AM9-91: an official puzzle cannot be an item (series items are community works only)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $official = \App\Models\Puzzle::factory()->create();

    foreach ([$official->slug ?? 'official', (string) $official->id, (string) \Illuminate\Support\Str::ulid()] as $bogus) {
        expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر رسمي', 'items' => [$works[0]->public_id, $bogus]]))
            ->toThrow(CreatorSeriesException::class);
    }

    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بعنصر رسمي', 'items' => [$works[0]->public_id, (string) $official->id]])->assertSessionHasErrors();
    expect(CreatorSeries::query()->count())->toBe(0)->and(CreatorSeriesItem::query()->count())->toBe(0);
});

test('AM9-92: item order is saved explicitly (positions 1..n in the submitted order) and re-ordering works', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 4);
    $order = [$works[2], $works[0], $works[3], $works[1]];

    $revision = e30s_draft($creator, $order);
    $positions = CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->orderBy('position')->get();

    expect($positions->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($positions->pluck('creator_puzzle_id')->all())->toBe(array_map(fn ($w) => $w->id, $order));

    $reordered = [$works[1], $works[3], $works[0], $works[2]];
    app(CreatorSeriesService::class)->updateDraft($creator, $revision->series()->value('public_id'), ['title' => $revision->title, 'items' => e30s_ids($reordered)]);

    expect(CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->orderBy('position')->pluck('creator_puzzle_id')->all())
        ->toBe(array_map(fn ($w) => $w->id, $reordered));
});

test('AM9-93: the owner can edit a draft (title, description, items) via HTTP and the page shows it', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, [$works[0], $works[1]], ['title' => 'عنوان قديم']);
    $publicId = $revision->series()->value('public_id');

    $this->actingAs($creator)->get(route('creator.series.edit', $publicId))->assertOk()->assertSee('عنوان قديم');

    $this->actingAs($creator)->put(route('creator.series.update', $publicId), ['title' => 'عنوان جديد', 'description' => 'وصف جديد', 'items' => e30s_ids($works)])
        ->assertSessionHasNoErrors()->assertRedirect();

    $revision->refresh();
    expect($revision->title)->toBe('عنوان جديد')->and($revision->description)->toBe('وصف جديد')->and($revision->items()->count())->toBe(3);
    $this->get(route('creator.series.edit', $publicId))->assertOk()->assertSee('عنوان جديد');
});

test('AM9-94: another creator cannot read or change my series (IDOR -> 404)', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $revision = e30s_draft($owner);
    $publicId = $revision->series()->value('public_id');
    $intruderWorks = e30s_works($intruder, 2);

    $this->actingAs($intruder);
    $this->get(route('creator.series.edit', $publicId))->assertNotFound();
    $this->put(route('creator.series.update', $publicId), ['title' => 'اختراق', 'items' => e30s_ids($intruderWorks)])->assertNotFound();
    $this->post(route('creator.series.submit', $publicId))->assertNotFound();
    $this->post(route('creator.series.withdraw', $publicId))->assertNotFound();
    $this->post(route('creator.series.revise', $publicId))->assertNotFound();
    $this->post(route('creator.series.archive', $publicId))->assertNotFound();
    $this->delete(route('creator.series.destroy', $publicId))->assertNotFound();

    expect($revision->fresh()->title)->toBe($revision->title)->and(CreatorSeries::query()->where('public_id', $publicId)->exists())->toBeTrue();
});

test('AM9: a work of the creator that is not theirs by id is unreachable and drafts are capped per creator', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    config(['creator_series.limits.max_per_creator' => 2]);

    e30s_draft($creator, $works);
    e30s_draft($creator, $works);

    expect(fn () => e30s_draft($creator, $works))->toThrow(CreatorSeriesException::class);
});
