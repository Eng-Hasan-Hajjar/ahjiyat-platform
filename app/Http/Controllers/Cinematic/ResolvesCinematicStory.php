<?php

namespace App\Http\Controllers\Cinematic;

use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicException;
use Illuminate\Http\JsonResponse;

/** CIN-1 - أدوات مشتركة صغيرة للـcontrollers: حلّ القصة بالمعرّف العام أو الـslug، وتحويل استثناءات المجال إلى JSON آمن. */
trait ResolvesCinematicStory
{
    protected function story(string $key): CinematicStory
    {
        return CinematicStory::query()
            ->where(fn ($q) => $q->where('public_id', $key)->orWhere('slug', $key))
            ->firstOrFail();
    }

    protected function failure(CinematicException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->httpStatus);
    }
}
