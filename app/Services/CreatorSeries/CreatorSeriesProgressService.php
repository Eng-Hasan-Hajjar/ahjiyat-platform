<?php

namespace App\Services\CreatorSeries;

use App\Models\CommunityPuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * E30 - تقدّم المستخدم في سلسلة: **مشتقّ دائمًا** من community_puzzle_attempts (سجل لعب المجتمع نفسه) لا جدول تقدّم ولا كاش.
 * العنصر مكتمل ⇔ للمستخدم محاولة صحيحة على النسخة المنشورة الحالية للعمل (وهي نفس قاعدة «حللتها» في صفحة الأحجية CommunityPlayService::state)،
 * فحلّ الأحجية قبل وجود السلسلة أو من خارجها يُحتسب تلقائيًا، و«أكمل» يفتح أول عنصر غير محلول بالترتيب.
 * استعلام واحد محدود بعناصر السلسلة (≤ 25) مهما كان عددها. لا كتابة هنا أبدًا: لا جواهر ولا XP ولا مهام ولا سلسلة أيام ولا صدارة.
 */
class CreatorSeriesProgressService
{
    /**
     * @param  Collection<int, object>  $items  عناصر بالترتيب، لكل منها work_id (creator_puzzles.id) و public_id
     * @return array{done:int, total:int, percent:int, completed:bool, solved:array<int,bool>, next_public_id:?string}
     */
    public function forUser(?User $user, Collection $items): array
    {
        $total = $items->count();
        $solvedIds = [];

        if ($user !== null && $total > 0) {
            $solvedIds = CommunityPuzzleAttempt::query()
                ->join('creator_puzzles as w', 'w.id', '=', 'community_puzzle_attempts.creator_puzzle_id')
                ->whereColumn('community_puzzle_attempts.puzzle_id', 'w.published_puzzle_id')
                ->where('community_puzzle_attempts.user_id', $user->id)
                ->where('community_puzzle_attempts.is_correct', true)
                ->whereIn('community_puzzle_attempts.creator_puzzle_id', $items->pluck('work_id')->all())
                ->distinct()->pluck('community_puzzle_attempts.creator_puzzle_id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $solved = [];
        $next = null;

        foreach ($items as $item) {
            $isSolved = in_array((int) $item->work_id, $solvedIds, true);
            $solved[(int) $item->work_id] = $isSolved;

            if (! $isSolved && $next === null) {
                $next = (string) $item->public_id;
            }
        }

        $done = count(array_filter($solved));

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            'completed' => $total > 0 && $done === $total,
            'solved' => $solved,
            'next_public_id' => $next,
        ];
    }
}
