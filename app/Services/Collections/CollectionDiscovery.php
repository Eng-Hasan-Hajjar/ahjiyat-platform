<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;

/**
 * E35 / E27 - قسم «مجموعات من المجتمع» في الاكتشاف.
 *
 * الترتيب (شفاف ومحدود، وثيقة config/collections.php):
 *   score = w_saves*ln(1+saves_count) + w_recency*max(0, 1 - أيام_منذ_آخر_تحديث/recent_days) + w_items*min(الأعمال_الظاهرة,10)/10
 * saves_count = حافظون **مميَّزون** (قيد فريد) لا تكرار؛ لا مشاهدات خام؛ لا ترتيب مدفوع؛ لا أثر اقتصادي.
 * التنوّع: سقف مجموعات لكل أمين (per_owner_cap). الحدّ الأدنى لأعمال المجموعة الظاهرة (min_visible_items، 3 افتراضيًا).
 *
 * **الكاش لا يتجاوز الإشراف/الخصوصية/الحظر**: ما يُخزَّن معرّفات ودرجات فقط (لا عناوين ولا أسماء ولا بيانات مشاهد)؛ وبكل طلب تُعاد المرشَّحات عبر
 * CollectionAccess::listableQuery (عامة ∧ غير مخفية ∧ مالك سليم وملفه عام ∧ ≥ الحدّ الأدنى ∧ بلا حظر مع المشاهد) فيختفي المخفي فورًا ولو بقي بالكاش.
 * عدد الاستعلامات ثابت: لقطة (مرة لكل TTL) + إعادة فحص واحدة + لا شيء لكل بطاقة.
 */
class CollectionDiscovery
{
    public const CACHE_KEY = 'collections.discover.pool';

    public function __construct(protected CollectionAccess $access) {}

    /**
     * عزل الفشل: قسم المجموعات اختياري في الاكتشاف، فأي استثناء فيه لا يكسر الصفحة (يُسجَّل خادميًا ويُسقَط القسم).
     * التسجيل هنا لا في DiscoveryService عمدًا: ملفات E27 (بحث/اكتشاف) ممنوع فيها أي تسجيل لئلا يتسرّب مصطلح بحث إلى السجلات.
     *
     * @return list<DiscoverCard>
     */
    public function safeCards(?User $viewer): array
    {
        try {
            return $this->cards($viewer);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<DiscoverCard> */
    public function cards(?User $viewer): array
    {
        $limit = max(1, (int) config('collections.discover.section_limit'));
        $min = max(1, (int) config('collections.discover.min_visible_items'));
        $cap = max(1, (int) config('collections.discover.per_owner_cap'));

        /** @var array<int, float> $pool id => score، مرتَّبة تنازليًا */
        $pool = $this->pool();

        if ($pool === []) {
            return [];
        }

        $rows = $this->access->listableQuery($viewer, $min)
            ->whereIn('user_collections.id', array_keys($pool))
            ->select('user_collections.id', 'user_collections.public_id', 'user_collections.title', 'user_collections.user_id', 'user_collections.saves_count', 'owner.name as owner_name')
            ->get()->keyBy('id');

        $perOwner = [];
        $cards = [];

        foreach (array_keys($pool) as $id) {
            $row = $rows->get($id);

            if ($row === null) {
                continue;
            }

            $owner = (int) $row->user_id;
            $perOwner[$owner] = ($perOwner[$owner] ?? 0) + 1;

            if ($perOwner[$owner] > $cap) {
                continue;
            }

            $saves = (int) $row->saves_count;
            $cards[] = new DiscoverCard(
                (string) $row->title,
                route('collections.public.show', $row->public_id),
                'inventory',
                'بقلم '.$row->getAttribute('owner_name'),
                'مجموعة',
                'primary',
                $saves > 0 ? number_format($saves).' حفظ' : null,
            );

            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    /** @return array<int, float> */
    public function pool(): array
    {
        $ttl = (int) config('collections.discover.cache_ttl');

        return CachedSnapshot::remember(self::CACHE_KEY, $ttl, fn () => $this->computePool());
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // الكاش اختياري.
        }
    }

    /** @return array<int, float> */
    protected function computePool(): array
    {
        $min = max(1, (int) config('collections.discover.min_visible_items'));
        $poolSize = max(1, (int) config('collections.discover.pool'));
        $w = (array) config('collections.discover.weights');
        $recentDays = max(1, (int) config('collections.discover.recent_days'));

        $rows = $this->access->listableQuery(null, $min)
            ->select('user_collections.id', 'user_collections.saves_count', 'user_collections.updated_at')
            ->orderByDesc('user_collections.saves_count')->orderByDesc('user_collections.updated_at')->orderByDesc('user_collections.id')
            ->limit($poolSize)->get();

        $counts = $this->access->visibleCounts($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $scores = [];

        foreach ($rows as $row) {
            $days = max(0.0, (float) $row->updated_at->diffInSeconds(now(), true) / 86400);
            $recency = max(0.0, 1 - $days / $recentDays);
            $scores[(int) $row->id] = round(
                (float) $w['saves'] * log(1 + (int) $row->saves_count)
                + (float) $w['recency'] * $recency
                + (float) $w['items'] * min($counts[(int) $row->id] ?? 0, 10) / 10,
                6
            );
        }

        arsort($scores);

        return $scores;
    }
}
