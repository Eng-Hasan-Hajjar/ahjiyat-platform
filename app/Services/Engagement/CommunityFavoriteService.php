<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CreatorPuzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - المفضلة (مفهوم واحد: «محفوظة»؛ لا إعجاب منفصل). **بلا سلطة اقتصادية** ولا أثر على التقدّم.
 *
 *  - خاصة: ما يحفظه مستخدم لا يراه غيره. الرقم المجمَّع (favorites_count) وحده علني.
 *  - فريدة لكل (مستخدم، عمل) بقيد القاعدة؛ تكرار الإضافة آمن (idempotent) ولا يضاعف العدّاد.
 *  - **لا مفضلة ذاتية** (قرار متحفظ): صاحب العمل لا يضخّم رقم عمله بنفسه.
 *  - إضافة جديدة تشترط عملًا ظاهرًا؛ الإزالة مسموحة دائمًا (حتى لو أُخفي العمل) كي ينظّف المستخدم قائمته.
 *  - صف المفضلة يبقى مع الإخفاء ويعود الظهور بالاستعادة طبيعيًا (انظر FavoritesPage: المخفي لا يُعرض ولا يُلعب).
 */
class CommunityFavoriteService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, count: int} */
    public function add(User $user, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $this->guard->assertVisible($locked);

            if ($this->guard->ownerId($locked) === $user->id) {
                throw new EngagementException('لا يمكنك حفظ أحجيتك في مفضلتك.');
            }

            if (CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->exists()) {
                return ['changed' => false, 'count' => $this->count($locked->id)];
            }

            $row = new CommunityPuzzleFavorite;
            $row->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $locked->id, 'created_at' => now()])->save();
            EngagementCounters::adjust('creator_puzzles', $locked->id, ['favorites_count' => 1]);

            return ['changed' => true, 'count' => $this->count($locked->id)];
        });
    }

    /** @return array{changed: bool, count: int} */
    public function remove(User $user, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $deleted = CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->delete();

            if ($deleted > 0) {
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['favorites_count' => -1]);
            }

            return ['changed' => $deleted > 0, 'count' => $this->count($locked->id)];
        });
    }

    public function isFavorite(User $user, int $workId): bool
    {
        return CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->exists();
    }

    protected function count(int $workId): int
    {
        return (int) DB::table('creator_puzzles')->where('id', $workId)->value('favorites_count');
    }
}
