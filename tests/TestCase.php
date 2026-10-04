<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * refreshApplication() تسبق setUpTraits() (أي تسبق migrate:fresh الخاصة
     * بـRefreshDatabase)، فيتوقف التشغيل هنا قبل أن يُمسَح أي شيء.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $config = config("database.connections.{$connection}", []);

        TestDatabaseGuard::assertSafe(
            (string) ($config['driver'] ?? ''),
            isset($config['database']) ? (string) $config['database'] : null,
            $this->app->configurationIsCached(),
        );
    }
}
