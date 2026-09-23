<?php

use App\Http\Middleware\EnsureAccountIsNotFrozen;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackDeviceFingerprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'account.active' => EnsureAccountIsNotFrozen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();