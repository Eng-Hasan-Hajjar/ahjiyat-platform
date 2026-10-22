<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E36 - إشعارات النقاش (E15). **ثلاثة أنواع فقط**: comment_reply_received (لكاتب الجذر)، new_comment_on_your_content (لمالك المحتوى)، comment_hidden (لكاتب التعليق المخفي).
 * لا إشعار على الإعجاب ولا البلاغ ولا القفل. لا إشعار ذاتي. نص الإشعار لا يحمل اسم المعلِّق ولا نص التعليق (لا قناة مضايقة).
 *
 * منع الإغراق (Idempotency دلالي بمفتاح نافذة زمنية): إشعار الرد واحد كحدّ أقصى لكل **جذر** في نافذة (reply_window_minutes)، وإشعار المالك واحد كحدّ أقصى لكل **نقاش**
 * في نافذة (owner_window_minutes) فلا يتلقى 100 إشعار من نقاش نشط. من يستلم إشعار الرد لا يستلم إشعار المالك نفسه عن التعليق نفسه (لا ازدواج).
 * تُستدعى بعد نجاح الـcommit وكل فشل هنا يُلتقَط ويُسجَّل فقط: لا يُرجع التعليق ولا الحالة أبدًا (عزل الفشل).
 */
class DiscussionNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function created(DiscussionComment $comment, ?DiscussionComment $root, DiscussionSubject $subject): void
    {
        try {
            $notifiedId = null;

            if ($root !== null && $root->user_id !== null && (int) $root->user_id !== (int) $comment->user_id) {
                $window = max(1, (int) config('discussions.notifications.reply_window_minutes')) * 60;
                $recipient = User::query()->find($root->user_id);

                if ($recipient !== null) {
                    $this->dispatcher->dispatch(
                        $recipient, NotificationType::CommentReplyReceived, ['title' => $subject->title],
                        'comment_reply:'.$root->id.':'.intdiv(now()->timestamp, $window),
                        [], ['comment' => (string) $comment->public_id], 'discussions.permalink',
                    );
                    $notifiedId = (int) $recipient->id;
                }
            }

            if ($subject->ownerId !== (int) $comment->user_id && $subject->ownerId !== $notifiedId) {
                $window = max(1, (int) config('discussions.notifications.owner_window_minutes')) * 60;
                $owner = User::query()->find($subject->ownerId);

                if ($owner !== null) {
                    $this->dispatcher->dispatch(
                        $owner, NotificationType::NewCommentOnYourContent, ['title' => $subject->title],
                        'content_comment:'.$comment->discussion_thread_id.':'.intdiv(now()->timestamp, $window),
                        [], ['comment' => (string) $comment->public_id], 'discussions.permalink',
                    );
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function hidden(DiscussionComment $comment): void
    {
        try {
            if ($comment->user_id === null) {
                return;
            }

            $author = User::query()->find($comment->user_id);

            if ($author !== null) {
                $this->dispatcher->dispatch(
                    $author, NotificationType::CommentHidden,
                    ['reason' => DiscussionCommentReport::REASONS[$comment->moderation_reason] ?? ''],
                    'comment_hidden:'.$comment->id.':'.($comment->moderated_at?->timestamp ?? 0),
                    [], ['comment' => (string) $comment->public_id], 'discussions.permalink',
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
