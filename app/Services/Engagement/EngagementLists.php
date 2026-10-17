<?php

namespace App\Services\Engagement;

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * E29 - قوائم المستخدم (المفضلة، عناصر المجموعة، خلاصة المتابَعين). كلها مرقَّمة بحدود ثابتة وباستعلام قراءة واحد + تحميل مسبق مجمَّع (لا N+1)،
 * وتعيد **كل** الأعمال بما فيها غير الظاهرة (is_visible=false) كي يعرض العرض مكانًا بديلًا «غير متاح حاليًا» دون تسريب عنوانها أو سبب إخفائها.
 * عدا الخلاصة: تعيد الظاهر فقط (ما يُنشر الآن)، وتعيد فحص CreatorPuzzle::visible() بكل قراءة.
 */
class EngagementLists
{
    protected const COLUMNS = [
        'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title',
        'creator_puzzles.published_category_id', 'creator_puzzles.published_difficulty', 'creator_puzzles.published_at', 'creator_puzzles.plays_count',
        'creator_puzzles.ratings_count', 'creator_puzzles.ratings_sum', 'creator_puzzles.favorites_count', 'creator_puzzles.is_visible',
    ];

    protected const WITH = ['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'];

    public function favorites(User $user, int $perPage): LengthAwarePaginator
    {
        return CreatorPuzzle::query()
            ->join('community_puzzle_favorites as f', 'f.creator_puzzle_id', '=', 'creator_puzzles.id')
            ->where('f.user_id', $user->id)
            ->select(array_merge(self::COLUMNS, ['f.id as favorite_id']))
            ->with(self::WITH)
            ->orderByDesc('f.id')
            ->paginate($perPage)->withQueryString();
    }

    public function collectionItems(UserCollection $collection, int $perPage): LengthAwarePaginator
    {
        return CreatorPuzzle::query()
            ->join('user_collection_items as i', 'i.creator_puzzle_id', '=', 'creator_puzzles.id')
            ->where('i.user_collection_id', $collection->id)
            ->select(array_merge(self::COLUMNS, ['i.id as item_id']))
            ->with(self::WITH)
            ->orderByDesc('i.id')
            ->paginate($perPage)->withQueryString();
    }

    /** أحدث منشورات المبدعين الذين يتابعهم المستخدم: ظاهر الآن فقط، الأحدث نشرًا أولًا ثم id كمفتاح ثانوي ثابت، بمؤشر (cursor) مستقر عند النشر الجديد. */
    public function following(User $user, int $perPage): CursorPaginator
    {
        return CreatorPuzzle::query()->visible()
            ->whereIn('creator_puzzles.creator_profile_id', fn ($q) => $q->select('creator_profile_id')->from('creator_follows')->where('user_id', $user->id))
            ->select(self::COLUMNS)
            ->with(self::WITH)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->cursorPaginate($perPage)->withQueryString();
    }
}
