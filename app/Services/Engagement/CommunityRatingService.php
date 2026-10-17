<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorPuzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - تقييم أحجيات المجتمع (نجوم 1–5). **بلا سلطة اقتصادية**: لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات ولا أي أثر على التقدّم.
 *
 *  - الأهلية: مستخدم موثَّق غير مجمَّد، ليس صاحب العمل، وله **محاولة واحدة على الأقل** على العمل (لا يُشترط الحل: الصعبة تُقيَّم أيضًا).
 *  - الهدف: العمل الثابت CreatorPuzzle لا نسخة الأحجية القانونية: اعتماد نسخة جديدة لا يمسّ تاريخ التقييمات.
 *  - تقييم واحد لكل (مستخدم، عمل) بقيد فريد بالقاعدة؛ التعديل يغيّر الصف نفسه؛ الحذف يزيله.
 *  - المصدر الوحيد للحقيقة صفوف التقييم؛ ratings_count/ratings_sum على العمل مشتقّان، يُحدَّثان بمعاملة واحدة مع الصف تحت قفل صف العمل (تحديث ذرّي).
 *  - عمل مخفي/مؤرشف/لمبدع معلَّق: لا تقييم جديد ولا تعديل؛ التقييمات القديمة تبقى محفوظة ولا تُعرض (لا يُعرض المحتوى أصلًا). الحذف الذاتي مسموح.
 */
class CommunityRatingService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, rating: int, count: int, average: float|null} */
    public function set(User $user, CreatorPuzzle $work, int $rating): array
    {
        $this->guard->assertCanInteract($user);

        $min = (int) config('engagement.ratings.min', 1);
        $max = (int) config('engagement.ratings.max', 5);

        if ($rating < $min || $rating > $max) {
            throw new EngagementException("قيمة التقييم من {$min} إلى {$max}.");
        }

        return DB::transaction(function () use ($user, $work, $rating) {
            $locked = $this->guard->lockWork($work->id);
            $this->guard->assertVisible($locked);

            if ($this->guard->ownerId($locked) === $user->id) {
                throw new EngagementException('لا يمكنك تقييم أحجيتك.');
            }

            if (! CommunityPuzzleAttempt::query()->where('creator_puzzle_id', $locked->id)->where('user_id', $user->id)->exists()) {
                throw new EngagementException('جرّب الأحجية أولًا ليتاح لك تقييمها.');
            }

            $existing = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->lockForUpdate()->first();
            $changed = false;

            if ($existing === null) {
                $row = new CommunityPuzzleRating;
                $row->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $locked->id, 'rating' => $rating])->save();
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_count' => 1, 'ratings_sum' => $rating]);
                $changed = true;
            } elseif ((int) $existing->rating !== $rating) {
                $delta = $rating - (int) $existing->rating;
                $existing->forceFill(['rating' => $rating])->save();
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_sum' => $delta]);
                $changed = true;
            }

            return ['changed' => $changed] + $this->summary($locked->id, $rating);
        });
    }

    /** يحذف تقييم المستخدم نفسه. @return bool هل حُذف صف فعلًا (idempotent: لا شيء للحذف = false) */
    public function remove(User $user, CreatorPuzzle $work): bool
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $row = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->lockForUpdate()->first();

            if ($row === null) {
                return false;
            }

            $value = (int) $row->rating;
            $row->delete();
            EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_count' => -1, 'ratings_sum' => -$value]);

            return true;
        });
    }

    /** تقييم المستخدم الحالي للعمل أو null. */
    public function current(User $user, int $workId): ?int
    {
        $value = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->value('rating');

        return $value === null ? null : (int) $value;
    }

    /** @return array{rating: int, count: int, average: float|null} */
    protected function summary(int $workId, int $rating): array
    {
        $row = DB::table('creator_puzzles')->where('id', $workId)->first(['ratings_count', 'ratings_sum']);
        $count = (int) ($row->ratings_count ?? 0);

        return ['rating' => $rating, 'count' => $count, 'average' => $count > 0 ? round(((int) $row->ratings_sum) / $count, 1) : null];
    }
}
