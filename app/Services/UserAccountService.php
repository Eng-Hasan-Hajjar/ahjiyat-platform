<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * نقطة الحقيقة الوحيدة لتجميد/رفع تجميد حساب - تتعامل مع الحالة +
 * الحماية (AuthorizationSafetyService) + سجل العمليات (OperationalAuditService)
 * معاً. لا Controller/Filament Action يعدّل is_frozen مباشرة.
 */
class UserAccountService
{
    public function __construct(
        protected AuthorizationSafetyService $safety,
        protected OperationalAuditService $audit,
    ) {}

    public function freeze(User $target, User $actor, string $reason): void
    {
        // لا يمكن تجميد Super Admin إلا من طرف Super Admin آخر، ولا يمكن
        // تجميد آخر Super Admin بالنظام إطلاقاً (حساب مجمَّد = معطَّل
        // فعلياً لأي غرض عملي بلوحة الإدارة).
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        if ($this->safety->isLastSuperAdmin($target)) {
            abort(403, 'لا يمكن تجميد هذا الحساب لأنه آخر مدير أعلى بالمنصة.');
        }

        if ($target->is($actor)) {
            abort(403, 'لا يمكنك تجميد حسابك الخاص.');
        }

        DB::transaction(function () use ($target, $actor, $reason) {
            // forceFill لازم هون - is_frozen/frozen_reason/frozen_at/frozen_by
            // مو موجودين بـ$fillable على User (نفس درس AdminUserSeeder) -
            // update() العادية كانت ستُسقط هذه الحقول بصمت بلا أي خطأ.
            $target->forceFill([
                'is_frozen' => true,
                'frozen_reason' => $reason,
                'frozen_at' => now(),
                'frozen_by' => $actor->id,
            ])->save();

            $this->audit->log('user_frozen', $target, ['reason' => $reason], $actor);
        });
    }

    public function unfreeze(User $target, User $actor): void
    {
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        $previousReason = $target->frozen_reason;

        DB::transaction(function () use ($target, $actor, $previousReason) {
            $target->forceFill([
                'is_frozen' => false,
                'frozen_reason' => null,
                'frozen_at' => null,
                'frozen_by' => null,
            ])->save();

            $this->audit->log('user_unfrozen', $target, ['previous_reason' => $previousReason], $actor);
        });
    }
}