<?php

namespace App\Services\Engagement;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;

/**
 * E29 - قواعد الأهلية المشتركة لكل التفاعلات. تقرأ الحالة **من القاعدة لحظة الفعل** (لا من نسخة مستخدم قديمة بالذاكرة):
 * حساب جُمِّد بعد بدء الجلسة لا يتفاعل. التفاعل = مستخدم موثَّق بريده وغير مجمَّد؛ ولا شيء منه يلمس الاقتصاد أو التقدّم.
 */
class EngagementGuard
{
    public function assertCanInteract(User $user): void
    {
        $row = User::query()->whereKey($user->id)->first(['id', 'email_verified_at', 'is_frozen']);

        if ($row === null || $row->email_verified_at === null) {
            throw new EngagementException('وثّق بريدك الإلكتروني أولًا لتتمكن من التفاعل.');
        }

        if ((bool) $row->is_frozen) {
            throw new EngagementException('حسابك مقيَّد مؤقتًا ولا يمكنه التفاعل الآن.');
        }
    }

    /** مالك العمل (user_id) لمنع التفاعل الذاتي: تضخيم الأرقام بنفس صاحب المحتوى. */
    public function ownerId(CreatorPuzzle $work): int
    {
        if ($work->relationLoaded('creator') && $work->creator !== null) {
            return (int) $work->creator->user_id;
        }

        return (int) CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
    }

    /** قفل صف العمل داخل معاملة: يُسلسل كتابات التفاعل على العمل الواحد ويقرأ is_visible الحيّة. */
    public function lockWork(int $workId): CreatorPuzzle
    {
        $locked = CreatorPuzzle::query()->whereKey($workId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
        }

        return $locked;
    }

    public function assertVisible(CreatorPuzzle $locked): void
    {
        if (! $locked->is_visible) {
            throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
        }
    }
}
