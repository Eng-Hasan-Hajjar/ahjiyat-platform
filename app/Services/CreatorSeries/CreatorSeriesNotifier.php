<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E30 - إشعارات المبدع بنتيجة مراجعة سلسلته (E15). تُستدعى دائمًا **بعد** نجاح تغيير الحالة (بعد الـcommit) وفشلها لا يُرجع أي حالة:
 * إخفاق الإشعار لا يتراجع عنه الاعتماد/الرفض/الإخفاء. مفاتيح idempotency دلالية (حدث واحد = إشعار واحد).
 */
class CreatorSeriesNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function approved(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesApproved, ['title' => $revision->title], 'series_approved:'.$revision->id);
    }

    public function changesRequested(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesChangesRequested, ['title' => $revision->title, 'note' => $revision->review_note], 'series_changes_requested:'.$revision->id.':'.($revision->reviewed_at?->timestamp ?? 0));
    }

    public function rejected(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesRejected, ['title' => $revision->title, 'note' => $revision->review_note], 'series_rejected:'.$revision->id);
    }

    public function hidden(CreatorSeries $series): void
    {
        try {
            $user = CreatorProfile::query()->with('user')->find($series->creator_profile_id)?->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, NotificationType::SeriesHidden, ['title' => (string) $series->published_title], 'series_hidden:'.$series->id.':'.($series->hidden_at?->timestamp ?? 0));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function send(CreatorSeriesRevision $revision, NotificationType $type, array $params, string $key): void
    {
        try {
            $user = $revision->series()->first()?->creator()->first()?->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, $type, $params, $key);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
