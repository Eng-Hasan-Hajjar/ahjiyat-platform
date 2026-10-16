<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E28 - إشعارات المبدع (E15). تُستدعى دائمًا **بعد** نجاح تغيير الحالة (بعد الـcommit) وفشلها لا يُرجع أي حالة: إخفاق الإشعار لا يتراجع عنه الاعتماد/النشر.
 * مفاتيح idempotency دلالية (حدث واحد = إشعار واحد). لا إشعار على اللعب أبدًا.
 */
class CreatorNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function creatorApproved(CreatorProfile $profile): void
    {
        $this->send($profile, NotificationType::CreatorApproved, [], 'creator_approved:'.$profile->id.':'.($profile->reviewed_at?->timestamp ?? 0));
    }

    public function creatorRejected(CreatorProfile $profile): void
    {
        $this->send($profile, NotificationType::CreatorRejected, ['note' => $profile->review_note], 'creator_rejected:'.$profile->id.':'.($profile->reviewed_at?->timestamp ?? 0));
    }

    public function submissionApproved(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionApproved, ['title' => $revision->title], 'submission_approved:'.$revision->id);
    }

    public function changesRequested(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionChangesRequested, ['title' => $revision->title, 'note' => $revision->review_note], 'submission_changes_requested:'.$revision->id.':'.($revision->reviewed_at?->timestamp ?? 0));
    }

    public function submissionRejected(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionRejected, ['title' => $revision->title, 'note' => $revision->review_note], 'submission_rejected:'.$revision->id);
    }

    public function publicationHidden(CreatorPuzzle $work): void
    {
        $this->send($work->creator, NotificationType::PublicationHidden, ['title' => (string) $work->published_title], 'publication_hidden:'.$work->id.':'.($work->hidden_at?->timestamp ?? 0));
    }

    protected function send(CreatorProfile $profile, NotificationType $type, array $params, string $key): void
    {
        try {
            $user = $profile->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, $type, $params, $key);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
