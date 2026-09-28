<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a new user automatically gets a non-null unique ULID public_id, never derived from the email or numeric id', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    foreach ([$a, $b] as $user) {
        expect($user->public_id)->not->toBeNull()
            ->and($user->public_id)->toMatch('/^[0-9a-z]{26}$/i')
            ->and($user->public_id)->not->toContain('@')
            ->and($user->public_id)->not->toBe((string) $user->id);
    }

    expect($a->public_id)->not->toBe($b->public_id);
});

test('public_id cannot be mass assigned by a client - the server value always wins', function () {
    $user = User::create([
        'name' => 'محاولة تلاعب',
        'email' => 'tamper-attempt@example.com',
        'password' => 'password-123',
        'public_id' => 'HACKED-PUBLIC-ID',
    ]);

    expect($user->fresh()->public_id)->not->toBe('HACKED-PUBLIC-ID')
        ->and($user->fresh()->public_id)->toMatch('/^[0-9a-z]{26}$/i');
});

test('sensitive account fields are not mass assignable', function () {
    $user = User::create([
        'name' => 'محاولة تصعيد',
        'email' => 'escalation-attempt@example.com',
        'password' => 'password-123',
        'is_frozen' => true,
        'role' => 'admin',
    ]);

    $fresh = $user->fresh();

    expect($fresh->is_frozen)->toBeFalse()
        ->and($fresh->role)->not->toBe('admin');
});

test('the database itself rejects a duplicate public_id', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $b->id)->update(['public_id' => $a->public_id]))
        ->toThrow(QueryException::class);
});