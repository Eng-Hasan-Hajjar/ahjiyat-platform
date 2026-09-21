<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccountService
{
    public function __construct(
        protected AuthorizationSafetyService $safety,
        protected OperationalAuditService $audit,
    ) {}

    public function freeze(User $target, User $actor, string $reason): void
    {
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        if ($this->safety->isLastSuperAdmin($target)) {
            abort(403, 'لا يمكن تجميد هذا الحساب لأنه آخر مدير أعلى بالمنصة.');
        }

        if ($target->is($actor)) {
            abort(403, 'لا يمكنك تجميد حسابك الخاص.');
        }

        DB::transaction(function () use ($target, $actor, $reason) {
            $target->update([
                'is_frozen' => true,
                'frozen_reason' => $reason,
                'frozen_at' => now(),
                'frozen_by' => $actor->id,
            ]);

            $this->audit->log('user_frozen', $target, ['reason' => $reason], $actor);
        });
    }

    public function unfreeze(User $target, User $actor): void
    {
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        $previousReason = $target->frozen_reason;

        DB::transaction(function () use ($target, $actor, $previousReason) {
            $target->update([
                'is_frozen' => false,
                'frozen_reason' => null,
                'frozen_at' => null,
                'frozen_by' => null,
            ]);

            $this->audit->log('user_unfrozen', $target, ['previous_reason' => $previousReason], $actor);
        });
    }
}