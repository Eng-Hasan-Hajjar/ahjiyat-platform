<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
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
