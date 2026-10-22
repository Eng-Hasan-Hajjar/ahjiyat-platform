<?php

namespace App\Services\Reputation;

use App\Models\User;
use App\Services\Discovery\DiscoverCard;

/**
 * E37 - أقسام الاكتشاف المعتدلة من السمعة: «مبدعون مختارون» (تحريري) و«مبدعون صاعدون» (آخر 30 يومًا). **بطاقات قليلة (discover_limit) ورابط واحد لصفحة الترتيبات**.
 * معزولة الفشل (تعطّلها لا يكسر مركز الاكتشاف)، تحترم الحظر، لا درجات خام ولا مؤشر مالي. ليست مدخل توصية لحساب شخصي ولا تغيّر ترتيب البحث بأي شكل.
 */
class ReputationDiscovery
{
    public function __construct(protected ReputationRankings $rankings, protected ReputationSettings $settings) {}

    /** @return array{featured: list<DiscoverCard>, rising: list<DiscoverCard>} */
    public function safeSections(?User $viewer): array
    {
        $empty = ['featured' => [], 'rising' => []];

        if (! $this->settings->enabled()) {
            return $empty;
        }

        try {
            $limit = max(1, $this->settings->int('rankings.discover_limit', 4));

            return [
                'featured' => array_map(fn (array $r) => new DiscoverCard($r['name'], route('creators.show', $r['slug']), 'trophy', null, 'مبدع مختار', 'warning'),
                    $this->rankings->featured($viewer, $limit)),
                'rising' => array_map(fn (array $r) => new DiscoverCard($r['name'], route('creators.show', $r['slug']), 'sparkles', null, 'صاعد', 'success', 'نشاط وجودة آخر '.$r['window_days'].' يومًا'),
                    $this->rankings->rising($viewer, $limit)),
            ];
        } catch (\Throwable $e) {
            report($e);

            return $empty;
        }
    }
}
