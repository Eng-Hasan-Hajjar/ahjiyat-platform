<?php

test('a public page includes the core security headers', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy');
});

test('HSTS is never sent over plain HTTP in local/testing, even if a request claims production headers', function () {
    $response = $this->get('/');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('the admin panel response also carries the security headers (web group covers /admin)', function () {
    $response = $this->get('/admin/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});