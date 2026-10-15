<?php

namespace App\Services\Search;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E27: هل نسمح بالمطابقة الجزئية (مسح الجدول مع طيّ الحروف) لهذا الجدول؟ فقط إذا كان أكبر معرّف ≤ الحدّ المضبوط.
 * القراءة: MAX(id) نقطة على المفتاح الأساسي (O(1))، وتُحفظ لقطة قصيرة. فشل الكاش = قراءة مباشرة (انظر CachedSnapshot). لا بيانات مستخدمين.
 */
final class PartialProbe
{
    public function allowed(string $type, string $table): bool
    {
        $max = (int) config("search.partial_match_max_id_by_type.{$type}", config('search.partial_match_max_id', 10000));
        $ttl = (int) config('search.partial_probe_ttl', 600);
        $maxId = (int) CachedSnapshot::remember('search.probe.'.TextFold::identifier($table), $ttl, fn () => (int) DB::table($table)->max('id'));

        return $maxId <= $max;
    }
}
