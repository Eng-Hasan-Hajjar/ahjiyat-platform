<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicStory;
use App\Models\User;
use App\Services\CreatorPremium\CreatorPremiumAccess;

/**
 * CIN-1 - سياسة الوصول الوحيدة للسلاسل السينمائية.
 *
 *  - العلم مغلق ⇒ لا وصول لأحد (حتى الطاقم) عبر المسارات العامة.
 *  - القصة المجانية: لأي مستخدم مسجَّل دخوله. القصة المميزة: الملكية تُقرأ من CreatorPremiumAccess::ownsSeries (E43 هي السلطة الوحيدة؛
 *    لا نكتب ولا نمنح ولا نعدّل أي وصول من هنا). المعاينة الإشرافية (صلاحية cinematic-stories.preview) معاينة فقط: لا تقدّم محفوظ ولا ملكية.
 *  - الوصول للنسخة: المنشورة الحالية؛ أو نسخة «مستبدلة» لمن لديه تقدّم عليها (استمرارية)؛ والمسحوبة (unpublished) لا يصلها إلا الطاقم.
 */
class CinematicAccess
{
    public const PREVIEW_PERMISSION = 'cinematic-stories.preview';

    public function __construct(protected CreatorPremiumAccess $premium) {}

    public function enabled(): bool
    {
        return (bool) config('cinematic_stories.enabled');
    }

    public function canPreview(?User $user): bool
    {
        return $user !== null && $user->can(self::PREVIEW_PERMISSION);
    }

    public function liveRevision(CinematicStory $story): ?CinematicRevision
    {
        return CinematicRevision::query()->where('story_id', $story->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->first();
    }

    /**
     * @return array{allowed: bool, reason: string}
     */
    public function decide(?User $user, CinematicStory $story): array
    {
        if (! $this->enabled()) {
            return ['allowed' => false, 'reason' => 'disabled'];
        }

        if ($story->archived_at !== null || $this->liveRevision($story) === null) {
            return ['allowed' => false, 'reason' => 'unavailable'];
        }

        if ($user === null) {
            return ['allowed' => false, 'reason' => 'login'];
        }

        if (! $story->isPremium()) {
            return ['allowed' => true, 'reason' => 'free'];
        }

        $series = $story->series;

        if ($series === null) {
            return ['allowed' => false, 'reason' => 'unavailable'];
        }

        return $this->premium->ownsSeries($user, $series)
            ? ['allowed' => true, 'reason' => 'owner']
            : ['allowed' => false, 'reason' => 'premium_locked'];
    }

    /** هل يجوز للمستخدم قراءة محتوى هذه النسخة (وسائط/مشاهد) الآن؟ (يفترض أن decide() سمح للقصة). */
    public function canReadRevision(?User $user, CinematicRevision $revision): bool
    {
        if ($user === null) {
            return false;
        }

        if ($revision->status === CinematicRevision::STATUS_PUBLISHED) {
            return true;
        }

        if ($revision->status === CinematicRevision::STATUS_SUPERSEDED) {
            return CinematicProgress::query()->where('user_id', $user->id)->where('revision_id', $revision->id)->exists();
        }

        return false;
    }
}
