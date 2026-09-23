<?php

test('the health check endpoint returns 200 when the application is healthy', function () {
    $response = $this->get('/up');

    $response->assertStatus(200);
});

test('the health check response does not leak any secret or infrastructure detail', function () {
    $response = $this->get('/up');
    $content = $response->getContent();

    expect($content)->not->toContain(config('app.key'))
        ->not->toContain(config('database.connections.'.config('database.default').'.host') ?? '__no_host__')
        ->not->toContain('DB_PASSWORD')
        ->not->toContain(base_path())
        ->not->toContain('mysql')
        ->not->toContain('sqlite');
});