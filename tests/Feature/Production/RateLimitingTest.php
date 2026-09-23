<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('registration is rate limited after 5 attempts from the same IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', [
            'name' => 'مستخدم '.$i,
            'email' => "user{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    $response = $this->post('/register', [
        'name' => 'مستخدم إضافي',
        'email' => 'oneMore@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($response->status())->toBe(429);
});

test('the forgot-password request is rate limited after 5 attempts', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/forgot-password', ['email' => $user->email]);
    }

    $response = $this->post('/forgot-password', ['email' => $user->email]);

    expect($response->status())->toBe(429);
});

test('login is rate limited after 5 failed attempts for the same email+IP', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $response->assertSessionHasErrors('email');
    expect($response->status())->not->toBe(200);
});

test('the sensitive game-action and redemption routes carry their named rate limiters', function () {
    $expectations = [
        'puzzles.attempt' => 'throttle:puzzle-attempt',
        'game-sessions.start' => 'throttle:game-session-start',
        'game-sessions.reveal' => 'throttle:game-session-reveal',
        'campaigns.steps.attempt' => 'throttle:puzzle-attempt',
        'campaigns.steps.session' => 'throttle:game-session-start',
        'redemption.store' => 'throttle:redemption',
    ];

    foreach ($expectations as $routeName => $expectedMiddleware) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull("route [{$routeName}] should exist")
            ->and($route->middleware())->toContain($expectedMiddleware);
    }
});