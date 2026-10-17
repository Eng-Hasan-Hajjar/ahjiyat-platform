<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorSeries;
use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * E30 - سلاسل المجتمع بالاكتشاف: «سلاسل من المجتمع» (أحدث الظاهرة، بسقف سلاسل لكل مبدع لتنوّع المبدعين) و«سلاسل جديدة ممّن تتابعهم» (إشارة متابعة E29، للمسجَّل فقط).
 *
 *  - **الظهور حيّ دائمًا** (CreatorSeriesVisibilityService): الكاش يحمل **معرّفات فقط** (لا أسماء ولا عناوين ولا بيانات لاعبين)، وكل عرض يعيد فحص الظهور باستعلام واحد،
 *    فإخفاء أحجية أو تعليق مبدع يُخرج السلسلة من الاكتشاف فورًا ولو بقيت المعرّفات بالكاش. الكاش ليس سلطة ظهور أبدًا.
 *  - عدد الاستعلامات ثابت لا يتبع حجم البيانات: معرّفات (1، أو 0 من الكاش) + بطاقات (1) + للمسجَّل: المتابَعون (1).
 *  - عرض فقط: لا مكافآت ولا رعاية ولا ترتيب مدفوع ولا كتابة. فشل هذا القسم لا يكسر مركز الاكتشاف (DiscoveryService يعزله).
 */
class CreatorSeriesDiscovery
{
    public function __construct(protected CreatorSeriesVisibilityService $visibility) {}

    /**
     * عزل الفشل هنا لا بـDiscoveryService: حارس E27 (S9) يمنع أي استدعاء تسجيل بشيفرة البحث والاكتشاف كي لا يصل مصطلح بحث لسجل أبدًا؛ وهذا الملف خارج ذلك النطاق ولا يرى مصطلحًا.
     *
     * @return array{new: list<DiscoverCard>, following: list<DiscoverCard>}
     */
    public function sections(?User $viewer): array
    {
        try {
            return $this->build($viewer);
        } catch (\Throwable $e) {
            report($e);

            return ['new' => [], 'following' => []];
        }
    }

    /** @return array{new: list<DiscoverCard>, following: list<DiscoverCard>} */
    protected function build(?User $viewer): array
    {
        if (! (bool) config('creator_series.enabled', true)) {
            return ['new' => [], 'following' => []];
        }

        $limit = max(1, (int) config('creator_series.discovery.section_limit', 6));
        $cap = max(1, (int) config('creator_series.discovery.per_creator_cap', 2));
        $following = collect();

        if ($viewer !== null) {
            $following = $this->capPerCreator(
                $this->cards()
                    ->whereIn('creator_series.creator_profile_id', fn ($q) => $q->select('creator_profile_id')->from('creator_follows')->where('user_id', $viewer->id))
                    ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                    ->limit($limit * $cap + $limit)->get(),
                $cap,
                $limit,
            );
        }

        $followingIds = $following->pluck('id')->all();
        $ids = $this->newestIds();
        $new = $ids === [] ? collect() : $this->capPerCreator(
            $this->cards()->whereIn('creator_series.id', $ids)->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')->get()
                ->reject(fn ($s) => in_array($s->id, $followingIds, true))->values(),
            $cap,
            $limit,
        );

        return [
            'new' => $new->map(fn ($s) => $this->card($s, null))->values()->all(),
            'following' => $following->map(fn ($s) => $this->card($s, 'من مبدع تتابعه'))->values()->all(),
        ];
    }

    /** معرّفات أحدث الظاهرة (كاش قصير). تُعاد المقارنة بالظهور الحي في الاستعلام التالي. @return list<int> */
    protected function newestIds(): array
    {
        return CachedSnapshot::remember('discover.series.newest', (int) config('creator_series.discovery.cache_ttl', 120), function () {
            return $this->visibility->visibleQuery()
                ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                ->limit(max(1, (int) config('creator_series.discovery.pool', 30)))->pluck('creator_series.id')->map(fn ($id) => (int) $id)->all();
        });
    }

    /** استعلام بطاقات ظاهرة بحقول لقطة النشر + اسم المبدع (ضمّان ثابتان، لا N+1). */
    protected function cards(): Builder
    {
        return $this->visibility->visibleQuery()
            ->join('creator_profiles as dcp', 'dcp.id', '=', 'creator_series.creator_profile_id')
            ->join('users as dcu', 'dcu.id', '=', 'dcp.user_id')
            ->select('creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title', 'creator_series.published_items_count', 'creator_series.published_at', 'dcu.name as creator_name');
    }

    protected function capPerCreator(Collection $series, int $cap, int $limit): Collection
    {
        $perCreator = [];

        return $series->filter(function ($s) use (&$perCreator, $cap) {
            $perCreator[$s->creator_profile_id] = ($perCreator[$s->creator_profile_id] ?? 0) + 1;

            return $perCreator[$s->creator_profile_id] <= $cap;
        })->take($limit)->values();
    }

    protected function card(CreatorSeries $series, ?string $reason): DiscoverCard
    {
        return new DiscoverCard(
            (string) $series->published_title,
            route('series.show', $series->public_id),
            'grid',
            (int) $series->published_items_count.' أحجية · بقلم '.$series->getAttribute('creator_name'),
            'سلسلة من المجتمع',
            'primary',
            $reason,
        );
    }
}
