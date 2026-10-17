<?php

namespace App\Services\Engagement;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - متابعة المبدعين. **بلا سلطة اقتصادية** ولا ميزة مدفوعة.
 *
 *  - يتابع المستخدم **مبدعًا معتمَدًا** حسابه غير مجمَّد فقط (تمامًا كشرط ظهور صفحته العامة)؛ لا متابعة ذاتية؛ سقف لعدد ما يتابعه (config).
 *  - متابعة واحدة لكل (مستخدم، مبدع) بقيد القاعدة؛ التكرار آمن ولا يضاعف followers_count.
 *  - الخصوصية: قائمة ما يتابعه المستخدم خاصة؛ هوية المتابعين لا تُعرض لأحد. العدد المجمَّع وحده علني.
 *  - تعليق المبدع/تجميده لاحقًا: صفوف المتابعة تبقى، لكنه غير قابل للمتابعة علنًا ومنشوراته تختفي بقاعدة الظهور E28. إلغاء المتابعة مسموح دائمًا.
 */
class CreatorFollowService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, followers: int} */
    public function follow(User $user, CreatorProfile $creator): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $creator) {
            $locked = CreatorProfile::query()->whereKey($creator->id)->lockForUpdate()->first();
            $creatorFrozen = $locked !== null && (bool) User::query()->whereKey($locked->user_id)->value('is_frozen');

            if ($locked === null || ! $locked->isApproved() || $creatorFrozen) {
                throw new EngagementException('هذا المبدع غير متاح للمتابعة حاليًا.');
            }

            if ((int) $locked->user_id === $user->id) {
                throw new EngagementException('لا يمكنك متابعة نفسك.');
            }

            if (CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $locked->id)->exists()) {
                return ['changed' => false, 'followers' => $this->followers($locked->id)];
            }

            if (CreatorFollow::query()->where('user_id', $user->id)->count() >= (int) config('engagement.follows.max_per_user', 500)) {
                throw new EngagementException('بلغت الحد الأقصى لعدد المبدعين الذين يمكنك متابعتهم.');
            }

            $row = new CreatorFollow;
            $row->forceFill(['user_id' => $user->id, 'creator_profile_id' => $locked->id, 'created_at' => now()])->save();
            EngagementCounters::adjust('creator_profiles', $locked->id, ['followers_count' => 1]);

            return ['changed' => true, 'followers' => $this->followers($locked->id)];
        });
    }

    /** @return array{changed: bool, followers: int} */
    public function unfollow(User $user, CreatorProfile $creator): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $creator) {
            $locked = CreatorProfile::query()->whereKey($creator->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['changed' => false, 'followers' => 0];
            }

            $deleted = CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $locked->id)->delete();

            if ($deleted > 0) {
                EngagementCounters::adjust('creator_profiles', $locked->id, ['followers_count' => -1]);
            }

            return ['changed' => $deleted > 0, 'followers' => $this->followers($locked->id)];
        });
    }

    public function isFollowing(User $user, int $creatorProfileId): bool
    {
        return CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $creatorProfileId)->exists();
    }

    protected function followers(int $creatorProfileId): int
    {
        return (int) DB::table('creator_profiles')->where('id', $creatorProfileId)->value('followers_count');
    }
}
