<?php

namespace App\Http\Middleware;

use App\Services\FraudDetectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackDeviceFingerprint
{
    public function __construct(protected FraudDetectionService $fraud) {}

    public function handle(Request $request, Closure $next): Response
    {
        // تسجيل خفيف وغير حاجب - أي فشل هنا ما لازم يوقف الطلب الأساسي
        try {
            $this->fraud->recordSighting(
                $request->user(),
                $request->ip(),
                (string) $request->userAgent()
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return $next($request);
    }
}
