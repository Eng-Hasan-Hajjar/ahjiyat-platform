<?php

use App\Filament\Pages\OperationsCenter;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * E25 (11-12) - مركز العمليات: إشارات النظام تُعرض لمن يملك system.audit_view فقط، وبلا أي سر.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::forget('ops:signals:v1');
    Cache::forget(app(SchedulerHeartbeat::class)->key());
});

function e25_super_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    return $user;
}

test('E25-11: the system signals render for a user with system.audit_view (release, scheduler, queue, readiness)', function () {
    config(['operations.release.id' => 'b983867a5c214ee19c8edfc99319f04b8d3427c5']);
    app(SchedulerHeartbeat::class)->beat();

    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)
        ->assertSee('صحة النظام')
        ->assertSee('b983867')
        ->assertSee('سليم')               // نبض المجدول ok
        ->assertSee('منتظرة')             // الطابور
        ->assertSee('DB OK')
        ->assertSee('Cache OK');
});

test('E25-11: the scheduler tile reflects an unknown, stale and down heartbeat', function () {
    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)->assertSee('غير معروف');

    Cache::forget('ops:signals:v1');
    $this->travelTo(now()->startOfMinute());
    app(SchedulerHeartbeat::class)->beat();
    $this->travel(400)->seconds();
    Cache::forget('ops:signals:v1');

    Livewire::test(OperationsCenter::class)->assertSee('متأخر');

    $this->travel(700)->seconds();
    Cache::forget('ops:signals:v1');

    Livewire::test(OperationsCenter::class)->assertSee('متوقف');
});

test('E25-11: a debug-enabled app is flagged prominently', function () {
    config(['app.debug' => true]);
    $this->actingAs(e25_super_admin());

    Livewire::test(OperationsCenter::class)->assertSee('APP_DEBUG مفعَّلة');
});

test('E25-12: the signals never expose secrets, hostnames, credentials or the app key', function () {
    config([
        'database.connections.mysql.password' => 'OpsCenterDbPassword-VALUE-123',
        'mail.mailers.smtp.password' => 'OpsCenterMailPassword-VALUE-456',
        'broadcasting.connections.reverb.secret' => 'OpsCenterReverbSecret-VALUE-789',
        'database.redis.default.password' => 'OpsCenterRedisPassword-VALUE-012',
        'mail.mailers.smtp.host' => 'smtp.private-host.example',
    ]);
    $this->actingAs(e25_super_admin());

    $html = Livewire::test(OperationsCenter::class)->html();

    foreach (['OpsCenterDbPassword-VALUE-123', 'OpsCenterMailPassword-VALUE-456', 'OpsCenterReverbSecret-VALUE-789', 'OpsCenterRedisPassword-VALUE-012', 'smtp.private-host.example', (string) config('app.key'), substr((string) config('app.key'), 7)] as $secret) {
        expect($html)->not->toContain($secret);
    }

    // أسماء المشغّلات فقط (driver names)
    expect($html)->toContain('cache=')->toContain('queue=');
});

test('E25-12: the signal snapshot itself contains only whitelisted keys and no config values', function () {
    config(['database.connections.mysql.password' => 'SnapshotPassword-VALUE-345']);

    $snapshot = app(App\Services\Operations\OperationalSignals::class)->compute();
    $dump = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

    expect(array_keys($snapshot))->toBe(['release', 'environment', 'debug', 'maintenance', 'drivers', 'scheduler', 'queue', 'queue_warnings', 'readiness', 'generated_at'])
        ->and($dump)->not->toContain('SnapshotPassword-VALUE-345')
        ->and($dump)->not->toContain((string) config('app.key'));
});

test('E25-12: a user without system.audit_view does not get the system signals at all', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    config(['operations.release.id' => 'hidden-release-1']);

    expect($moderator->can('system.audit_view'))->toBeFalse();

    $this->actingAs($moderator);
    $page = Livewire::test(OperationsCenter::class);

    expect($page->instance()->getSystemSignals())->toBeNull();
    $page->assertDontSee('صحة النظام')->assertDontSee('hidden-release-1');
});

test('E25: the signals snapshot is cached for 30 seconds (the page does not recompute on every refresh)', function () {
    $this->actingAs(e25_super_admin());
    $queries = 0;
    $signals = app(App\Services\Operations\OperationalSignals::class);

    $first = $signals->snapshot();

    Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
        $queries++;
    });
    $second = $signals->snapshot();

    expect($second)->toBe($first)->and($queries)->toBe(0);
});
