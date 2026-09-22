<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class AnalyticsCache
{
    protected const VERSION_KEY = 'analytics:cache_version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    public static function key(string $suffix): string
    {
        return 'analytics:v'.self::version().':'.$suffix;
    }
}