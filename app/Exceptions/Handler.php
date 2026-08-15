<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Customize 404 for model not found
        $this->renderable(function (NotFoundHttpException $e, $request) {
            if (!$request->expectsJson()) {
                return response()->view('errors.404', [], 404);
            }
        });

        $this->renderable(function (ModelNotFoundException $e, $request) {
            if (!$request->expectsJson()) {
                return response()->view('errors.404', [], 404);
            }
        });
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'غير مصادق عليك.'], 401);
        }

        return redirect()->guest(route('login'));
    }
}