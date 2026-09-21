<?php

use App\Http\Middleware\EnsureAccountIsNotFrozen;
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
        // كل زيارة (ويب + API) تمر من هنا لتسجيل بصمة الجهاز/الـ IP
        // اللازمة لخدمة مكافحة الاحتيال (انظر FraudDetectionService).
        $middleware->web(append: [
            TrackDeviceFingerprint::class,
        ]);

        $middleware->api(append: [
            TrackDeviceFingerprint::class,
        ]);

        $middleware->throttleApi();

        // E6: يُطبَّق صراحة فقط على مجموعة الأفعال الحساسة بـroutes/web.php
        // (انظر EnsureAccountIsNotFrozen) - لا على كل الموقع.
        $middleware->alias([
            'account.active' => EnsureAccountIsNotFrozen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();