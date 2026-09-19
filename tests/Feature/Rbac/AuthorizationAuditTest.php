<?php

use App\Filament\Resources\UserResource\RoleAssignmentSaver;
use App\Models\AuthorizationAuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationAuditService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('assigning a role to a user is recorded in the audit log with the actor and the change', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $moderatorRole = Role::where('name', 'moderator')->firstOrFail();

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, [$moderatorRole->id]);

    $log = AuthorizationAuditLog::where('action', 'user_roles_updated')->where('subject_id', $target->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($actor->id)
        ->and($log->metadata['added_roles'])->toContain('moderator');
});

test('a role permission change is recorded with added and removed permission diffs, not a full snapshot', function () {
    $actor = User::factory()->create();
    $role = Role::create(['name' => 'diff-role', 'guard_name' => 'web']);
    $role->givePermissionTo('puzzles.view');

    app(AuthorizationAuditService::class)->log('role_permissions_updated', $role, [
        'added_permissions' => ['seasons.view'],
        'removed_permissions' => ['puzzles.view'],
    ], $actor);

    $log = AuthorizationAuditLog::where('action', 'role_permissions_updated')->where('subject_id', $role->id)->first();

    expect($log->metadata)->toHaveKeys(['added_permissions', 'removed_permissions'])
        ->and($log->metadata)->not->toHaveKey('all_permissions_snapshot');
});

test('removing a role from a user is also recorded in the audit log', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $target->assignRole('support');

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, []);

    $log = AuthorizationAuditLog::where('action', 'user_roles_updated')->where('subject_id', $target->id)->latest()->first();

    expect($log->metadata['removed_roles'])->toContain('support');
});

test('no audit log is written when the role assignment does not actually change anything', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $supportRole = Role::where('name', 'support')->firstOrFail();
    $target->assignRole('support');

    AuthorizationAuditLog::truncate();

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, [$supportRole->id]);

    expect(AuthorizationAuditLog::count())->toBe(0);
});

test('the audit log is immutable - no updated_at column is tracked', function () {
    $log = AuthorizationAuditLog::create(['action' => 'role_created', 'metadata' => []]);

    expect(array_key_exists('updated_at', $log->getAttributes()))->toBeFalse();
});

test('a non-privileged user cannot view the authorization audit log resource', function () {
    $user = User::factory()->create();
    $user->assignRole('player');

    $this->actingAs($user);

    expect(\App\Filament\Resources\AuthorizationAuditLogResource::canViewAny())->toBeFalse();
});