<?php

use App\Models\Session as SessionModel;
use App\Models\User;
use App\Services\SessionManagementService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('security.sessions_view and security.sessions_revoke are separate permissions and both required appropriately', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $limited = User::factory()->create();
    $limited->assignRole('support');

    expect($admin->can('security.sessions_view'))->toBeTrue()
        ->and($admin->can('security.sessions_revoke'))->toBeTrue()
        ->and($limited->can('security.sessions_view'))->toBeFalse()
        ->and($limited->can('security.sessions_revoke'))->toBeFalse();
});

test('revoking a session deletes its row using the current database session driver', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    SessionModel::query()->insert([
        'id' => 'session-to-revoke',
        'user_id' => $target->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestAgent/1.0',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $session = SessionModel::find('session-to-revoke');
    app(SessionManagementService::class)->revoke($session, $actor);

    expect(SessionModel::find('session-to-revoke'))->toBeNull();
});

test('an admin cannot revoke a super admin session without being a super admin themselves', function () {
    $normalAdmin = User::factory()->create();
    $normalAdmin->assignRole('administrator');

    $superAdminUser = User::factory()->create();
    $superAdminUser->assignRole('super-admin');

    SessionModel::query()->insert([
        'id' => 'super-admin-session',
        'user_id' => $superAdminUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestAgent/1.0',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $session = SessionModel::find('super-admin-session');

    expect(fn () => app(SessionManagementService::class)->revoke($session, $normalAdmin))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('revoking all sessions for a user returns the correct count and removes them', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');
    $target = User::factory()->create();

    foreach (['s1', 's2', 's3'] as $id) {
        SessionModel::query()->insert([
            'id' => $id, 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'A', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
        ]);
    }

    $count = app(SessionManagementService::class)->revokeAllForUser($target, $actor, keepCurrentIfSelf: false);

    expect($count)->toBe(3)
        ->and(SessionModel::where('user_id', $target->id)->count())->toBe(0);
});