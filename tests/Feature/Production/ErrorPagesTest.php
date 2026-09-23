<?php

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a 404 page renders a safe Arabic UI with no stack trace or file paths', function () {
    $response = $this->get('/this-route-does-not-exist-at-all');

    $response->assertStatus(404);
    $response->assertSee('الصفحة غير موجودة');
    $response->assertDontSee('Stack trace', false);
    $response->assertDontSee('vendor/laravel', false);
    $response->assertDontSee('.php', false);
});

test('a 403 page renders a safe Arabic UI for an unauthorized admin action', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $response = $this->actingAs($player)->get('/admin');

    $response->assertStatus(403);
    $response->assertSee('غير مصرح لك بالوصول');
    $response->assertDontSee('Exception', false);
    $response->assertDontSee('SQLSTATE', false);
});

test('a 429 page renders a safe Arabic UI and does not leak rate-limit internals', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', [
            'name' => 'مستخدم '.$i,
            'email' => "u{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    $response = $this->post('/register', [
        'name' => 'إضافي',
        'email' => 'extra@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(429);
    $response->assertSee('طلبات كثيرة جداً');
    $response->assertDontSee('RateLimiter', false);
    $response->assertDontSee('throttle:', false);
});

test('error pages never leak the application environment or debug details', function () {
    $response = $this->get('/this-route-does-not-exist-at-all');

    $response->assertDontSee('APP_ENV', false)
        ->assertDontSee('APP_KEY', false)
        ->assertDontSee(base_path(), false);
});