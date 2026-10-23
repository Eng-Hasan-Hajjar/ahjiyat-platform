<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorPayout;
use App\Models\CreatorRevenueAllocation;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E39 - إشعارات مالية المبدع (E15، فئة social الاختيارية). تُستدعى **بعد** نجاح الـcommit ويعزل فشلها (try/catch + report): لا تُرجع حالة ولا تُفشل المالية.
 * لا مبالغ ولا مراجع صرف ولا ملاحظات داخلية في النص أبدًا. مفاتيح idempotency دلالية فلا إشعار مكرَّر. لا إشعار عند: طلب الدفعة، المراجعة، التعليق، التعديل الإداري.
 * الحساب المجمَّد لا يُشعَر (مثل بقية المنظومة).
 */
class CreatorFinanceNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function statementReady(CreatorRevenueAllocation $allocation): void
    {
        $this->send((int) $allocation->user_id, NotificationType::CreatorStatementReady, 'creator_statement:'.$allocation->creator_revenue_period_id.':'.$allocation->creator_profile_id, ['creator_revenue_allocation_id' => $allocation->id]);
    }

    public function payoutApproved(CreatorPayout $payout): void
    {
        $this->send((int) $payout->user_id, NotificationType::CreatorPayoutApproved, 'creator_payout:'.$payout->id.':approved', ['creator_payout_id' => $payout->id]);
    }

    public function payoutPaid(CreatorPayout $payout): void
    {
        $this->send((int) $payout->user_id, NotificationType::CreatorPayoutPaid, 'creator_payout:'.$payout->id.':paid', ['creator_payout_id' => $payout->id]);
    }

    public function payoutRejected(CreatorPayout $payout): void
    {
        $this->send((int) $payout->user_id, NotificationType::CreatorPayoutRejected, 'creator_payout:'.$payout->id.':rejected', ['creator_payout_id' => $payout->id]);
    }

    /** @param array<string, scalar> $refs */
    protected function send(int $userId, NotificationType $type, string $key, array $refs): void
    {
        if (! (bool) config('creator_monetization.notifications.enabled', true)) {
            return;
        }

        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return;
            }

            $this->dispatcher->dispatch($user, $type, [], $key, $refs, [], 'creator.earnings');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
