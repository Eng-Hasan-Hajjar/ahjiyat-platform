<?php

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\User;
use App\Services\FraudDetectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E24 - تتبّع الجهاز كان يكتب في كل طلب. الآن مرة لكل (مستخدم، IP، جهاز) في النافذة، دون فقدان الكشف الأمني.
 */
function e24_sightings_touched(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'device_sightings'))->count();
    DB::disableQueryLog();

    return $n;
}

test('E24: a repeated request from the same user/IP/device within the window does not touch device_sightings', function () {
    $user = User::factory()->create();
    $server = ['REMOTE_ADDR' => '10.20.30.40', 'HTTP_USER_AGENT' => 'E24-Agent'];

    $first = e24_sightings_touched(fn () => $this->actingAs($user)->withServerVariables($server)->get('/puzzles')->assertOk());
    $second = e24_sightings_touched(fn () => $this->actingAs($user)->withServerVariables($server)->get('/puzzles')->assertOk());

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(DeviceSighting::where('user_id', $user->id)->count())->toBe(1);
});

test('E24: a different device or IP for the same user is still recorded', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.20.30.40', 'HTTP_USER_AGENT' => 'A'])->get('/puzzles')->assertOk();
    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.20.30.40', 'HTTP_USER_AGENT' => 'B'])->get('/puzzles')->assertOk();
    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.20.30.41', 'HTTP_USER_AGENT' => 'A'])->get('/puzzles')->assertOk();

    expect(DeviceSighting::where('user_id', $user->id)->count())->toBe(3);
});

test('E24: throttle of zero restores the old behavior (every request is recorded)', function () {
    config(['performance.device_sighting.throttle_seconds' => 0]);
    $user = User::factory()->create();
    $server = ['REMOTE_ADDR' => '10.20.30.40', 'HTTP_USER_AGENT' => 'E24-Agent'];

    $this->actingAs($user)->withServerVariables($server)->get('/puzzles');
    $second = e24_sightings_touched(fn () => $this->actingAs($user)->withServerVariables($server)->get('/puzzles'));

    expect($second)->toBeGreaterThan(0);
});

test('E24: the shared-IP fraud flag is still raised when five accounts use one IP', function () {
    $server = ['REMOTE_ADDR' => '10.99.99.99', 'HTTP_USER_AGENT' => 'Shared-Agent'];
    $users = User::factory()->count(5)->create();

    foreach ($users as $u) {
        $this->actingAs($u)->withServerVariables($server)->get('/puzzles')->assertOk();
    }

    expect(FraudFlag::where('reason', 'ip_shared')->where('user_id', $users->last()->id)->exists())->toBeTrue();
});

test('E24: if the cache is unavailable the sighting is still recorded (fail-open, security tracking is never lost)', function () {
    $user = User::factory()->create();
    config(['cache.default' => 'store-that-does-not-exist']);

    app(FraudDetectionService::class)->recordSighting($user, '10.5.5.5', 'UA');

    expect(DeviceSighting::where('user_id', $user->id)->where('ip_address', '10.5.5.5')->count())->toBe(1);
});

test('E24: the throttle key never contains the raw IP or User-Agent', function () {
    $user = User::factory()->create();
    app(FraudDetectionService::class)->recordSighting($user, '203.0.113.77', 'Very-Distinct-UA/1.0');

    $store = Cache::getStore();
    $keys = array_keys((fn () => $this->storage)->call($store));

    foreach ($keys as $k) {
        expect($k)->not->toContain('203.0.113.77')->and($k)->not->toContain('Very-Distinct-UA');
    }
    expect(collect($keys)->filter(fn ($k) => str_contains($k, 'sighting:'))->count())->toBe(1);
});

test('E24: an unresolved ip_shared flag is not duplicated, and a new one is raised after an admin resolves it', function () {
    config(['performance.device_sighting.throttle_seconds' => 0]); // كل طلب يفحص (كالسلوك القديم) كي نثبت منع التكرار نفسه
    $server = ['REMOTE_ADDR' => '10.77.77.77', 'HTTP_USER_AGENT' => 'Shared-Agent-2'];
    $users = User::factory()->count(5)->create();
    $last = $users->last();

    foreach ($users as $u) {
        $this->actingAs($u)->withServerVariables($server)->get('/puzzles')->assertOk();
    }
    $this->actingAs($last)->withServerVariables($server)->get('/puzzles')->assertOk();
    $this->actingAs($last)->withServerVariables($server)->get('/puzzles')->assertOk();

    expect(FraudFlag::where('user_id', $last->id)->where('reason', 'ip_shared')->count())->toBe(1);

    FraudFlag::where('user_id', $last->id)->where('reason', 'ip_shared')->update(['resolved' => true]);
    $this->actingAs($last)->withServerVariables($server)->get('/puzzles')->assertOk();

    expect(FraudFlag::where('user_id', $last->id)->where('reason', 'ip_shared')->count())->toBe(2)
        ->and(FraudFlag::where('user_id', $last->id)->where('reason', 'ip_shared')->where('resolved', false)->count())->toBe(1);
});
