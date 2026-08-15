<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        if (!$request->user()) {
            return redirect()->route('login');
        }

        if (!in_array($request->user()->role, $roles)) {
            abort(403, 'غير مصرح لك بالوصول إلى هذه الصفحة.');
        }

        return $next($request);
    }
}