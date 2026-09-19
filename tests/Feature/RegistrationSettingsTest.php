<?php

use App\Models\User;
use App\Services\PlatformSettingsService;

test('registration works normally when allow_registration is enabled (the default)', function () {
    $response = $this->post(route('register'), [
        'name' => 'مستخدم جديد',
        'email' => 'new-user@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect(route('verification.notice'));
    expect(User::where('email', 'new-user@example.com')->exists())->toBeTrue();
});

test('the registration form redirects to login with a message when registration is disabled', function () {
    app(PlatformSettingsService::class)->set('access', 'allow_registration', false);

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

test('submitting the registration form does not create a user when registration is disabled', function () {
    app(PlatformSettingsService::class)->set('access', 'allow_registration', false);

    $this->post(route('register'), [
        'name' => 'محاولة تسجيل',
        'email' => 'blocked-user@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertForbidden();

    expect(User::where('email', 'blocked-user@example.com')->exists())->toBeFalse();
});

test('login still works normally even when registration is disabled', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    app(PlatformSettingsService::class)->set('access', 'allow_registration', false);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('the admin panel remains accessible even when registration is disabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    app(PlatformSettingsService::class)->set('access', 'allow_registration', false);

    $this->actingAs($admin)->get('/admin/settings')->assertOk();
});

test('the "create account" button is hidden from the navbar when the CTA setting is off', function () {
    app(PlatformSettingsService::class)->set('access', 'show_registration_cta', false);

    $this->get(route('home'))->assertOk()->assertDontSee('إنشاء حساب');
});