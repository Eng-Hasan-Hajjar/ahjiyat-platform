<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E43 - بيانات كتلة «سلسلة مميزة» في صفحة السلسلة العامة. قراءة فقط: **لا يمنح ولا يعدّل شيئًا**، والقرار الحقيقي للشراء يبقى في CreatorSeriesPurchaseService عند الإرسال.
 * العرض (الحالة/السعر/العملة) يأتي مع استعلام السلسلة نفسه (أعمدة premium_*) فلا استعلام إضافي للسلاسل الحرة؛ للمميزة استعلامان على الأكثر للمشاهد المسجَّل (الملكية + الطلب المفتوح).
 *
 * state: creator | guest | owned | cannot_buy | disabled | paused | continue (طلب مفتوح) | buyable
 */
class CreatorPremiumSeriesPresenter
{
    public function __construct(
        protected CreatorPremiumPolicy $policy,
        protected CreatorPremiumAccess $access,
        protected CreatorSeriesPurchaseService $purchases,
    ) {}

    /**
     * @return array{status: string, price: string, state: string, request_key: ?string, order: ?string}|null null = سلسلة حرة (لا كتلة)
     */
    public function forPage(?User $viewer, CreatorSeries $series, bool $isCreator): ?array
    {
        $attributes = $series->getAttributes();
        $status = $attributes['premium_status'] ?? null;

        if (! in_array($status, CreatorSeriesSaleOffer::LOCKING_STATUSES, true)) {
            return null;
        }

        $out = [
            'status' => (string) $status,
            'price' => SponsorshipMoney::format((int) $attributes['premium_price_minor'], (string) $attributes['premium_currency']),
            'state' => 'buyable',
            'request_key' => null,
            'order' => null,
        ];

        if ($isCreator) {
            return ['state' => 'creator'] + $out;
        }

        if ($viewer === null) {
            return ['state' => 'guest'] + $out;
        }

        if ($this->access->ownsSeries($viewer, $series)) {
            return ['state' => 'owned'] + $out;
        }

        if (! $viewer->hasVerifiedEmail() || $viewer->is_frozen) {
            return ['state' => 'cannot_buy'] + $out;
        }

        if ($status === CreatorSeriesSaleOffer::STATUS_PAUSED) {
            return ['state' => 'paused'] + $out;
        }

        if (! $this->policy->enabled()) {
            return ['state' => 'disabled'] + $out;
        }

        $open = $this->purchases->keyedPurchase($viewer, $series);

        if ($open !== null && $open->status === DigitalPurchase::STATUS_AWAITING) {
            return ['state' => 'continue', 'order' => $open->public_id] + $out;
        }

        return ['state' => 'buyable', 'request_key' => bin2hex(random_bytes(12))] + $out;
    }
}
