<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatMessageReport;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * بلاغات الرسائل (E21-E): تصنيف مغلق، لا بلاغ عن رسالتك، ولا عن رسالة لا تراها أو محذوفة، وبلاغ واحد لكل (رسالة، مُبلِّغ) بقيد UNIQUE.
 * المراجعة (reviewed|dismissed|actioned) بصلاحية chat.moderate ومدقَّقة. لا يعرض البلاغ إلا الرسالة المبلَّغ عنها (لا تاريخ محادثة خاصة).
 */
class ChatReportService
{
    public function __construct(protected ChatAccess $access, protected OperationalAuditService $audit) {}

    public function report(User $reporter, ChatMessage $message, string $category, ?string $details = null): ChatMessageReport
    {
        if (! in_array($category, config('chat.report_categories', []), true)) {
            throw new ChatException('invalid_category');
        }

        if ((int) $message->sender_id === (int) $reporter->getKey()) {
            throw new ChatException('own_message');
        }

        if (! $this->access->canRead($reporter, $message->thread)) {
            throw new ChatException('forbidden');
        }

        if (! $message->isNormal() || $message->sender_id === null) {
            throw new ChatException('not_reportable');
        }

        $details = $details === null ? null : mb_substr(trim($details), 0, (int) config('chat.report_details_max', 500));

        try {
            $report = new ChatMessageReport;
            $report->forceFill(['chat_message_id' => $message->getKey(), 'reporter_id' => $reporter->getKey(), 'category' => $category, 'details' => $details === '' ? null : $details, 'status' => ChatMessageReport::STATUS_PENDING])->save();

            return $report;
        } catch (UniqueConstraintViolationException) {
            throw new ChatException('already_reported');
        }
    }

    public function review(User $moderator, ChatMessageReport $report, string $status): ChatMessageReport
    {
        abort_unless($moderator->can('chat.moderate'), 403);

        if (! in_array($status, [ChatMessageReport::STATUS_REVIEWED, ChatMessageReport::STATUS_DISMISSED, ChatMessageReport::STATUS_ACTIONED], true)) {
            throw new ChatException('invalid_category');
        }

        if ($report->status === $status) {
            return $report;
        }

        $report->forceFill(['status' => $status, 'reviewed_by_id' => $moderator->getKey(), 'reviewed_at' => now()])->save();
        $this->audit->log('chat_report_reviewed', $report, ['status' => $status], $moderator);

        return $report;
    }
}
