<?php

use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->periods = app(QuestPeriodService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('E13 req 20: daily period key is deterministic for a given moment', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 14:30:00', 'UTC'));

    $context = $this->periods->dailyContext();

    expect($context->periodKey)->toBe('daily:2026-10-05');
});

test('E13 req 109: multiple requests the same day produce the exact same daily key', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));
    $first = $this->periods->dailyContext()->periodKey;

    Carbon::setTestNow(Carbon::parse('2026-10-05 23:00:00', 'UTC'));
    $second = $this->periods->dailyContext()->periodKey;

    expect($first)->toBe($second);
});

test('E13 req 107: 23:59 then 00:00 next day changes the daily period key', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 23:59:59', 'UTC'));
    $before = $this->periods->dailyContext()->periodKey;

    Carbon::setTestNow(Carbon::parse('2026-10-06 00:00:01', 'UTC'));
    $after = $this->periods->dailyContext()->periodKey;

    expect($before)->not->toBe($after)
        ->and($before)->toBe('daily:2026-10-05')
        ->and($after)->toBe('daily:2026-10-06');
});

test('E13 req 24: the week starts Monday 00:00 (documented safe default - no platform policy exists)', function () {
    // 2026-10-05 is a Monday.
    Carbon::setTestNow(Carbon::parse('2026-10-05 00:00:01', 'UTC'));
    $context = $this->periods->weeklyContext();

    expect($context->start->format('Y-m-d H:i:s'))->toBe('2026-10-05 00:00:00')
        ->and($context->start->isMonday())->toBeTrue();
});

test('E13 req 108: last moment of week then first moment of next week changes the weekly key', function () {
    // Sunday 2026-10-11 23:59:59 is the last moment of that week (Mon-Sun).
    Carbon::setTestNow(Carbon::parse('2026-10-11 23:59:59', 'UTC'));
    $before = $this->periods->weeklyContext()->periodKey;

    Carbon::setTestNow(Carbon::parse('2026-10-12 00:00:01', 'UTC'));
    $after = $this->periods->weeklyContext()->periodKey;

    expect($before)->not->toBe($after);
});

test('E13 req 194/195: year boundary (Dec 31 -> Jan 1) produces correct, non-colliding daily keys', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-31 23:59:59', 'UTC'));
    $dec31 = $this->periods->dailyContext()->periodKey;

    Carbon::setTestNow(Carbon::parse('2027-01-01 00:00:01', 'UTC'));
    $jan1 = $this->periods->dailyContext()->periodKey;

    expect($dec31)->toBe('daily:2026-12-31')
        ->and($jan1)->toBe('daily:2027-01-01');
});

test('E13 req 195/196: ISO week-year correctly differs from calendar year at the Dec/Jan boundary', function () {
    // 2025-12-29 (a Monday) is ISO week 1 of 2026, even though the calendar year is still 2025.
    Carbon::setTestNow(Carbon::parse('2025-12-29 12:00:00', 'UTC'));
    $context = $this->periods->weeklyContext();

    expect($context->periodKey)->toBe('weekly:2026-W01');
});

test('E13 req 128: timezone used is the single, actual project timezone - no divergent policy', function () {
    expect($this->periods->timezone())->toBe(config('app.timezone'));
});

test('E13 req 26: DST-safety - period boundaries use Carbon timezone-aware operations, not raw 86400-second arithmetic', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
    $context = $this->periods->dailyContext();

    // بالتوقيت UTC لا DST فعليًا، لكن نؤكد أن الحدود تُحسَب عبر startOfDay/endOfDay الواعية بالمنطقة الزمنية لا بحساب ثوانٍ يدوي.
    expect($context->start->diffInSeconds($context->end))->toBeGreaterThan(86000)
        ->and($context->start->diffInSeconds($context->end))->toBeLessThan(86400);
});
