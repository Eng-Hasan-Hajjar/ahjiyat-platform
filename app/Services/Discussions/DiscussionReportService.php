<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\User;
use App\Services\Engagement\EngagementGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E36 - الإبلاغ عن تعليق. **سبب من قائمة مغلقة فقط** (DiscussionCommentReport::REASONS، بلا نص حر): لا يُحقن محتوى مسيء عبر البلاغ.
 *
 *  - المُبلِّغ: موثَّق غير مجمَّد. يبلّغ فقط عن تعليق **ظاهر له** (موضوع ظاهر، بلا حظر، حيّ). لا بلاغ على تعليقك. مالك المحتوى يبلّغ كأي مستخدم (لا يملك إخفاءً).
 *  - بلاغ **مفتوح واحد** لكل (تعليق، مبلِّغ) بقيد قاعدة بيانات (open_marker). بعد إغلاقه يمكن بلاغ جديد.
 *  - **لقطة النص**: body_snapshot = النص لحظة البلاغ، للإشراف فقط، يبقى مهما عدّل صاحب التعليق أو حذف لاحقًا (لا نسخة تاريخ عامة).
 *  - على نقاش مقفل يبقى الإبلاغ متاحًا (آلية أمان). لا أثر على الاقتصاد ولا إشعار للمالك ولا للمبلِّغ (هوية المبلِّغ للإشراف وحده).
 */
class DiscussionReportService
{
    public function __construct(protected EngagementGuard $guard, protected DiscussionThreadService $threads) {}

    public function report(User $reporter, string $commentPublicId, string $reason): DiscussionCommentReport
    {
        if (! (bool) config('discussions.enabled', true)) {
            throw new DiscussionException('النقاشات متوقفة مؤقتًا.');
        }

        $this->guard->assertCanInteract($reporter);

        if (! array_key_exists($reason, DiscussionCommentReport::REASONS)) {
            throw new DiscussionException('سبب البلاغ غير صالح.');
        }

        $context = $this->threads->contextFor($commentPublicId, $reporter) ?? throw new ModelNotFoundException;
        $comment = $context->comment;

        if (! $comment->isLive()) {
            throw new DiscussionException('لا يمكن الإبلاغ عن هذا التعليق.');
        }

        if ((int) $comment->user_id === (int) $reporter->id) {
            throw new DiscussionException('لا يمكنك الإبلاغ عن تعليقك.');
        }

        try {
            return DB::transaction(function () use ($comment, $reporter, $reason) {
                $locked = DiscussionComment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isLive()) {
                    throw new DiscussionException('لا يمكن الإبلاغ عن هذا التعليق.');
                }

                if (DiscussionCommentReport::query()->where('discussion_comment_id', $locked->id)->where('reporter_id', $reporter->id)->where('status', DiscussionCommentReport::STATUS_OPEN)->exists()) {
                    throw new DiscussionException('لديك بلاغ مفتوح على هذا التعليق بالفعل.');
                }

                $report = new DiscussionCommentReport;
                $report->forceFill([
                    'discussion_comment_id' => $locked->id,
                    'reporter_id' => $reporter->id,
                    'reason' => $reason,
                    'status' => DiscussionCommentReport::STATUS_OPEN,
                    'open_marker' => 1,
                    'body_snapshot' => (string) $locked->body,
                ])->save();

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DiscussionException('لديك بلاغ مفتوح على هذا التعليق بالفعل.');
        }
    }

    /** @param list<int> $commentIds @return array<int, true> التعليقات التي لدى المستخدم بلاغ مفتوح عليها (دفعة واحدة لعرض «تم الإبلاغ»). */
    public function openFor(?User $user, array $commentIds): array
    {
        if ($user === null || $commentIds === []) {
            return [];
        }

        return array_fill_keys(
            DiscussionCommentReport::query()->where('reporter_id', $user->id)->where('status', DiscussionCommentReport::STATUS_OPEN)->whereIn('discussion_comment_id', $commentIds)->pluck('discussion_comment_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
    }
}
