<?php

use App\Models\CreatorSeriesRevision;
use App\Services\CreatorFinance\QualifiedUnitsQuery;
use App\Services\CreatorPremium\CreatorPremiumAccess;
use App\Services\CreatorPremium\CreatorPremiumPublishGuard;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (5/9) - العزل عن صندوق المبدعين E39 (مستقبلي فقط، أصغر تغيير على QualifiedUnitsQuery) وحراس النشر/الأرشفة بعد البيع:
| الإضافة وإعادة الترتيب مسموحتان والمالكون يحتفظون بوصولهم، وحذف عنصر مباع يحتاج تأكيدًا صريحًا مع ملاحظة وتدقيق.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

/** وحدات المبدع المؤهَّلة (للصندوق) في نافذة واسعة تغطي كل الاختبار. */
function e43_fund_units(int $profileId, ?Carbon $end = null): int
{
    $rows = (new QualifiedUnitsQuery)->perCreator(Carbon::parse('2026-12-01 00:00:00'), $end ?? Carbon::parse('2027-02-01 00:00:00'), 100);

    foreach ($rows as $row) {
        if ($row['creator_profile_id'] === $profileId) {
            return $row['qualified_units'];
        }
    }

    return 0;
}

/** نسخة جديدة مرسلة للمراجعة من سلسلة منشورة، بعناصر معطاة (مبدع السلسلة). */
function e43_pending_revision(array $world, array $works): CreatorSeriesRevision
{
    $svc = app(CreatorSeriesService::class);
    $id = $world['series']->public_id;
    $svc->revise($world['user'], $id);
    $svc->updateDraft($world['user'], $id, ['title' => 'نسخة معدّلة '.fake()->unique()->numerify('###'), 'description' => 'وصف قصير للنسخة.', 'items' => e30s_ids($works)]);

    return $svc->submit($world['user'], $id);
}

// ───────────── عزل الصندوق (E39) ─────────────

test('E43-FI-01: plays on a work are counted for the Creator Fund while no premium offer has ever been active', function () {
    $world = e43_world(['offer' => false]);
    e30s_solve(e16User(), $world['works'][0]);

    expect(e43_fund_units($world['profile']->id))->toBe(1);
});

test('E43-FI-02: a draft offer that was never activated does not exclude anything from the fund', function () {
    $world = e43_world(['activate' => false]);
    e30s_solve(e16User(), $world['works'][0]);

    expect(e43_fund_units($world['profile']->id))->toBe(1);
});

test('E43-FI-03: once the offer is active, the new buyer\'s qualified play no longer feeds the fund - but the earlier play still counts (prospective only)', function () {
    $world = e43_world(['offer' => false]);
    e30s_solve(e16User(), $world['works'][0]);
    $beforeActivation = now();
    e43_tick(5);
    e43_offer($world['user'], $world['series'], '5.00');

    $buyer = e43_buy($world)['user'];
    e43_tick(1);
    e30s_solve($buyer, $world['works'][1]);

    expect(e43_fund_units($world['profile']->id))->toBe(1)
        ->and(e43_fund_units($world['profile']->id, $beforeActivation->copy()->addMinute()))->toBe(1);
});

test('E43-FI-04: a grandfathered player solving a premium work AFTER activation is excluded by the attempt time window', function () {
    $world = e43_world(['offer' => false]);
    $old = e16User();
    e30s_wrong($old, $world['works'][2]); // لعب قديم قبل التفعيل: يمنح تقادمًا على هذه الأحجية فقط
    e43_tick(5);
    e43_offer($world['user'], $world['series'], '5.00');
    e43_tick(1);

    expect(e43_access_service()->decide($old, e43_fund_work($world['works'][2]), CreatorPremiumAccess::ATTEMPT)->allowed)->toBeTrue();
    e30s_solve($old, $world['works'][2]);

    expect(e43_fund_units($world['profile']->id))->toBe(0);
});

function e43_fund_work(\App\Models\CreatorPuzzle $work): \App\Models\CreatorPuzzle
{
    return \App\Models\CreatorPuzzle::query()->visible()->withPremiumLock()->where('public_id', $work->public_id)->firstOrFail();
}

