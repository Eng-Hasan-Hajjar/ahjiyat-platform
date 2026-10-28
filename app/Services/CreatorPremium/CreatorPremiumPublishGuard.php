<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\CreatorSeriesSaleOffer;
use Illuminate\Support\Facades\DB;

/**
 * E43 - حارس النشر بعد البيع. سلسلة لها مشترون فعليون (وصول فعّال) لا يجوز أن تُسقط نسخة جديدة منها أحجيةً من النسخة المنشورة الحالية **بصمت**:
 * الإضافات المتوافقة (أحجيات جديدة/إعادة ترتيب) مسموحة والمالكون يحتفظون بوصولهم، أما حذف عنصر مباع فيحتاج تأكيدًا صريحًا من المراجع مع ملاحظة وتدقيق.
 * لا يعيد كتابة أي لقطة: الوصول مرتبط بالسلسلة لا بنسخة، ويُقرأ من النسخة المنشورة الحالية بعد الاعتماد.
 */
class CreatorPremiumPublishGuard
{
    /** سلسلة لها وصول مدفوع فعّال (مبيعات قائمة). */
    public function hasActiveOwners(CreatorSeries|int $series): bool
    {
        return DB::table('creator_series_accesses')
            ->where('creator_series_id', $series instanceof CreatorSeries ? $series->getKey() : $series)
            ->whereNull('revoked_at')
            ->exists();
    }

    /** سلسلة بعرض نشط/موقوف الآن (تُحجب أرشفتها قبل إغلاق العرض). */
    public function hasLiveOffer(CreatorSeries|int $series): bool
    {
        return DB::table('creator_series_sale_offers')
            ->where('creator_series_id', $series instanceof CreatorSeries ? $series->getKey() : $series)
            ->whereIn('status', CreatorSeriesSaleOffer::LOCKING_STATUSES)
            ->exists();
    }

    /**
     * أعمال النسخة المنشورة الحالية التي تُسقطها النسخة المرشَّحة (فارغة = لا خطر، أو لا مشترين).
     *
     * @return list<int> أرقام الأعمال المحذوفة
     */
    public function soldItemsRemoved(CreatorSeries $series, CreatorSeriesRevision $candidate): array
    {
        if ($series->published_revision_id === null || (int) $series->published_revision_id === (int) $candidate->getKey() || ! $this->hasActiveOwners($series)) {
            return [];
        }

        return DB::table('creator_series_items as cur')
            ->where('cur.creator_series_revision_id', $series->published_revision_id)
            ->whereNotExists(function ($q) use ($candidate) {
                $q->selectRaw('1')->from('creator_series_items as nxt')
                    ->whereColumn('nxt.creator_puzzle_id', 'cur.creator_puzzle_id')
                    ->where('nxt.creator_series_revision_id', $candidate->getKey());
            })
            ->pluck('cur.creator_puzzle_id')->map(fn ($id) => (int) $id)->all();
    }
}
