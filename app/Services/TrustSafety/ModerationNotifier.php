<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E38 - إشعارات الثقة والسلامة (E15، فئة security الإلزامية) بعد نجاح الـcommit.
 *  - moderation_notice: للمستخدم المعني بإجراء ظاهر له (تنبيه/إخفاء/تعليق/تجميد). النص ثابت: لا اسم مشرف، لا مبلِّغ، لا سبب داخلي.
 *  - moderation_appeal_decided: لصاحب الاعتراض عند القرار.
 *  - **لا إشعار للمبلِّغين** (قرار موثَّق: لا قناة مضايقة ولا كشف نتيجة تحقيق).
 * كل فشل يُلتقَط ويُسجَّل فقط: لا يُرجع إجراءً ولا قرارًا أبدًا (عزل الفشل).
 */
class ModerationNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function notice(ModerationAction $action): void
    {
        try {
            if (! $action->user_visible || $action->target_user_id === null) {
                return;
            }

            $user = User::query()->find($action->target_user_id);

            if ($user !== null) {
                $this->dispatcher->dispatch($user, NotificationType::ModerationNotice, [], 'moderation_notice:'.$action->public_id);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function appealDecided(ModerationAppeal $appeal): void
    {
        try {
            if ($appeal->appellant_id === null) {
                return;
            }

            $user = User::query()->find($appeal->appellant_id);

            if ($user !== null) {
                $this->dispatcher->dispatch($user, NotificationType::ModerationAppealDecided, [], 'moderation_appeal:'.$appeal->public_id.':'.$appeal->status);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