test('E43-FI-05: a paused offer still excludes (paused is still premium); retiring it brings later plays back into the fund while the window stays excluded', function () {
    $world = e43_world();
    $buyer = e43_buy($world)['user'];
    e43_tick(1);
    e30s_solve($buyer, $world['works'][0]); // داخل نافذة العرض ⇒ مستبعد

    e43_offers()->pause($world['user'], $world['offer']->public_id);
    e43_tick(1);
    e30s_solve($buyer, $world['works'][1]); // الموقوف ما زال مميزًا ⇒ مستبعد
    expect(e43_fund_units($world['profile']->id))->toBe(0);

    e43_tick(1);
    e43_offers()->retire($world['user'], $world['offer']->public_id); // النافذة نصف مفتوحة [التفعيل، السحب): لعب لحظة السحب نفسها يُحتسب حرًّا، لذا نفصل بدقيقة
    e43_tick(1);
    $free = e16User();
    e30s_solve($free, $world['works'][2]); // بعد الإغلاق: السلسلة حرّة ⇒ تُحتسب

    expect(e43_fund_units($world['profile']->id))->toBe(1);
});

test('E43-FI-06: plays on works of a different, free series (or of no series) are never touched by another series\' offer', function () {
    $world = e43_world();
    $extra = e30s_works($world['user'], 2);
    e30s_published($world['user'], $extra); // سلسلة ثانية حرّة
    e43_tick(1);
    e30s_solve(e16User(), $extra[0]);
    $loose = e28_published($world['user'], 'text');
    e30s_solve(e16User(), $loose);

    expect(e43_fund_units($world['profile']->id))->toBe(2);
});

test('E43-FI-07: history is stable - a window that closed before activation counts exactly the same before and after the offer exists', function () {
    $world = e43_world(['offer' => false]);
    e30s_solve(e16User(), $world['works'][0]);
    e30s_solve(e16User(), $world['works'][1]);
    $windowEnd = now()->copy()->addMinute();
    $before = e43_fund_units($world['profile']->id, $windowEnd);
    e43_tick(10);

    e43_offer($world['user'], $world['series'], '5.00');
    e43_tick(1);

    expect($before)->toBe(2)->and(e43_fund_units($world['profile']->id, $windowEnd))->toBe(2);
});

