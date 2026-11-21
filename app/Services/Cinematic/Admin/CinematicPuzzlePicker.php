<?php

namespace App\Services\Cinematic\Admin;

use App\Models\Puzzle;
use App\Services\Cinematic\CinematicPuzzleBridge;
use App\Support\Search\TextSearch;

/**
 * بحث محدّد الأحجية في محرّر مشهد الأحجية: على الخادم ضمن **كل** الأحجيات الصالحة للربط قبل تحديد العدد
 * (كان: أول 300 حسب العنوان ثم فلترة في PHP، فتختفي أحجية قديمة/متأخرة أبجديًا). الصلاحية يفحصها المستدعي.
 * لا إجابة ولا تلميح ولا هاش ولا مكافأة في المخرجات: العنوان والصعوبة والرقم والتصنيف فقط.
 */
final class CinematicPuzzlePicker
{
    public const PAGE_SIZE = 10;

    private const DIFFICULTY = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function __construct(protected CinematicPuzzleBridge $bridge) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    public function search(string $term = '', int $offset = 0, ?Puzzle $pinned = null): array
    {
        $offset = max(0, min($offset, 100000));
        $tokens = TextSearch::tokens($term);

        $base = TextSearch::contains($this->bridge->eligibleQuery(), 'title', $tokens);

        if ($pinned !== null) {
            $base->where('puzzles.id', '!=', $pinned->id);
        }

        $rest = (clone $base)->count();
        $rows = (clone $base)->with('category:id,name')->orderBy('title')->orderBy('id')->offset($offset)->limit(self::PAGE_SIZE)->get(['id', 'title', 'difficulty', 'puzzle_category_id'])->all();

        $showPinned = $pinned !== null && $offset === 0
            && ($tokens === [] || TextSearch::contains(Puzzle::query()->where('id', $pinned->id), 'title', $tokens)->exists());

        $display = $showPinned ? [$pinned, ...$rows] : $rows;
        $items = array_map(fn (Puzzle $p) => $this->describe($p, $pinned !== null && $p->id === $pinned->id), $display);
        $consumed = $offset + count($rows);

        return ['items' => $items, 'total' => $rest + ($showPinned ? 1 : 0), 'next' => $consumed < $rest ? $consumed : null];
    }

    /** @return array{id: string, name: string, parts: list<string>, dist: list<string>, retired: bool, current: bool, note: string|null} */
    public function describe(Puzzle $puzzle, bool $current = false): array
    {
        $reason = $current ? $this->bridge->ineligibleReason($puzzle) : null;

        return [
            'id' => (string) $puzzle->id,
            'name' => (string) $puzzle->title,
            'parts' => array_values(array_filter([self::DIFFICULTY[$puzzle->difficulty] ?? null, '#'.$puzzle->id, $puzzle->category?->name])),
            'dist' => [],
            // الأحجية المربوطة سابقًا والتي لم تعد صالحة (عُطّلت مثلًا) تبقى ظاهرة كاختيار حالي مع السبب، ولا تُعرض كخيار جديد.
            'retired' => $reason !== null,
            'current' => $current,
            'note' => $reason,
        ];
    }
}
