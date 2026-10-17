<?php

namespace App\Services\Sponsorship;

use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Illuminate\Support\Collection;

/**
 * E30 - مستوى الداعم **مشتقّ دائمًا** من صافي دعمه المؤكَّد (SupportTotals) مقابل مستويات الإدارة النشطة: أعلى مستوى حدّه الأدنى ≤ الصافي.
 * لا يُخزَّن على الداعم ولا يُقبل من العميل أبدًا؛ فالاسترداد يخفّضه آليًا والتأكيد يرفعه. تعادل الحدّ الأدنى بين مستويين يُحسم بـsort_order ثم المعرّف (قطعي).
 * قائمة المستويات (إعداد) تُخزَّن مؤقتًا بكاش الرعاية وتُبطَل بأي تغيير (SponsorshipCache::bust)؛ المبلغ نفسه لا يُخزَّن بالكاش.
 */
class SupporterTierService
{
    public function __construct(protected SupportTotals $totals) {}

    /** @return Collection<int, SupporterTier> نشطة مرتبة بالحدّ الأدنى تصاعديًا. */
    public function tiers(): Collection
    {
        $rows = SponsorshipCache::remember('tiers', [], 300, fn () => SupporterTier::query()->where('is_active', true)
            ->orderBy('minimum_accounting_amount_minor')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'slug', 'minimum_accounting_amount_minor', 'sort_order', 'badge_key'])->map->getAttributes()->all());

        return collect($rows)->map(fn (array $row) => (new SupporterTier)->forceFill($row));
    }

    public function forAmount(int $netMinor): ?SupporterTier
    {
        if ($netMinor <= 0) {
            return null;
        }

        return $this->tiers()->filter(fn (SupporterTier $t) => (int) $t->minimum_accounting_amount_minor <= $netMinor)->last();
    }

    public function forSupporter(SupporterProfile|int $supporter): ?SupporterTier
    {
        return $this->forAmount($this->totals->net($supporter instanceof SupporterProfile ? $supporter->id : $supporter));
    }

    /** @param list<int> $supporterIds @return array<int, ?SupporterTier> */
    public function forMany(array $supporterIds): array
    {
        $net = $this->totals->netMany($supporterIds);
        $out = [];

        foreach ($supporterIds as $id) {
            $out[$id] = $this->forAmount($net[$id] ?? 0);
        }

        return $out;
    }
}
