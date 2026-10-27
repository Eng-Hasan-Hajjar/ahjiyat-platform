<?php

use App\Events\SeasonStarted;
use App\Models\Campaign;
use App\Models\OperationalAuditLog;
use App\Models\Season;
use App\Services\Seasons\SeasonTheme;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/_support.php';

/*
| E42 / نافذة الموسم والنشر والأرشفة والجاهزية والثيم عبر المنشئ.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── الجدولة ─────────────────────────────

test('E42-BW-01: schedule validation - order, announcement before start, archive after end', function () {
    $season = e42_season(['published' => false]);
    $admin = e42_admin();
    $b = e42_builder();

    expect(e42_reason(fn () => $b->updateSchedule($admin, $season, ['starts_at' => '2026-12-20', 'ends_at' => '2026-12-19'])))->toBe('schedule_invalid')
        ->and(e42_reason(fn () => $b->updateSchedule($admin, $season, ['starts_at' => '2026-12-20', 'ends_at' => '2026-12-30', 'announcement_at' => '2026-12-21'])))->toBe('schedule_invalid')
        ->and(e42_reason(fn () => $b->updateSchedule($admin, $season, ['starts_at' => '2026-12-20', 'ends_at' => '2026-12-30', 'archive_at' => '2026-12-25'])))->toBe('schedule_invalid')
        ->and(e42_reason(fn () => $b->updateSchedule($admin, $season, ['starts_at' => 'غير صالح'])))->toBe('schedule_invalid');

    $ok = $b->updateSchedule($admin, $season, ['starts_at' => '2026-12-20 10:00:00', 'ends_at' => '2026-12-30 10:00:00', 'announcement_at' => '2026-12-15 10:00:00', 'archive_at' => '2027-01-05 10:00:00']);

    expect($ok->starts_at->toDateTimeString())->toBe('2026-12-20 10:00:00')
        ->and(OperationalAuditLog::query()->where('action', 'season_schedule_updated')->count())->toBe(1);
});

test('E42-BW-02: clearing the season dates falls back to the campaign (NULL = follow), and an unchanged save is a no-op', function () {
    $season = e42_live(['campaign' => ['starts_at' => '2026-12-05 00:00:00', 'ends_at' => '2026-12-25 00:00:00']]);
    $admin = e42_admin();

    $before = OperationalAuditLog::query()->count();
    e42_builder()->updateSchedule($admin, $season, ['starts_at' => '2026-11-30 00:00:00', 'ends_at' => '2026-12-30 00:00:00']);
    expect(OperationalAuditLog::query()->count())->toBe($before);

    // مرحلة الموسم الصريح بدأت فعلًا: بدايته مقفلة، فنمسح النهاية فقط ثم نتحقق من تتبّع الحملة.
    $fresh = e42_builder()->updateSchedule($admin, $season, ['ends_at' => null]);
    expect($fresh->ends_at)->toBeNull()
        ->and(e42_life()->windowEnd($fresh->load('campaign'))->toDateString())->toBe('2026-12-25');
});

test('E42-BW-03: a form save with minute precision does not count as a change (no start lock collision)', function () {
    $season = e42_live();
    Season::query()->whereKey($season->id)->update(['starts_at' => '2026-11-30 00:00:37']); // ثوانٍ غير صفرية
    $season = $season->fresh();
    $season->forceFill(['went_live_at' => now()])->saveQuietly();

    $same = e42_builder()->updateSchedule(e42_admin(), $season, ['starts_at' => '2026-11-30 00:00', 'ends_at' => '2026-12-30 00:00']);

    expect($same->starts_at->toDateTimeString())->toBe('2026-11-30 00:00:37');
});

test('E42-BW-04: a started season cannot move its start, nor postpone its announcement into the future', function () {
    $season = e42_live();
    $admin = e42_admin();

    expect($season->fresh()->went_live_at)->not->toBeNull()
        ->and(e42_reason(fn () => e42_builder()->updateSchedule($admin, $season, ['starts_at' => '2026-12-01 00:00:00'])))->toBe('start_locked')
        ->and(e42_reason(fn () => e42_builder()->updateSchedule($admin, $season, ['announcement_at' => '2026-12-10 13:00:00'])))->toBe('schedule_invalid');

    // إطالة النهاية مسموحة.
    expect(e42_builder()->updateSchedule($admin, $season, ['ends_at' => '2027-01-15 00:00:00'])->ends_at->toDateString())->toBe('2027-01-15');
});

test('E42-BW-05: a window that would push existing phases outside is refused with the offending names', function () {
    $season = e42_live();
    e42_phase($season, 'الختام', '2026-12-25 00:00:00', '2026-12-30 00:00:00');

    try {
        e42_builder()->updateSchedule(e42_admin(), $season, ['ends_at' => '2026-12-27 00:00:00']);
        $this->fail('expected SeasonException');
    } catch (\App\Services\Seasons\SeasonException $e) {
        expect($e->reason)->toBe('window_excludes_phases')->and($e->details)->toBe(['الختام']);
    }
});

// ───────────────────────────── النشر ─────────────────────────────

test('E42-BW-06: publishing is gated by readiness blockers (campaign structure), idempotent, and unpublish reverses it', function () {
    $bare = e42_season(['published' => false, 'structure' => false]);
    $ready = e42_season(['published' => false]);
    $admin = e42_admin();

    try {
        e42_builder()->publish($admin, $bare);
        $this->fail('expected SeasonException');
    } catch (\App\Services\Seasons\SeasonException $e) {
        expect($e->reason)->toBe('not_ready')->and($e->details)->not->toBeEmpty();
    }

    expect($bare->fresh()->is_published)->toBeFalse();

    e42_builder()->publish($admin, $ready);
    e42_builder()->publish($admin, $ready); // idempotent
    expect($ready->fresh()->is_published)->toBeTrue()
        ->and(OperationalAuditLog::query()->where('action', 'season_published')->count())->toBe(1);

    e42_builder()->unpublish($admin, $ready);
    expect($ready->fresh()->is_published)->toBeFalse()
        ->and(OperationalAuditLog::query()->where('action', 'season_unpublished')->count())->toBe(1);
});

test('E42-BW-07: publishing a season whose window is live fires SeasonStarted exactly once through the model hook', function () {
    Event::fake([SeasonStarted::class]);
    $season = e42_live(['published' => false]);

    Event::assertNotDispatched(SeasonStarted::class);
    e42_builder()->publish(e42_admin(), $season);
    e42_builder()->unpublish(e42_admin(), $season);
    e42_builder()->publish(e42_admin(), $season);

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('E42-BW-08: readiness separates blockers from warnings and keeps the legacy check() shape', function () {
    $season = e42_live(['published' => false]);
    $report = e42_ready()->report($season->fresh());

    expect($report['ready'])->toBeTrue()
        ->and($report['blockers'])->toBe([])
        ->and($report['warnings'])->not->toBeEmpty() // لا مراحل / لا غلاف / لا مسار
        ->and(e42_ready()->check($season->fresh()))->toBe(['ready' => true, 'issues' => []]);

    $bare = e42_season(['published' => false, 'structure' => false]);
    $legacy = e42_ready()->check($bare->fresh());

    expect($legacy['ready'])->toBeFalse()->and($legacy['issues'])->not->toBeEmpty()
        ->and(e42_ready()->isReadyToPublish($bare->fresh()))->toBeFalse();
});

test('E42-BW-09: a phase outside the effective window is a blocker (window shrunk behind the builder\'s back)', function () {
    $season = e42_live(['published' => false]);
    e42_phase($season, 'الختام', '2026-12-25 00:00:00', '2026-12-30 00:00:00');
    Season::query()->whereKey($season->id)->update(['ends_at' => '2026-12-27 00:00:00']);

    $report = e42_ready()->report($season->fresh());

    expect($report['ready'])->toBeFalse()
        ->and(implode(' ', $report['blockers']))->toContain('الختام');
});

// ───────────────────────────── الأرشفة ─────────────────────────────

test('E42-BW-10: manual archive ends a live season now, freezes the effective window and keeps it visible in the archive', function () {
    $season = e42_season(['campaign' => ['starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2027-02-01 00:00:00']]);
    $admin = e42_admin();

    e42_builder()->archive($admin, $season);
    $fresh = $season->fresh();

    expect(e42_life()->state($fresh->load('campaign')))->toBe('archived')
        ->and($fresh->starts_at->toDateTimeString())->toBe('2026-12-01 00:00:00') // نُسخت من الحملة
        ->and($fresh->ends_at->toDateTimeString())->toBe('2026-12-10 12:00:00')
        ->and($fresh->is_published)->toBeTrue();

    // تعديل الحملة لاحقًا لا يغيّر تاريخ الموسم المؤرشف.
    $fresh->campaign->update(['ends_at' => '2027-12-01 00:00:00']);
    expect(e42_life()->windowEnd($fresh->fresh('campaign'))->toDateTimeString())->toBe('2026-12-10 12:00:00');

    e42_builder()->archive($admin, $season); // idempotent
    expect(OperationalAuditLog::query()->where('action', 'season_archived')->count())->toBe(1);
});

test('E42-BW-11: only a started season can be archived (draft / upcoming refuse)', function () {
    $draft = e42_live(['published' => false]);
    $upcoming = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);

    expect(e42_reason(fn () => e42_builder()->archive(e42_admin(), $draft)))->toBe('not_started')
        ->and(e42_reason(fn () => e42_builder()->archive(e42_admin(), $upcoming)))->toBe('not_started');
});

test('E42-BW-12: freezeWindow (scheduled archive) copies campaign dates once and is idempotent', function () {
    $season = e42_season(['campaign' => ['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-20 00:00:00']]);
    Season::withoutEvents(fn () => $season->forceFill(['archive_at' => '2026-12-01 00:00:00'])->saveQuietly());

    expect(e42_builder()->freezeWindow($season->fresh()))->toBeTrue()
        ->and(e42_builder()->freezeWindow($season->fresh()))->toBeFalse();

    $fresh = $season->fresh();
    expect($fresh->starts_at->toDateString())->toBe('2026-11-01')->and($fresh->ends_at->toDateString())->toBe('2026-11-20');
});

// ───────────────────────────── الثيم ─────────────────────────────

test('E42-BW-13: theme is a closed whitelist - preset, accent and tagline only; no CSS, HTML or unknown keys', function () {
    $b = e42_builder();

    expect($b->normalizeTheme(['preset' => 'aseel', 'hero_tagline' => 'رحلة الشتاء'])['preset'])->toBe('aseel');

    foreach ([['preset' => 'nope'], ['preset' => 'generic', 'css' => 'body{display:none}'], ['hero_tagline' => '<b>x</b>'], ['hero_tagline' => str_repeat('ا', 121)], ['preset' => ['x']]] as $bad) {
        expect(e42_reason(fn () => $b->normalizeTheme($bad)))->not->toBeNull();
    }

    expect(SeasonTheme::class)->toBeString();
});
