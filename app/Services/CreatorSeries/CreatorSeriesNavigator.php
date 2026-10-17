<?php

namespace App\Services\CreatorSeries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - سياق السلسلة على صفحة أحجية مجتمع (اختياري: ?series=...). يعمل **فقط** عند وجود المعامل، فصفحة الأحجية العادية بلا أي استعلام إضافي.
 * يعيد الموضع والسابق والتالي من النسخة المنشورة للسلسلة الظاهرة حاليًا إن كانت الأحجية عنصرًا فيها، وإلا null (معامل مختلق/سلسلة مخفية = لا سياق ولا خطأ).
 * قراءة فقط: لا يكتب شيئًا ولا يمنح شيئًا.
 */
class CreatorSeriesNavigator
{
    public function __construct(protected CreatorSeriesVisibilityService $visibility) {}

    /** @return array{series:string, title:string, url:string, position:int, total:int, previous:?array{public_id:string,title:string}, next:?array{public_id:string,title:string}}|null */
    public function context(mixed $seriesPublicId, int $workId): ?array
    {
        if (! (bool) config('creator_series.enabled', true) || ! is_string($seriesPublicId) || ! Str::isUlid($seriesPublicId)) {
            return null;
        }

        $series = $this->visibility->visibleQuery()
            ->where('creator_series.public_id', Str::upper($seriesPublicId))
            ->first(['creator_series.id', 'creator_series.public_id', 'creator_series.published_revision_id', 'creator_series.published_title']);

        if ($series === null) {
            return null;
        }

        $rows = DB::table('creator_series_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $series->published_revision_id)
            ->orderBy('i.position')
            ->get(['w.id as work_id', 'w.public_id', 'w.published_title'])->values();

        $index = $rows->search(fn ($r) => (int) $r->work_id === $workId);

        if ($index === false) {
            return null;
        }

        $shape = fn ($r) => $r === null ? null : ['public_id' => (string) $r->public_id, 'title' => (string) $r->published_title];

        return [
            'series' => (string) $series->public_id,
            'title' => (string) $series->published_title,
            'url' => route('series.show', $series->public_id),
            'position' => $index + 1,
            'total' => $rows->count(),
            'previous' => $shape($rows->get($index - 1)),
            'next' => $shape($rows->get($index + 1)),
        ];
    }
}
