<?php

namespace App\Services\Engagement;

use App\Jobs\DispatchCreatorPublicationChunk;
use App\Models\CreatorFollow;
use App\Models\CreatorPuzzleRevision;
use Throwable;

/**
 * E29 - نقطة الإعلان الوحيدة عن «نشر جديد» لمتابعي المبدع. تُستدعى من CreatorReviewService **بعد** إتمام معاملة الاعتماد (بعد الـcommit).
 *
 *  - فشل الإعلان بأي شكل لا يتراجع عنه الاعتماد (try/catch + report): النشر أهم من الإشعار.
 *  - لا متابعون ← لا Job إطلاقًا (استعلام exists واحد على فهرس المبدع).
 *  - التوزيع نفسه بدفعات بمؤشر id عبر الطابور (DispatchCreatorPublicationChunk): لا حلقة على كل المتابعين ولا داخل معاملة طلب.
 *  - لا إشعار على التقييم أو المفضلة أو اللعب أبدًا؛ إشعار واحد لكل (نسخة منشورة، متابع).
 */
class CreatorPublicationAnnouncer
{
    public function announce(CreatorPuzzleRevision $revision, int $creatorProfileId, bool $isUpdate): void
    {
        try {
            if ($isUpdate && ! (bool) config('engagement.notifications.on_revision', true)) {
                return;
            }

            if (! CreatorFollow::query()->where('creator_profile_id', $creatorProfileId)->exists()) {
                return;
            }

            DispatchCreatorPublicationChunk::dispatch((int) $revision->id, $isUpdate)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
