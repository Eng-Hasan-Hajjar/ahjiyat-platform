<?php

use App\Models\InventoryTransaction;
use App\Models\Season;
use App\Models\SeasonWriterAward;
use App\Models\SeasonWriterEntry;
use App\Models\StoreItem;
use App\Models\UserInventoryItem;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E44 / الإنهاء والجوائز والدوران: لقطة نهائية صريحة ومتكرّرة الأمان، أول ثلاثة مراكز، «خارج الموسم التالي» لأدنى K فقط وللموسم التالي مباشرة دون حظر،
| جوائز تجميلية عبر المخزون ومالية كالتزام يدوي قابل للتدقيق، مطابقة الدفتر قبل الإنهاء، وإشعارات بمفاتيح دلالية.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

/** دوري مُنهى جاهز: $stars نجوم لكل كاتب بالترتيب، ثم الإغلاق. @return array{season: Season, admin: \App\Models\User, writers: list<array<string, mixed>>} */
function e44_closed_league(array $stars, array $o = []): array
{
    $league = e44_league(count($stars), $o);
    e44_cast($league['writers'], $stars);
    e44_after_close();

    return $league;
}

// ───────────────────────────── ترتيب النتائج ─────────────────────────────

test('E44-FN-01: top three get podium outcomes only when they have at least one star; the lowest K non-podium writers are ineligible next season', function () {
    $f = e44_finalizer();

    expect($f->assignOutcomes([10, 8, 6, 4, 3, 2, 1], 5))->toBe(['first_place', 'second_place', 'third_place', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season'])
        ->and($f->assignOutcomes([9, 8, 7, 6, 5, 4, 3, 2, 1, 1], 5))->toBe(['first_place', 'second_place', 'third_place', 'participant', 'participant', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season'])
        ->and($f->assignOutcomes([5, 4, 3, 2, 1], 0))->toBe(['first_place', 'second_place', 'third_place', 'participant', 'participant']);
});

test('E44-FN-02: writers without stars never take a podium place, and a podium holder is never marked ineligible', function () {
    $f = e44_finalizer();

    expect($f->assignOutcomes([3, 0, 0, 0, 0, 0, 0], 5))->toBe(['first_place', 'participant', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season'])
        ->and($f->assignOutcomes([0, 0, 0], 5))->toBe(['ineligible_next_season', 'ineligible_next_season', 'ineligible_next_season'])
        ->and($f->assignOutcomes([4, 3], 5))->toBe(['first_place', 'second_place'])
        ->and($f->assignOutcomes([], 5))->toBe([]);
});

// ───────────────────────────── الإنهاء ─────────────────────────────

test('E44-FN-03: finalization is refused while voting is open, not yet open, unconfigured, disabled, empty, or for lack of the finalize permission', function () {
    $open = e44_league(1);
    e44_vote($open['writers'][0], 2);

    expect(e44_reason(fn () => e44_finalizer()->finalize($open['season'], $open['admin'])))->toBe('voting_open')
        ->and(e44_reason(fn () => e44_finalizer()->finalize($open['season'], e44_staff(['seasonal-recognition.manage']))))->toBe('forbidden');

    $unconfigured = e44_league(1, ['open' => false]);
    expect(e44_reason(fn () => e44_finalizer()->finalize($unconfigured['season'], $unconfigured['admin'])))->toBe('not_configured');

    e44_settings()->saveWriterSettings($unconfigured['season'], ['opens_at' => '2026-12-15 00:00:00', 'closes_at' => '2026-12-20 12:00:00'], $unconfigured['admin']);
    expect(e44_reason(fn () => e44_finalizer()->finalize($unconfigured['season'], $unconfigured['admin'])))->toBe('not_open_yet');

    $empty = e44_season();
    e44_enable($empty);
    e44_open($empty, ['closes_at' => '2026-12-20 12:00:00']);
    e44_after_close();
    expect(e44_reason(fn () => e44_finalizer()->finalize($empty, e44_admin())))->toBe('no_writers');

    config(['seasonal_recognition.writers.enabled' => false]);
    expect(e44_reason(fn () => e44_finalizer()->finalize($open['season'], $open['admin'])))->toBe('writers_disabled');
});

test('E44-FN-04: finalizing writes a complete, contiguous, immutable snapshot with top three, participants and the ineligible tail', function () {
    $league = e44_closed_league([9, 7, 5, 3, 1], ['eliminated_count' => 1]);

    $result = e44_finalizer()->finalize($league['season'], $league['admin']);

    expect($result)->toBe(['finalized' => true, 'already' => false, 'writers' => 5, 'podium' => 3, 'eliminated' => 1, 'awards' => 0]);

    $entries = SeasonWriterEntry::query()->where('season_id', $league['season']->id)->orderBy('final_rank')->get();

    expect($entries->pluck('final_rank')->map(fn ($v) => (int) $v)->all())->toBe([1, 2, 3, 4, 5])
        ->and($entries->pluck('final_stars')->map(fn ($v) => (int) $v)->all())->toBe([9, 7, 5, 3, 1])
        ->and($entries->pluck('outcome')->all())->toBe(['first_place', 'second_place', 'third_place', 'participant', 'ineligible_next_season'])
        ->and($entries->every(fn ($e) => $e->finalized_at !== null))->toBeTrue()
        ->and($entries->pluck('public_id')->all())->toBe(array_map(fn ($w) => $w['entry']->public_id, $league['writers']));

    $setting = e44_settings()->settingFor($league['season']);

    expect($setting->writers_finalized_at)->not->toBeNull()
        ->and((int) $setting->writers_finalized_by)->toBe((int) $league['admin']->id)
        ->and(e44_audit('seasonal_writers_finalized'))->toBe(1)
        ->and(app(SeasonWriterReconciler::class)->inspect($league['season']))->toBe([]);
});

test('E44-FN-05: a writer with no stars is not placed on the podium even when the roster is small', function () {
    $league = e44_closed_league([2, 0, 0]);
    e44_finalizer()->finalize($league['season'], $league['admin']);

    $outcomes = SeasonWriterEntry::query()->where('season_id', $league['season']->id)->orderBy('final_rank')->pluck('outcome')->all();

    expect($outcomes)->toBe(['first_place', 'ineligible_next_season', 'ineligible_next_season']);
});

test('E44-FN-06: finalizing twice is idempotent — no second snapshot, awards, audit entries or notifications', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([5, 3], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id]]]);

    e44_finalizer()->finalize($league['season'], $league['admin']);
    $snapshot = SeasonWriterEntry::query()->where('season_id', $league['season']->id)->orderBy('id')->get(['id', 'final_rank', 'final_stars', 'outcome', 'finalized_at'])->toArray();
    $awards = SeasonWriterAward::query()->count();
    $notes = DB::table('notifications')->where('type_key', 'writer_final_standing')->count();

    $second = e44_finalizer()->finalize($league['season'], $league['admin']);

    expect($second)->toBe(['finalized' => false, 'already' => true, 'writers' => 0, 'podium' => 0, 'eliminated' => 0, 'awards' => 0])
        ->and(SeasonWriterEntry::query()->where('season_id', $league['season']->id)->orderBy('id')->get(['id', 'final_rank', 'final_stars', 'outcome', 'finalized_at'])->toArray())->toBe($snapshot)
        ->and(SeasonWriterAward::query()->count())->toBe($awards)
        ->and(DB::table('notifications')->where('type_key', 'writer_final_standing')->count())->toBe($notes)
        ->and(e44_audit('seasonal_writers_finalized'))->toBe(1);
});

test('E44-FN-07: the final snapshot cannot be edited or deleted, and live aggregates cannot be moved afterwards', function () {
    $league = e44_closed_league([4, 2]);
    e44_finalizer()->finalize($league['season'], $league['admin']);
    $entry = SeasonWriterEntry::query()->findOrFail($league['writers'][0]['entry']->id);

    expect(fn () => $entry->forceFill(['final_rank' => 2])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->forceFill(['outcome' => 'participant'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->forceFill(['stars_total' => 99])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->forceFill(['status' => 'withdrawn'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->forceFill(['season_id' => 12345])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);

    expect((int) SeasonWriterEntry::query()->findOrFail($entry->id)->final_rank)->toBe(1);
});

test('E44-FN-08: after finalization votes, roster changes, links and settings are all frozen', function () {
    $league = e44_closed_league([4, 2]);
    e44_finalizer()->finalize($league['season'], $league['admin']);
    $writer = $league['writers'][0];
    $newcomer = e28_creator();

    expect(e44_reason(fn () => e44_voting()->allocate(e44_staff_voter(), $writer['entry']->fresh(), 1)))->toBe('finalized')
        ->and(e44_reason(fn () => e44_roster()->addWriter($league['season'], $newcomer->creatorProfile()->firstOrFail(), $league['admin'])))->toBe('finalized')
        ->and(e44_reason(fn () => e44_roster()->removeWriter($writer['entry']->fresh(), $league['admin'])))->toBe('finalized')
        ->and(e44_reason(fn () => e44_roster()->linkSeries($writer['entry']->fresh(), e30s_published($writer['user'], e30s_works($writer['user'], 2)), $league['admin'])))->toBe('finalized')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($league['season'], ['capacity' => 50], $league['admin'])))->toBe('finalized');
});

test('E44-FN-09: the public board and season hub read the frozen snapshot even if the live aggregate is corrupted afterwards', function () {
    $league = e44_closed_league([6, 3]);
    e44_finalizer()->finalize($league['season'], $league['admin']);

    DB::table('season_writer_entries')->where('id', $league['writers'][0]['entry']->id)->update(['stars_total' => 0, 'voters_count' => 0]);
    \App\Services\SeasonalRecognition\SeasonalRecognitionCache::bump($league['season']->id, \App\Services\SeasonalRecognition\SeasonalRecognitionCache::SCOPE_WRITERS);

    $rows = app(\App\Services\SeasonalRecognition\SeasonWriterLeaderboardService::class)->publicBoard($league['season'])['rows'];

    expect($rows[0]['stars'])->toBe(6)->and($rows[0]['outcome'])->toBe('first_place')->and($rows[0]['rank'])->toBe(1);

    $this->get(route('seasons.show', $league['season']))->assertOk()->assertSee('data-seasonal-recognition', false)->assertSee('الترتيب النهائي');
});

test('E44-FN-10: the preview is read-only and lists ranks, outcomes and the awards that finalization would create', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([6, 3, 1], ['eliminated_count' => 1, 'reward_plan' => [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id],
        ['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'مكافأة', 'amount_minor' => 2500, 'currency' => 'USD'],
    ]]);

    $before = [DB::table('season_writer_entries')->whereNotNull('final_rank')->count(), SeasonWriterAward::query()->count(), DB::table('operational_audit_logs')->count()];
    $preview = e44_finalizer()->preview($league['season']);

    expect($preview['can_finalize'])->toBeTrue()
        ->and($preview['blockers'])->toBe([])
        ->and(array_column($preview['rows'], 'outcome'))->toBe(['first_place', 'second_place', 'third_place'])
        ->and(array_column($preview['awards'], 'kind'))->toBe(['cosmetic', 'monetary'])
        ->and([DB::table('season_writer_entries')->whereNotNull('final_rank')->count(), SeasonWriterAward::query()->count(), DB::table('operational_audit_logs')->count()])->toBe($before);
});

test('E44-FN-11: a mismatch between stored aggregates and the allocation ledger blocks finalization until it is rebuilt', function () {
    $league = e44_closed_league([5, 2]);
    $writer = $league['writers'][0];
    DB::table('season_writer_entries')->where('id', $writer['entry']->id)->update(['stars_total' => 99]);

    $codes = array_column(app(SeasonWriterReconciler::class)->inspect($league['season']), 'code');
    $preview = e44_finalizer()->preview($league['season']);

    expect($codes)->toContain('entry_aggregate_mismatch')
        ->and($preview['can_finalize'])->toBeFalse()
        ->and(e44_reason(fn () => e44_finalizer()->finalize($league['season'], $league['admin'])))->toBe('ledger_mismatch')
        ->and(e44_settings()->settingFor($league['season'])->writers_finalized_at)->toBeNull();

    expect(e44_reason(fn () => app(SeasonWriterReconciler::class)->rebuildAggregates($league['season'], e44_staff(['seasonal-recognition.manage']))))->toBe('forbidden');

    $fixed = app(SeasonWriterReconciler::class)->rebuildAggregates($league['season'], $league['admin']);

    expect($fixed['entries'])->toBe(1)
        ->and(e44_stars($writer))->toBe(5)
        ->and(app(SeasonWriterReconciler::class)->inspect($league['season']))->toBe([])
        ->and(e44_finalizer()->finalize($league['season'], $league['admin'])['finalized'])->toBeTrue();
});

test('E44-FN-12: the reconciler detects budget drift, orphan allocations and self-vote rows, and rebuilds the budget from the ledger', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    [$voter] = e44_vote($a, 4);

    DB::table('season_writer_vote_budgets')->where('user_id', $voter->id)->update(['spent_stars' => 9]);
    DB::table('season_writer_star_allocations')->insert(['season_id' => $league['season']->id, 'writer_entry_id' => $b['entry']->id, 'voter_user_id' => $b['user']->id, 'stars' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $codes = array_column(app(SeasonWriterReconciler::class)->inspect($league['season']), 'code');

    expect($codes)->toContain('budget_mismatch')->toContain('self_vote_allocation')->toContain('allocation_without_budget');

    // حذف التوزيع المسموم ثم إعادة بناء الميزانية من الدفتر.
    DB::table('season_writer_star_allocations')->where('voter_user_id', $b['user']->id)->delete();
    $result = app(SeasonWriterReconciler::class)->rebuildAggregates($league['season'], $league['admin']);

    expect($result['budgets'])->toBe(1)
        ->and((int) DB::table('season_writer_vote_budgets')->where('user_id', $voter->id)->value('spent_stars'))->toBe(4)
        ->and(app(SeasonWriterReconciler::class)->inspect($league['season']))->toBe([]);
});

test('E44-FN-13: rebuilding aggregates is refused for a finalized league', function () {
    $league = e44_closed_league([3]);
    e44_finalizer()->finalize($league['season'], $league['admin']);

    expect(e44_reason(fn () => app(SeasonWriterReconciler::class)->rebuildAggregates($league['season'], $league['admin'])))->toBe('finalized');
});

test('E44-FN-14: every ranked writer gets exactly one final-standing notification (semantic key), frozen writers none, and redelivery never duplicates', function () {
    $league = e44_closed_league([5, 3, 1]);
    $league['writers'][2]['user']->forceFill(['is_frozen' => true])->save();

    e44_finalizer()->finalize($league['season'], $league['admin']);

    expect(e44_notes('writer_final_standing', $league['writers'][0]['user']))->toHaveCount(1)
        ->and(e44_notes('writer_final_standing', $league['writers'][1]['user']))->toHaveCount(1)
        ->and(e44_notes('writer_final_standing', $league['writers'][2]['user']))->toHaveCount(0);

    e44_finalizer()->redeliver($league['season'], $league['admin']);
    e44_finalizer()->redeliver($league['season'], $league['admin']);

    expect(e44_notes('writer_final_standing', $league['writers'][0]['user']))->toHaveCount(1)
        ->and(e44_notes('writer_final_standing', null))->toHaveCount(2);
});

test('E44-FN-15: redelivery needs the award permission and a finalized league', function () {
    $league = e44_closed_league([3]);

    expect(e44_reason(fn () => e44_finalizer()->redeliver($league['season'], $league['admin'])))->toBe('not_finalized');

    e44_finalizer()->finalize($league['season'], $league['admin']);

    expect(e44_reason(fn () => e44_finalizer()->redeliver($league['season'], e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_finalizer()->redeliver($league['season'], $league['admin']))->toBe(['granted' => 0, 'failed' => 0]);
});

// ───────────────────────────── الجوائز ─────────────────────────────

test('E44-AW-01: a cosmetic prize is granted once through the inventory service — not as gems, XP or currency', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([5, 2], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة المركز الأول', 'store_item_id' => $item->id]]]);
    $winner = $league['writers'][0]['user'];
    $before = e44_foreign_counts();

    expect(e44_finalizer()->finalize($league['season'], $league['admin'])['awards'])->toBe(1);

    $award = SeasonWriterAward::query()->firstOrFail();
    $inventory = UserInventoryItem::query()->where('user_id', $winner->id)->where('store_item_id', $item->id)->first();

    expect($award->status)->toBe('granted')
        ->and($award->granted_at)->not->toBeNull()
        ->and((int) $award->user_id)->toBe((int) $winner->id)
        ->and($award->placement)->toBe('first_place')
        ->and((int) $inventory?->quantity)->toBe(1)
        ->and(InventoryTransaction::query()->where('reason', 'like', 'seasonal_writer_award:%')->count())->toBe(1)
        ->and(UserInventoryItem::query()->where('user_id', $league['writers'][1]['user']->id)->count())->toBe(0)
        ->and(e44_foreign_counts())->toBe($before);

    // إعادة المحاولة لا تمنح مرة ثانية.
    expect(e44_awards()->grantPendingCosmetics($league['season'], $league['admin']))->toBe(['granted' => 0, 'failed' => 0])
        ->and(e44_awards()->grantCosmetic($award->id))->toBeTrue()
        ->and((int) UserInventoryItem::query()->where('user_id', $winner->id)->where('store_item_id', $item->id)->value('quantity'))->toBe(1);
});

test('E44-AW-02: a monetary prize is only an auditable obligation — nothing is paid, converted or credited to any balance', function () {
    $league = e44_closed_league([5, 2], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'monetary', 'label' => 'مكافأة نقدية', 'amount_minor' => 5000, 'currency' => 'USD']]]);
    $winner = $league['writers'][0]['user'];
    $before = e44_foreign_counts();
    $wallet = e44_money_state($winner);

    e44_finalizer()->finalize($league['season'], $league['admin']);

    $award = SeasonWriterAward::query()->firstOrFail();

    expect($award->status)->toBe('pending_finance')
        ->and((int) $award->amount_minor)->toBe(5000)
        ->and($award->currency)->toBe('USD')
        ->and($award->store_item_id)->toBeNull()
        ->and($award->settled_at)->toBeNull()
        ->and(e44_foreign_counts())->toBe($before)
        ->and(e44_money_state($winner))->toBe($wallet)
        ->and(UserInventoryItem::query()->count())->toBe(0);

    // الجائزة المالية لا تُمنح تلقائيًا حتى لو استُدعيت خدمة التجميلية.
    expect(e44_awards()->grantCosmetic($award->id))->toBeFalse()
        ->and($award->fresh()->status)->toBe('pending_finance');
});

test('E44-AW-03: settling an obligation is a manual, permissioned, idempotent record with a mandatory reference', function () {
    $league = e44_closed_league([5], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'monetary', 'label' => 'مكافأة', 'amount_minor' => 2500, 'currency' => 'USD']]]);
    e44_finalizer()->finalize($league['season'], $league['admin']);
    $award = SeasonWriterAward::query()->firstOrFail();

    expect(e44_reason(fn () => e44_awards()->settleMonetary($award, e44_staff(['seasonal-recognition.manage']), 'bank', 'REF-1')))->toBe('forbidden')
        ->and(e44_reason(fn () => e44_awards()->settleMonetary($award, $league['admin'], 'bank', '   ')))->toBe('settlement_invalid')
        ->and(e44_reason(fn () => e44_awards()->settleMonetary($award, $league['admin'], '', 'REF-1')))->toBe('settlement_invalid')
        ->and($award->fresh()->status)->toBe('pending_finance');

    $settled = e44_awards()->settleMonetary($award, $league['admin'], 'bank', 'REF-1', 'تم التحويل <b>يدويًا</b>');

    expect($settled->status)->toBe('settled')
        ->and($settled->settlement_ref)->toBe('REF-1')
        ->and($settled->note)->toBe('تم التحويل يدويًا')
        ->and((int) $settled->settled_by)->toBe((int) $league['admin']->id)
        ->and((int) $settled->amount_minor)->toBe(2500)
        ->and(e44_awards()->settleMonetary($award->fresh(), $league['admin'], 'bank', 'REF-OTHER')->settlement_ref)->toBe('REF-1')
        ->and(e44_audit('seasonal_writer_award_settled'))->toBe(1);
});

