<?php

use App\Models\OperationalAuditLog;
use App\Models\Season;
use App\Models\SeasonPhase;
use App\Models\User;
use App\Services\Seasons\SeasonCache;
use App\Services\Seasons\SeasonPhaseResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

/*
| E42 / منشئ الموسم: المراحل (نصف مفتوحة، بلا تداخل، ضمن النافذة، حدّ أقصى، أقفال ما بدأ) + الصلاحيات داخل الخدمة + التدقيق + إبطال الكاش.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── الصلاحيات داخل الخدمة ─────────────────────────────

test('E42-BP-01: every write checks the permission inside the service (UI hiding is not security)', function () {
    $season = e42_live();
    $phase = e42_phase($season, 'الأولى', '2026-12-01 00:00:00', '2026-12-05 00:00:00');
    $nobody = e42_plain_staff();
    $viewer = e42_staff(['seasons.view']);
    $b = e42_builder();

    foreach ([$nobody, $viewer] as $actor) {
        expect(e42_reason(fn () => $b->addPhase($actor, $season, ['name' => 'x', 'starts_at' => '2026-12-06', 'ends_at' => '2026-12-07'])))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->updatePhase($actor, $phase, ['name' => 'y'])))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->deletePhase($actor, $phase)))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->updateSchedule($actor, $season, ['ends_at' => '2026-12-31'])))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->publish($actor, $season)))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->unpublish($actor, $season)))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->archive($actor, $season)))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->createAnnouncement($actor, $season, ['title' => 'a', 'body' => 'b'])))->toBe('forbidden')
            ->and(e42_reason(fn () => $b->linkPass($actor, $season, null)))->toBe('forbidden');
    }

    expect(SeasonPhase::query()->count())->toBe(1);
});

test('E42-BP-02: an editor with seasons.update but without seasons.publish cannot publish, unpublish or archive', function () {
    $editor = e42_staff(['seasons.view', 'seasons.update']);
    $season = e42_live(['published' => false]);

    expect(e42_reason(fn () => e42_builder()->publish($editor, $season)))->toBe('forbidden')
        ->and(e42_reason(fn () => e42_builder()->archive($editor, $season)))->toBe('forbidden')
        ->and($season->fresh()->is_published)->toBeFalse();

    expect(e42_builder()->addPhase($editor, $season, ['name' => 'مرحلة', 'starts_at' => '2026-12-02', 'ends_at' => '2026-12-04']))->toBeInstanceOf(SeasonPhase::class);
});

// ───────────────────────────── المراحل ─────────────────────────────

test('E42-BP-03: a phase is created with a public ULID, a generated internal key, audited, and ordered deterministically', function () {
    $season = e42_live();
    $admin = e42_admin();

    $b = e42_phase($season, 'الفصل الثاني', '2026-12-10 00:00:00', '2026-12-20 00:00:00', [], $admin);
    $a = e42_phase($season, 'الفصل الأول', '2026-12-01 00:00:00', '2026-12-10 00:00:00', ['internal_key' => 'opening'], $admin);

    expect($a->public_id)->toHaveLength(26)
        ->and($a->internal_key)->toBe('opening')
        ->and($b->internal_key)->toStartWith('phase-')
        ->and($season->fresh()->phases->pluck('name')->all())->toBe(['الفصل الأول', 'الفصل الثاني'])
        ->and(OperationalAuditLog::query()->where('action', 'season_phase_added')->count())->toBe(2);
});

test('E42-BP-04: phases are half-open - back to back is allowed, any overlap is refused', function () {
    $season = e42_live();
    e42_phase($season, 'أ', '2026-12-01 00:00:00', '2026-12-10 00:00:00');

    expect(e42_phase($season, 'ب', '2026-12-10 00:00:00', '2026-12-15 00:00:00'))->toBeInstanceOf(SeasonPhase::class); // يبدأ لحظة انتهاء سابقتها

    foreach ([['2026-12-09 23:59:59', '2026-12-11 00:00:00'], ['2026-12-02 00:00:00', '2026-12-03 00:00:00'], ['2026-11-30 12:00:00', '2026-12-20 00:00:00']] as [$s, $e]) {
        expect(e42_reason(fn () => e42_phase($season, 'متداخلة', $s, $e)))->toBe('phase_overlap');
    }

    expect($season->phases()->count())->toBe(2);
});

test('E42-BP-05: a phase must have a valid range and stay inside the effective season window', function () {
    $season = e42_live(); // 30/11 → 30/12

    expect(e42_reason(fn () => e42_phase($season, 'معكوسة', '2026-12-05 00:00:00', '2026-12-05 00:00:00')))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, 'معكوسة', '2026-12-06 00:00:00', '2026-12-05 00:00:00')))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, 'قبل الموسم', '2026-11-29 00:00:00', '2026-12-02 00:00:00')))->toBe('phase_outside_window')
        ->and(e42_reason(fn () => e42_phase($season, 'بعد الموسم', '2026-12-29 00:00:00', '2026-12-31 00:00:00')))->toBe('phase_outside_window')
        ->and(e42_reason(fn () => e42_builder()->addPhase(e42_admin(), $season, ['name' => 'بلا تواريخ'])))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, 'تاريخ سيئ', 'ليس تاريخًا', '2026-12-05 00:00:00')))->toBe('schedule_invalid');

    expect(e42_phase($season, 'الحدّان بالضبط', '2026-11-30 00:00:00', '2026-12-30 00:00:00'))->toBeInstanceOf(SeasonPhase::class);
});

test('E42-BP-06: a windowless season follows the primary campaign window for phase bounds', function () {
    $season = e42_season(['campaign' => ['starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-20 00:00:00']]);

    expect(e42_reason(fn () => e42_phase($season, 'خارج', '2026-12-18 00:00:00', '2026-12-22 00:00:00')))->toBe('phase_outside_window')
        ->and(e42_phase($season, 'داخل', '2026-12-02 00:00:00', '2026-12-18 00:00:00'))->toBeInstanceOf(SeasonPhase::class);
});

test('E42-BP-07: phase name and description are plain text only and length-bounded', function () {
    $season = e42_live();

    expect(e42_reason(fn () => e42_phase($season, '<b>x</b>', '2026-12-01 00:00:00', '2026-12-02 00:00:00')))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, '   ', '2026-12-01 00:00:00', '2026-12-02 00:00:00')))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, str_repeat('ا', 121), '2026-12-01 00:00:00', '2026-12-02 00:00:00')))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, 'سليم', '2026-12-01 00:00:00', '2026-12-02 00:00:00', ['description' => '<script>alert(1)</script>'])))->toBe('phase_invalid')
        ->and(e42_reason(fn () => e42_phase($season, 'سليم', '2026-12-01 00:00:00', '2026-12-02 00:00:00', ['internal_key' => 'Bad Key!'])))->toBe('phase_invalid');

    $ok = e42_phase($season, 'سليم', '2026-12-01 00:00:00', '2026-12-02 00:00:00', ['description' => "سطر\n\n\n\nسطر"]);
    expect($ok->description)->toBe("سطر\n\nسطر");
});

test('E42-BP-08: the per-season phase limit is enforced', function () {
    config(['seasons.limits.phases_per_season' => 3]);
    $season = e42_live();

    foreach ([1, 2, 3] as $i) {
        e42_phase($season, 'م'.$i, '2026-12-0'.$i.' 00:00:00', '2026-12-0'.$i.' 12:00:00');
    }

    expect(e42_reason(fn () => e42_phase($season, 'رابعة', '2026-12-10 00:00:00', '2026-12-11 00:00:00')))->toBe('phase_limit');
});

test('E42-BP-09: updating a phase keeps overlap rules, freezes id and internal key, and is a no-op when nothing changed', function () {
    $season = e42_live();
    $a = e42_phase($season, 'أ', '2026-12-01 00:00:00', '2026-12-10 00:00:00', ['internal_key' => 'first']);
    $b = e42_phase($season, 'ب', '2026-12-10 00:00:00', '2026-12-20 00:00:00');
    $admin = e42_admin();

    expect(e42_reason(fn () => e42_builder()->updatePhase($admin, $b, ['starts_at' => '2026-12-09 00:00:00'])))->toBe('phase_overlap')
        ->and(e42_reason(fn () => e42_builder()->updatePhase($admin, $a, ['internal_key' => 'other'])))->toBe('phase_invalid');

    $updated = e42_builder()->updatePhase($admin, $a, ['name' => 'أ معدّلة', 'ends_at' => '2026-12-09 00:00:00']);
    expect($updated->name)->toBe('أ معدّلة')->and($updated->internal_key)->toBe('first');

    $before = OperationalAuditLog::query()->count();
    e42_builder()->updatePhase($admin, $a->fresh(), ['name' => 'أ معدّلة']);
    expect(OperationalAuditLog::query()->count())->toBe($before);
});

test('E42-BP-10: a phase that already started is locked - its start cannot move and it cannot be deleted; an upcoming one can be deleted', function () {
    $season = e42_live();
    $started = e42_phase($season, 'بدأت', '2026-12-01 00:00:00', '2026-12-20 00:00:00');
    $upcoming = e42_phase($season, 'قادمة', '2026-12-20 00:00:00', '2026-12-25 00:00:00');
    $admin = e42_admin();

    SeasonPhase::query()->whereKey($started->id)->update(['went_live_at' => now()]);
    $started = $started->fresh();

    expect(e42_reason(fn () => e42_builder()->updatePhase($admin, $started, ['starts_at' => '2026-12-02 00:00:00'])))->toBe('phase_started')
        ->and(e42_reason(fn () => e42_builder()->deletePhase($admin, $started)))->toBe('phase_started')
        ->and(e42_builder()->updatePhase($admin, $started, ['ends_at' => '2026-12-19 00:00:00'])->ends_at->toDateString())->toBe('2026-12-19');

    e42_builder()->deletePhase($admin, $upcoming);
    expect(SeasonPhase::query()->whereKey($upcoming->id)->exists())->toBeFalse()
        ->and(OperationalAuditLog::query()->where('action', 'season_phase_deleted')->count())->toBe(1);

    e42_builder()->deletePhase($admin, $upcoming); // مكرَّر = لا شيء ولا خطأ
});

test('E42-BP-11: phase model is mass-assignment closed and immutable on identity columns', function () {
    $season = e42_live();
    $phase = e42_phase($season, 'م', '2026-12-01 00:00:00', '2026-12-02 00:00:00');

    expect(fn () => SeasonPhase::query()->create(['season_id' => $season->id, 'name' => 'x']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);

    $other = e42_live();
    expect(fn () => $phase->forceFill(['season_id' => $other->id])->save())->toThrow(\LogicException::class);
});

// ───────────────────────────── ترتيب المراحل والحالة ─────────────────────────────

test('E42-BP-12: the resolver orders by (starts_at, sort_order, id) and finds current/next at the half-open edges', function () {
    $season = e42_live();
    $p2 = e42_phase($season, 'ثانية', '2026-12-10 12:00:00', '2026-12-15 00:00:00');
    $p1 = e42_phase($season, 'أولى', '2026-12-01 00:00:00', '2026-12-10 12:00:00');
    $phases = $season->fresh()->phases;

    expect(SeasonPhaseResolver::ordered($phases)->pluck('name')->all())->toBe(['أولى', 'ثانية'])
        ->and(SeasonPhaseResolver::current($phases, Carbon::parse('2026-12-10 11:59:59'))?->name)->toBe('أولى')
        ->and(SeasonPhaseResolver::current($phases, Carbon::parse('2026-12-10 12:00:00'))?->name)->toBe('ثانية') // البداية شاملة والنهاية حصرية
        ->and(SeasonPhaseResolver::current($phases, Carbon::parse('2026-12-15 00:00:00')))->toBeNull()
        ->and(SeasonPhaseResolver::next($phases, Carbon::parse('2026-12-05 00:00:00'))?->name)->toBe('ثانية')
        ->and(SeasonPhaseResolver::next($phases, Carbon::parse('2026-12-12 00:00:00')))->toBeNull()
        ->and($p1->stateAt(Carbon::parse('2026-12-10 12:00:00')))->toBe(SeasonPhase::STATE_ENDED)
        ->and($p2->stateAt(Carbon::parse('2026-12-10 12:00:00')))->toBe(SeasonPhase::STATE_CURRENT);
});

// ───────────────────────────── التدقيق والكاش ─────────────────────────────

test('E42-BP-13: any write flushes the definition cache version (and the audit trail records the actor)', function () {
    $season = e42_live();
    $before = SeasonCache::version();
    $admin = e42_admin();

    e42_phase($season, 'م', '2026-12-01 00:00:00', '2026-12-02 00:00:00', [], $admin);

    expect(SeasonCache::version())->toBeGreaterThan($before)
        ->and(OperationalAuditLog::query()->where('action', 'season_phase_added')->first()->actor_user_id)->toBe($admin->id);
});

test('E42-BP-14: an archived season is frozen for every kind of edit', function () {
    $season = e42_live();
    $phase = e42_phase($season, 'م', '2026-12-01 00:00:00', '2026-12-02 00:00:00');
    $admin = e42_admin();

    e42_builder()->archive($admin, $season);

    $b = e42_builder();
    expect(e42_reason(fn () => $b->addPhase($admin, $season, ['name' => 'جديدة', 'starts_at' => '2026-12-03 00:00:00', 'ends_at' => '2026-12-04 00:00:00'])))->toBe('season_archived')
        ->and(e42_reason(fn () => $b->updatePhase($admin, $phase, ['name' => 'تغيير'])))->toBe('season_archived')
        ->and(e42_reason(fn () => $b->deletePhase($admin, $phase)))->toBe('season_archived')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 't', 'body' => 'b'])))->toBe('season_archived')
        ->and(e42_reason(fn () => $b->updateSchedule($admin, $season, ['ends_at' => '2027-01-30'])))->toBe('season_archived')
        ->and(e42_reason(fn () => $b->unpublish($admin, $season)))->toBe('season_archived');
});
