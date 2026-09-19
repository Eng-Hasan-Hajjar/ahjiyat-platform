<?php

namespace App\Providers;

use App\Services\AuthorizationSafetyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });
    }
}