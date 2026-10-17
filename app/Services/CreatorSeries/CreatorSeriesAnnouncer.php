<?php

namespace App\Services\CreatorSeries;

use App\Jobs\DispatchCreatorSeriesChunk;
use App\Models\CreatorFollow;
use App\Models\CreatorSeriesRevision;
use Throwable;

/**
 * E30 - نقطة الإعلان الوحيدة عن «سلسلة جديدة» لمتابعي المبدع، تُستدعى من CreatorSeriesReviewService **بعد** إتمام معاملة الاعتماد (بعد الـcommit).
 * نفس نمط E29 (CreatorPublicationAnnouncer): فشل الإعلان لا يتراجع عنه الاعتماد، لا متابعون ⇒ لا Job، والتوزيع بدفعات بمؤشر id عبر الطابور.
 * إشعار واحد لكل (نسخة منشورة، متابع). نسخة جديدة لسلسلة نُشرت سابقًا تُعلَن فقط إن فُعِّل creator_series.notifications.on_revision.
 */
class CreatorSeriesAnnouncer
{
    public function announce(CreatorSeriesRevision $revision, int $creatorProfileId, bool $isUpdate): void
    {
        try {
            if (! (bool) config('creator_series.notifications.on_new_series', true)) {
                return;
            }

            if ($isUpdate && ! (bool) config('creator_series.notifications.on_revision', true)) {
                return;
            }

            if (! CreatorFollow::query()->where('creator_profile_id', $creatorProfileId)->exists()) {
                return;
            }

            DispatchCreatorSeriesChunk::dispatch((int) $revision->id, $isUpdate)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