test('E44-AW-04: cancelling needs a reason; settled or granted decisions cannot be cancelled, and a cancelled one can never be settled', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([6, 4], ['reward_plan' => [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id],
        ['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'مكافأة', 'amount_minor' => 1000, 'currency' => 'USD'],
    ]]);
    e44_finalizer()->finalize($league['season'], $league['admin']);

    $granted = SeasonWriterAward::query()->where('kind', 'cosmetic')->firstOrFail();
    $money = SeasonWriterAward::query()->where('kind', 'monetary')->firstOrFail();

    expect(e44_reason(fn () => e44_awards()->cancel($money, $league['admin'], '   ')))->toBe('note_required')
        ->and(e44_reason(fn () => e44_awards()->cancel($granted, $league['admin'], 'سبب')))->toBe('not_cancellable');

    $cancelled = e44_awards()->cancel($money, $league['admin'], 'اتفاق على بديل');

    expect($cancelled->status)->toBe('cancelled')
        ->and(e44_reason(fn () => e44_awards()->settleMonetary($money->fresh(), $league['admin'], 'bank', 'REF-9')))->toBe('not_pending')
        ->and(e44_awards()->cancel($money->fresh(), $league['admin'], 'مرة ثانية')->status)->toBe('cancelled');

    // التسوية لا تنطبق على التجميلية.
    expect(e44_reason(fn () => e44_awards()->settleMonetary($granted, $league['admin'], 'bank', 'REF')))->toBe('not_monetary');
});