test('E43-FI-08: the fund query stays one aggregate statement (no per-attempt loop) even with premium exclusion', function () {
    $world = e43_world();

    for ($i = 0; $i < 4; $i++) {
        e30s_solve(e43_buy($world)['user'], $world['works'][$i % 3]); // مالك فعّال: لعبه داخل نافذة العرض ⇒ مستبعد
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    e43_fund_units($world['profile']->id);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

// ───────────── حراس النشر بعد البيع ─────────────

test('E43-PG-01: active owners are detected only while access is active (a refund revokes it)', function () {
    $world = e43_world();
    $guard = app(CreatorPremiumPublishGuard::class);
    expect($guard->hasActiveOwners($world['series']))->toBeFalse();

    $bought = e43_buy($world);
    expect($guard->hasActiveOwners($world['series']))->toBeTrue();

    e43_tick(1);
    app(\App\Services\CreatorPremium\CreatorPremiumRefundService::class)->refund($world['admin'], $bought['purchase']->public_id, 'user_request', 'استرداد لاختبار الحارس', false);

    expect($guard->hasActiveOwners($world['series']))->toBeFalse();
});

test('E43-PG-02: an additive revision is approved normally, owners keep access and also receive the new work; non-owners stay locked', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $added = e28_published($world['user'], 'text');
    $revision = e43_pending_revision($world, array_merge($world['works'], [$added]));

    expect(app(CreatorPremiumPublishGuard::class)->soldItemsRemoved($world['series'], $revision))->toBe([]);

    app(CreatorSeriesReviewService::class)->approve($revision, e28_reviewer());
    $service = e43_access_service();

    expect($service->decide($bought['user'], e43_fund_work($added), CreatorPremiumAccess::VIEW)->allowed)->toBeTrue()
        ->and($service->decide(e16User(), e43_fund_work($added), CreatorPremiumAccess::VIEW)->allowed)->toBeFalse()
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue();
});

test('E43-PG-03: a pure re-order of the sold items is allowed with no override', function () {
    $world = e43_world();
    e43_buy($world);
    $revision = e43_pending_revision($world, array_reverse($world['works']));

    expect(app(CreatorPremiumPublishGuard::class)->soldItemsRemoved($world['series'], $revision))->toBe([])
        ->and(app(CreatorSeriesReviewService::class)->approve($revision, e28_reviewer())->status)->toBe(CreatorSeriesRevision::STATUS_APPROVED);
});

test('E43-PG-04: dropping a sold work is refused without an explicit override, the published revision stays untouched, and nothing is half-applied', function () {
    $world = e43_world();
    e43_buy($world);
    $before = $world['series']->fresh()->published_revision_id;
    $revision = e43_pending_revision($world, [$world['works'][0], $world['works'][1]]);

    expect(app(CreatorPremiumPublishGuard::class)->soldItemsRemoved($world['series']->fresh(), $revision))->toBe([$world['works'][2]->id])
        ->and(e43_reason_series(fn () => app(CreatorSeriesReviewService::class)->approve($revision, e28_reviewer())))->toBeTrue()
        ->and($world['series']->fresh()->published_revision_id)->toBe($before)->and($revision->fresh()->status)->toBe(CreatorSeriesRevision::STATUS_PENDING_REVIEW)
        ->and(DB::table('operational_audit_logs')->where('action', 'creator_premium_sold_items_removed')->count())->toBe(0);
});

function e43_reason_series(callable $call): bool
{
    try {
        $call();
    } catch (CreatorSeriesException) {
        return true;
    }

    return false;
}

test('E43-PG-05: the override needs a note; with note it is approved, audited once, and the removed work becomes free while owners keep the rest', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $revision = e43_pending_revision($world, [$world['works'][0], $world['works'][1]]);
    $review = app(CreatorSeriesReviewService::class);
    $reviewer = e28_reviewer();

    expect(e43_reason_series(fn () => $review->approve($revision, $reviewer, null, true)))->toBeTrue();

    $review->approve($revision->fresh(), $reviewer, 'حذف عنصر مباع بطلب المبدع وتأكيد المراجع.', true);
    $service = e43_access_service();

    expect($revision->fresh()->status)->toBe(CreatorSeriesRevision::STATUS_APPROVED)->and($world['series']->fresh()->published_revision_id)->toBe($revision->id)
        ->and(DB::table('operational_audit_logs')->where('action', 'creator_premium_sold_items_removed')->count())->toBe(1)
        ->and($service->isLocked(e43_fund_work($world['works'][2])))->toBeFalse()
        ->and($service->isLocked(e43_fund_work($world['works'][0])))->toBeTrue()
        ->and($service->decide($bought['user'], e43_fund_work($world['works'][0]), CreatorPremiumAccess::VIEW)->allowed)->toBeTrue()
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue();
});

test('E43-PG-06: a series with no owners can drop items freely (no override needed, nothing audited)', function () {
    $world = e43_world();
    $revision = e43_pending_revision($world, [$world['works'][0], $world['works'][1]]); // الحد الأدنى للسلسلة أحجيتان

    app(CreatorSeriesReviewService::class)->approve($revision, e28_reviewer());

    expect($revision->fresh()->status)->toBe(CreatorSeriesRevision::STATUS_APPROVED)
        ->and(DB::table('operational_audit_logs')->where('action', 'creator_premium_sold_items_removed')->count())->toBe(0);
});

test('E43-PG-07: archiving is blocked while an offer is active or paused, and allowed once the offer is closed', function () {
    $world = e43_world();
    $svc = app(CreatorSeriesService::class);

    expect(e43_reason_series(fn () => $svc->archive($world['user'], $world['series']->public_id)))->toBeTrue();

    e43_offers()->pause($world['user'], $world['offer']->public_id);
    expect(e43_reason_series(fn () => $svc->archive($world['user'], $world['series']->public_id)))->toBeTrue();

    e43_offers()->retire($world['user'], $world['offer']->public_id);
    $svc->archive($world['user'], $world['series']->public_id);

    expect($world['series']->fresh()->isArchived())->toBeTrue();
});

test('E43-PG-08: premium to free keeps normal visibility and every history row (access, sale, ledger) after the offer is retired', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $entries = DB::table('creator_earning_entries')->count();

    e43_tick(1);
    e43_offers()->retire($world['user'], $world['offer']->public_id);

    expect(e43_access_service()->isLocked(e43_fund_work($world['works'][0])))->toBeFalse()
        ->and(e43_access_service()->decide(e16User(), e43_fund_work($world['works'][0]), CreatorPremiumAccess::VIEW)->allowed)->toBeTrue()
        ->and(e43_access_row($bought['user'], $world['series'])->isActive())->toBeTrue()
        ->and($bought['sale']->fresh()->status)->toBe('credited')->and(DB::table('creator_earning_entries')->count())->toBe($entries)
        ->and(DB::table('digital_purchases')->where('id', $bought['purchase']->id)->value('status'))->toBe('verified');
});
