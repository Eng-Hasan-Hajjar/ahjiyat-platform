<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\User;

/**
 * E28 - مصدر حقيقة واحد لأهلية الاستوديو: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد. ليست صلاحية إدارة: المبدع لا يحتاج admin.access.
 * كل بوابة (ميدلوير، خدمات الكتابة) تمرّ من هنا، فلا تتباعد القواعد.
 */
class CreatorEligibility
{
    public function profileFor(?User $user): ?CreatorProfile
    {
        return $user?->creatorProfile;
    }

    /** سبب رفض الاستوديو بالعربية، أو null إن كان المستخدم مؤهَّلًا. */
    public function studioDenial(?User $user): ?string
    {
        if ($user === null) {
            return 'سجّل الدخول أولًا.';
        }

        if (! $user->hasVerifiedEmail()) {
            return 'وثّق بريدك الإلكتروني أولًا.';
        }

        if ($user->is_frozen) {
            return 'تم تقييد حسابك مؤقتًا.';
        }

        $profile = $this->profileFor($user);

        return match (true) {
            $profile === null => 'قدّم طلب الانضمام للمبدعين أولًا.',
            $profile->isPending() => 'طلبك قيد المراجعة.',
            $profile->isRejected() => 'لم يُقبل طلبك السابق. يمكنك إعادة التقديم.',
            $profile->isSuspended() => 'تم تعليق صلاحيات المبدع لحسابك.',
            default => null,
        };
    }

    public function canUseStudio(?User $user): bool
    {
        return $this->studioDenial($user) === null;
    }

    /** يُعيد ملف المبدع المعتمَد أو يرمي بسبب الرفض (للخدمات: لا كتابة بدونه). */
    public function studioProfile(User $user): CreatorProfile
    {
        $reason = $this->studioDenial($user);

        if ($reason !== null) {
            throw new CreatorException($reason);
        }

        return $this->profileFor($user);
    }

    /** سبب عدم أهلية التقديم كمبدع، أو null. المرفوض يعيد التقديم؛ الباقي (قيد المراجعة/معتمَد/معلَّق) لا. */
    public function applyDenial(?User $user): ?string
    {
        if ($user === null) {
            return 'سجّل الدخول أولًا.';
        }

        if (! $user->hasVerifiedEmail()) {
            return 'وثّق بريدك الإلكتروني أولًا.';
        }

        if ($user->is_frozen) {
            return 'تم تقييد حسابك مؤقتًا.';
        }

        $profile = $this->profileFor($user);

        return match (true) {
            $profile === null, $profile->isRejected() => null,
            $profile->isPending() => 'لديك طلب قيد المراجعة بالفعل.',
            $profile->isApproved() => 'أنت مبدع معتمَد بالفعل.',
            default => 'تم تعليق صلاحيات المبدع لحسابك؛ لا يمكن تقديم طلب جديد.',
        };
    }
}
