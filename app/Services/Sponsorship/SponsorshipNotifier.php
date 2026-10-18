<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E30 - إشعارات الرعاية (E15). تُستدعى **بعد** نجاح التغيير المالي (بعد الـcommit) وفشلها لا يُرجع أي حالة (try/catch + report). مفاتيح idempotency دلالية.
 * للداعم المرتبط بحساب فقط؛ **لا مبالغ ولا مراجع ولا ملاحظات خاصة** بنصوص الإشعارات أو معاملاتها أبدًا.
 */
class SponsorshipNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function supportConfirmed(SponsorshipContribution $contribution): void
    {
        $this->send($contribution->supporter, NotificationType::SupportConfirmed, [], 'support_confirmed:'.$contribution->id, $this->actionParams($contribution->supporter));
    }

    public function tierChanged(SupporterProfile $supporter, ?SupporterTier $from, ?SupporterTier $to, int $contributionId): void
    {
        if (($from?->id) === ($to?->id)) {
            return;
        }

        $up = $to !== null && ($from === null || (int) $to->minimum_accounting_amount_minor > (int) $from->minimum_accounting_amount_minor);

        $this->send($supporter, NotificationType::SupporterTierChanged, ['tier' => $to?->name, 'up' => $up], 'supporter_tier:'.$supporter->id.':'.($to?->id ?? 0).':'.$contributionId, $this->actionParams($supporter));
    }

    public function programStarted(SupporterProfile $supporter, SponsorshipProgram $program): void
    {
        $this->send($supporter, NotificationType::SponsorshipProgramStarted, ['title' => $program->title], 'sponsorship_program_started:'.$program->id.':'.$supporter->id);
    }

    // ---- E32: إشعارات طلب الدعم/الدفع (لصاحب الطلب مباشرة، لا عبر ملف الداعم: قد لا يوجد ملف قبل التحقق). بلا مبالغ ولا مراجع تحويل بالنص. ----

    public function paymentReceived(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRequestReceived, [], 'support_request_received:'.$payment->id);
    }

    public function paymentActionRequired(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportActionRequired, ['note' => (string) $payment->user_message], 'support_action_required:'.$payment->id.':'.($payment->clarification_requested_at?->timestamp ?? 0));
    }

    public function paymentRejected(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRejected, ['note' => (string) $payment->user_message], 'support_rejected:'.$payment->id);
    }

    public function paymentRefunded(SponsorshipPayment $payment): void
    {
        $this->sendToUser($payment, NotificationType::SupportRefunded, [], 'support_refunded:'.$payment->id);
    }

    protected function sendToUser(SponsorshipPayment $payment, NotificationType $type, array $params, string $key): void
    {
        if (! (bool) config('sponsorship.notifications.enabled', true) || ! (bool) config('payments.notifications.enabled', true)) {
            return;
        }

        try {
            $user = $payment->relationLoaded('user') ? $payment->user : User::query()->find($payment->user_id);

            if ($user === null || $user->is_frozen) {
                return;
            }

            $this->dispatcher->dispatch($user, $type, $params, $key, ['sponsorship_payment_id' => $payment->id], ['payment' => (string) $payment->public_id], 'support.payments.show');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, string> */
    protected function actionParams(SupporterProfile $supporter): array
    {
        return $supporter->isPublic() ? ['supporter' => (string) $supporter->slug] : [];
    }

    protected function send(SupporterProfile $supporter, NotificationType $type, array $params, string $key, array $actionParams = []): void
    {
        if (! (bool) config('sponsorship.notifications.enabled', true)) {
            return;
        }

        try {
            $user = $supporter->user;

            if ($user === null || $user->is_frozen) {
                return;
            }

            $route = $actionParams !== [] ? 'supporters.show' : null;
            $this->dispatcher->dispatch($user, $type, $params, $key, ['supporter_profile_id' => $supporter->id], $actionParams, $route);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
