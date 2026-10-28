<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorSeriesSaleOffer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E43 - «القفل المميز» لعمل مشتق حيًّا: العمل مقفول ⇔ هو ضمن **النسخة المنشورة** لسلسلة لها عرض نشط أو موقوف. لا عمود مخزَّن ولا مزامنة ولا كاش: القفل يتبع النشر والعرض لحظيًا.
 *
 * الاستعلام الفرعي مفهرس بالكامل (creator_series_items.creator_puzzle_id ← النسخة بالمفتاح الأساسي ← السلسلة بالمفتاح الأساسي ← عروضها بـ(creator_series_id، status))
 * ويُضمَّن في استعلامات الأعمال القائمة كعمود (premium_lock_since) فلا استعلام إضافي لعمل مجاني. القيمة = أقدم first_activated_at بين العروض القافلة (NULL = حرّ).
 * مصدر واحد لثلاثة مستهلكين: بوابة الوصول (CreatorPremiumAccess)، وبطاقات القوائم (شارة «مميز»)، واستبعاد صندوق المبدعين (QualifiedUnitsQuery).
 */
final class PremiumLockQuery
{
    /** استعلام فرعي يعيد أقدم وقت تفعيل لعرض قافل للعمل المعطى (عمود العمل الخارجي بالاسم المؤهَّل). */
    public static function lockSince(string $workIdColumn): Builder
    {
        return self::base($workIdColumn)->selectRaw('MIN(plo.first_activated_at)');
    }

    /**
     * يستبعد من استعلام محاولات (a.creator_puzzle_id, a.created_at) كل محاولة وقعت داخل نافذة عرض مميز لعملها: من أول تفعيل (first_activated_at) حتى الإغلاق (retired_at) إن أُغلق (E43 مع E39: مسار تسييل واحد للمحتوى المميز).
     * الأثر مستقبلي فقط: المحاولات السابقة للتفعيل تعدّ كما كانت (لا مساس بتاريخ E39)، والفترات المقفلة لا تُعاد حسابها (تخصيصاتها لقطة).
     */
    public static function excludeFromFund(Builder $attempts, string $workColumn = 'a.creator_puzzle_id', string $timeColumn = 'a.created_at'): Builder
    {
        return $attempts->whereNotExists(function ($q) use ($workColumn, $timeColumn) {
            $q->selectRaw('1')->from('creator_series_items as pli')
                ->join('creator_series_revisions as plr', 'plr.id', '=', 'pli.creator_series_revision_id')
                ->join('creator_series as pls', function ($j) {
                    $j->on('pls.id', '=', 'plr.creator_series_id')->on('pls.published_revision_id', '=', 'plr.id');
                })
                ->join('creator_series_sale_offers as plo', 'plo.creator_series_id', '=', 'pls.id')
                ->whereColumn('pli.creator_puzzle_id', $workColumn)
                ->whereNotNull('plo.first_activated_at')
                ->whereColumn($timeColumn, '>=', 'plo.first_activated_at')
                // نافذة العرض: من أول تفعيل حتى الإغلاق إن أُغلق (premium→free لا يُعيد احتساب ما لُعب أثناء قفله)؛ الموقوف مؤقتًا ما زال قافلًا.
                ->where(fn ($w) => $w->whereNull('plo.retired_at')->orWhereColumn($timeColumn, '<', 'plo.retired_at'));
        });
    }

    private static function base(string $workIdColumn): Builder
    {
        return DB::table('creator_series_items as pli')
            ->join('creator_series_revisions as plr', 'plr.id', '=', 'pli.creator_series_revision_id')
            ->join('creator_series as pls', function ($j) {
                $j->on('pls.id', '=', 'plr.creator_series_id')->on('pls.published_revision_id', '=', 'plr.id');
            })
            ->join('creator_series_sale_offers as plo', 'plo.creator_series_id', '=', 'pls.id')
            ->whereColumn('pli.creator_puzzle_id', $workIdColumn)
            ->whereIn('plo.status', CreatorSeriesSaleOffer::LOCKING_STATUSES);
    }
}
