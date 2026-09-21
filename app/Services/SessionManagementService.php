<?php

namespace App\Services;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SessionManagementService
{
    public function __construct(
        protected AuthorizationSafetyService $safety,
        protected OperationalAuditService $audit,
    ) {}

    public function revoke(Session $session, User $actor): void
    {
        $target = $session->user;

        if ($target) {
            $this->safety->assertCanModifyUserAuthorization($actor, $target);
        }

        if ($session->id === session()->getId()) {
            abort(403, 'لا يمكن إنهاء جلستك الحالية من هنا - استخدم تسجيل الخروج.');
        }

        $session->delete();

        $this->audit->log('session_revoked', $target, [
            'ip_address' => $session->ip_address,
        ], $actor);
    }

    public function revokeAllForUser(User $target, User $actor, bool $keepCurrentIfSelf = true): int
    {
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        $query = Session::query()->where('user_id', $target->id);

        if ($keepCurrentIfSelf && $target->is($actor)) {
            $query->where('id', '!=', session()->getId());
        }

        $count = $query->count();
        $query->delete();

        $this->audit->log('all_sessions_revoked', $target, ['count' => $count], $actor);

        return $count;
    }
}