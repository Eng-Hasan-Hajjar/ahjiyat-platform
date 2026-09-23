<?php

test('DIAGNOSTIC - reveal what actually happens on registration', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    dump('STATUS: '.$response->status());
    dump('REDIRECT TARGET: '.($response->headers->get('Location') ?? 'NONE'));
    dump('USER EXISTS IN DB: '.(\App\Models\User::where('email', 'test@example.com')->exists() ? 'YES' : 'NO'));
    dump('AUTH CHECK: '.(auth()->check() ? 'YES' : 'NO'));

    if ($response->status() >= 500) {
        dump('CONTENT SNIPPET: '.substr(strip_tags($response->getContent()), 0, 500));
    }

    expect(true)->toBeTrue();
});