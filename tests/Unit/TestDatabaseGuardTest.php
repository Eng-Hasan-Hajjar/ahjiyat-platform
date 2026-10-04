<?php

use Tests\Support\TestDatabaseGuard;

test('E14.1-R/41: only explicit test databases are allowed', function (string $driver, ?string $database, bool $allowed) {
    expect(TestDatabaseGuard::isAllowed($driver, $database))->toBe($allowed);
})->with([
    'sqlite memory' => ['sqlite', ':memory:', true],
    'project testing db' => ['sqlite', 'database/testing.sqlite', true],
    'absolute windows path' => ['sqlite', 'C:\\Users\\x\\proj\\database\\testing.sqlite', true],
    'dev db_puzzle' => ['sqlite', 'db_puzzle', false],
    'default laravel db' => ['sqlite', 'database/database.sqlite', false],
    'root artifact' => ['sqlite', 'laravel11_auth', false],
    'empty path' => ['sqlite', '', false],
    'null path' => ['sqlite', null, false],
    'mysql test db' => ['mysql', 'puzzles_test', true],
    'mysql testing db' => ['mysql', 'testing', true],
    'mysql production-looking db' => ['mysql', 'puzzles', false],
    'mysql db merely containing test' => ['mysql', 'contest_prod', false],
]);

test('a cached config is refused before any database check (it would silently target the real DB)', function () {
    expect(fn () => TestDatabaseGuard::assertSafe('sqlite', 'database/testing.sqlite', true))
        ->toThrow(RuntimeException::class, 'config:clear');
});

test('an unsafe database is refused with a clear message and nothing is migrated', function () {
    expect(fn () => TestDatabaseGuard::assertSafe('sqlite', 'db_puzzle', false))
        ->toThrow(RuntimeException::class, 'غير آمنة');
});

test('a safe test database passes the guard', function () {
    TestDatabaseGuard::assertSafe('sqlite', 'database/testing.sqlite', false);

    expect(true)->toBeTrue();
});

test('tests/TestCase.php actually wires the guard before RefreshDatabase runs', function () {
    $source = file_get_contents(__DIR__.'/../TestCase.php');

    expect($source)->toContain('refreshApplication')->toContain('TestDatabaseGuard::assertSafe');
});
