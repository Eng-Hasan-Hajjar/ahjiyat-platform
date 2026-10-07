<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\OperationalAuditService;

/**
 * الإخفاء الإشرافي والاستعادة (E21-C5/D7/E): الإخفاء **لا يعدّل نص رسالة غيرك** ولا يحذفه (النص يبقى للمراجعة).
 * من يملك الإخفاء: (1) مالك/مشرف **الفريق الحاليان** داخل غرفة فريقهم، (2) صاحب chat.moderate بالغرفة العامة، (3) صاحب chat.moderate لرسالة **عليها بلاغ** بأي غرفة
 * (استثناء الإشراف: بلاغ مستخدم يكشف تلك الرسالة وحدها، لا تاريخ المحادثة). لا يصفّح أحد رسائل خاصة غير مبلَّغ عنها. السبب إلزامي، والأفعال مدقَّقة (لا تدقيق لرسالة عادية).
 */
class ChatModerationService
{
    public function __construct(protected ChatAccess $access, protected ChatBroadcaster $broadcaster, protected OperationalAuditService $audit) {}

    public function canModerate(User $actor, ChatMessage $message): bool
    {
        $thread = $message->thread;

        return match (true) {
            $this->access->accountBlocker($actor) !== null => false,
            $thread->isTeam() && $this->access->canModerateTeamThread($actor, $thread) => true,
            $thread->isGlobal() => $actor->can('chat.moderate'),
            default => $actor->can('chat.moderate') && $message->reports()->exists(),
        };
    }

    public function hide(User $actor, ChatMessage $message, string $reason): ChatMessage
    {
        $reason = $this->reason($reason);
        abort_unless($this->canModerate($actor, $message), 403);

        if ($message->isHidden() || $message->isDeleted()) {
            return $message;
        }

        $message->forceFill(['hidden_at' => now(), 'hidden_by_id' => $actor->getKey(), 'hidden_reason' => $reason])->save();
        $this->audit->log('chat_message_hidden', $message, ['thread_type' => $message->thread->type, 'reason' => $reason], $actor);
        $this->broadcaster->publish($message, updated: true);

        return $message;
    }

    public function restore(User $actor, ChatMessage $message, string $reason): ChatMessage
    {
        $reason = $this->reason($reason);
        abort_unless($this->canModerate($actor, $message), 403);

        if (! $message->isHidden()) {
            return $message;
        }

        $message->forceFill(['hidden_at' => null, 'hidden_by_id' => null, 'hidden_reason' => null])->save();
        $this->audit->log('chat_message_restored', $message, ['thread_type' => $message->thread->type, 'reason' => $reason], $actor);
        $this->broadcaster->publish($message, updated: true);

        return $message;
    }

    protected function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > (int) config('chat.moderation_reason_max', 200)) {
            throw new ChatException('reason_required');
        }

        return $reason;
    }
}
