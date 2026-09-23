<?php

test('the command fails when APP_DEBUG is enabled', function () {
    config(['app.debug' => true]);

    $this->artisan('production:check')->assertExitCode(1);
});

test('the command passes when APP_DEBUG is disabled and the environment is otherwise healthy', function () {
    config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com']);

    $this->artisan('production:check')->assertExitCode(0);
});

test('the command warns but does not fail when APP_URL is not HTTPS', function () {
    config(['app.debug' => false, 'app.url' => 'http://localhost']);

    $this->artisan('production:check')->assertExitCode(0);
});

test('the command never prints the actual APP_KEY value', function () {
    $key = config('app.key');

    $this->artisan('production:check')
        ->expectsOutputToContain('APP_KEY موجود')
        ->doesntExpectOutputToContain($key);
});

test('the command never prints a database password or connection secret', function () {
    config(['database.connections.mysql.password' => 'super-secret-value-xyz']);

    $this->artisan('production:check')
        ->doesntExpectOutputToContain('super-secret-value-xyz');
});