test('E44-AW-05: several plan items for one placement create several decisions; unplanned placements get none; plan_index is unique per writer', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([6, 4, 2], ['reward_plan' => [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id],
        ['placement' => 'first_place', 'kind' => 'monetary', 'label' => 'مكافأة', 'amount_minor' => 700, 'currency' => 'USD'],
    ]]);

    expect(e44_finalizer()->finalize($league['season'], $league['admin'])['awards'])->toBe(2);

    $awards = SeasonWriterAward::query()->orderBy('plan_index')->get();

    expect($awards->pluck('plan_index')->map(fn ($v) => (int) $v)->all())->toBe([0, 1])
        ->and($awards->pluck('placement')->unique()->all())->toBe(['first_place'])
        ->and($awards->pluck('user_id')->unique()->count())->toBe(1);

    expect(fn () => DB::table('season_writer_awards')->insert([
        'public_id' => (string) \Illuminate\Support\Str::ulid(), 'season_id' => $league['season']->id, 'writer_entry_id' => $awards[0]->writer_entry_id, 'user_id' => $awards[0]->user_id,
        'plan_index' => 0, 'placement' => 'first_place', 'kind' => 'monetary', 'label' => 'x', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('E44-AW-06: a failing cosmetic grant is isolated — the league still finalizes, the award is marked failed, and a retry grants it', function () {
    $item = e44_cosmetic();
    $league = e44_closed_league([5, 2], ['reward_plan' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة', 'store_item_id' => $item->id]]]);
    DB::table('store_items')->where('id', $item->id)->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]); // لم يعد قابلًا للتسليم كمخزون

    $result = e44_finalizer()->finalize($league['season'], $league['admin']);
    $award = SeasonWriterAward::query()->firstOrFail();

    expect($result['finalized'])->toBeTrue()
        ->and($award->status)->toBe('failed')
        ->and(UserInventoryItem::query()->count())->toBe(0)
        ->and(e44_settings()->settingFor($league['season'])->writers_finalized_at)->not->toBeNull();

    DB::table('store_items')->where('id', $item->id)->update(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);

    expect(e44_reason(fn () => e44_awards()->grantPendingCosmetics($league['season'], e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_awards()->grantPendingCosmetics($league['season'], $league['admin']))->toBe(['granted' => 1, 'failed' => 0])
        ->and($award->fresh()->status)->toBe('granted')
        ->and((int) UserInventoryItem::query()->where('store_item_id', $item->id)->value('quantity'))->toBe(1);
});

// ───────────────────────────── الدوران بين المواسم ─────────────────────────────

/** يُنهي دوريًا سابقًا في الماضي (سبتمبر) بأربعة كتّاب: الثلاثة الأوائل أصحاب مراكز (لا يُوسَم صاحب مركز) والرابع الأخير «خارج الموسم التالي». @return array{season: Season, writers: list<array<string, mixed>>} */
function e44_finalized_past_league(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00'));
    $admin = e44_admin();
    $past = e42_season(['window' => ['2026-09-01 00:00:00', '2026-10-01 00:00:00']]);
    e44_settings()->ensure($past);
    e44_enable($past, ['eliminated_count' => 1], $admin);

    $writers = [e44_writer($past, $admin), e44_writer($past, $admin), e44_writer($past, $admin), e44_writer($past, $admin)];

    e44_open($past, ['closes_at' => '2026-09-20 12:00:00'], $admin);
    e44_tick(1);
    e44_cast($writers, [5, 3, 2, 1]);

    Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00'));
    e44_finalizer()->finalize($past, $admin);

    Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00'));

    return ['season' => $past->fresh(), 'writers' => $writers];
}

test('E44-RO-01: the lowest-ranked writer is excluded from the IMMEDIATELY following season only — not banned, suspended or hidden', function () {
    $past = e44_finalized_past_league();
    [$first, $second, , $excluded] = $past['writers'];
    $admin = e44_admin();

    $next = e44_season();
    e44_enable($next, [], $admin);
    $rotation = app(\App\Services\SeasonalRecognition\SeasonWriterRotationService::class);

    expect($rotation->previousSeasonFor($next)->id)->toBe($past['season']->id)
        ->and($rotation->excludedCreatorProfileIds($next))->toBe([(int) $excluded['profile']->id])
        ->and(e44_reason(fn () => e44_roster()->addWriter($next, $excluded['profile'], $admin)))->toBe('rotation_excluded')
        ->and(e44_roster()->addWriter($next, $first['profile'], $admin)->exists)->toBeTrue()
        ->and(e44_roster()->addWriter($next, $second['profile'], $admin)->exists)->toBeTrue();

    // لا حظر منصّة ولا تعليق ولا إخفاء: الملف معتمد والاستوديو متاح والصفحة العامة تعمل.
    $profile = $excluded['profile']->fresh();
    expect($profile->status)->toBe(\App\Models\CreatorProfile::STATUS_APPROVED)
        ->and(app(\App\Services\Creators\CreatorEligibility::class)->canUseStudio($excluded['user']->fresh()))->toBeTrue()
        ->and((bool) \App\Models\User::query()->find($excluded['user']->id)->is_frozen)->toBeFalse();
    $this->get(route('creators.show', $profile->slug))->assertOk();
});

test('E44-RO-02: the exclusion does not carry to the season after next, and an unfinalized or explicit-other previous season excludes nobody', function () {
    $past = e44_finalized_past_league();
    $excluded = $past['writers'][3];
    $admin = e44_admin();

    $next = e44_season();
    e44_enable($next, [], $admin);

    // الموسم بعد التالي: سابقه المباشر هو «التالي» (غير مُنهى)، فلا استبعاد.
    $after = e42_season(['window' => ['2027-02-01 00:00:00', '2027-03-01 00:00:00']]);
    e44_settings()->ensure($after);
    e44_enable($after, [], $admin);

    expect(app(\App\Services\SeasonalRecognition\SeasonWriterRotationService::class)->excludedCreatorProfileIds($after))->toBe([])
        ->and(e44_roster()->addWriter($after, $excluded['profile'], $admin)->exists)->toBeTrue();

    // مؤشر صريح لموسم سابق آخر يغيّر المرجع؛ ومؤشر لنفس الموسم مرفوض.
    $other = e44_season();
    e44_settings()->saveWriterSettings($next, ['previous_season_id' => $other->id], $admin);
    expect(app(\App\Services\SeasonalRecognition\SeasonWriterRotationService::class)->excludedCreatorProfileIds($next->fresh()))->toBe([])
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($next, ['previous_season_id' => $next->id], $admin)))->toBe('invalid_previous');
});

test('E44-RO-03: the profile page shows podium places only and never the ineligible-next-season label', function () {
    $past = e44_finalized_past_league();
    [$first, , , $excluded] = $past['writers'];

    $this->get(route('creators.show', $first['profile']->slug))->assertOk()->assertSee('data-creator-seasonal-record', false)->assertSee('المركز الأول');

    $html = $this->get(route('creators.show', $excluded['profile']->slug))->assertOk()->getContent();

    expect(str_contains($html, 'خارج قائمة الموسم التالي'))->toBeFalse()
        ->and(str_contains($html, 'ineligible'))->toBeFalse()
        ->and(str_contains($html, 'شارك في دوري الموسم'))->toBeTrue();

    // الصفحة العامة للموسم نفسه تُظهر الوسم (ترتيب الموسم لا الملف).
    $this->get(route('seasonal.writers.show', $past['season']))->assertOk()->assertSee('خارج قائمة الموسم التالي');
});
