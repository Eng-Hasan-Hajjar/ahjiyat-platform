<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E35 - الإبلاغ عن مجموعة. **سبب من قائمة مغلقة فقط** (UserCollectionReport::REASONS، بلا نص حر): لا يُحقن محتوى مسيء عبر البلاغ ولا يحمل المُبلِّغ بيانات.
 *
 *  - المُبلِّغ: موثَّق غير مجمَّد (EngagementGuard، قراءة حيّة). يبلّغ فقط عن مجموعة **ظاهرة له** (عامة/غير مدرجة، غير مخفية، مالك سليم، بلا حظر) وإلا 404 بلا كشف؛
 *    الخاصة لا تُبلَّغ لأن غير مالكها لا يعرفها أصلًا.
 *  - لا بلاغ على مجموعتك. بلاغ **مفتوح واحد** لكل (مجموعة، مبلِّغ) بقيد قاعدة بيانات (open_marker). بعد إغلاقه يمكن بلاغ جديد.
 *  - لا أثر على الاقتصاد ولا إشعار للمالك ولا للمبلِّغ (هوية المبلِّغ خاصة بالإشراف).
 */
class CollectionReportService
{
    public function __construct(protected EngagementGuard $guard, protected CollectionAccess $access) {}

    public function report(User $reporter, string $publicId, string $reason): UserCollectionReport
    {
        $this->guard->assertCanInteract($reporter);

        if (! array_key_exists($reason, UserCollectionReport::REASONS)) {
            throw new EngagementException('سبب البلاغ غير صالح.');
        }

        $collection = $this->access->findViewable($publicId, $reporter);

        if ($collection === null) {
            throw new ModelNotFoundException;
        }

        if ((int) $collection->user_id === (int) $reporter->id) {
            throw new EngagementException('لا يمكنك الإبلاغ عن مجموعتك.');
        }

        try {
            return DB::transaction(function () use ($collection, $reporter, $reason) {
                if (UserCollectionReport::query()->where('user_collection_id', $collection->id)->where('reporter_id', $reporter->id)->where('status', UserCollectionReport::STATUS_OPEN)->exists()) {
                    throw new EngagementException('لديك بلاغ مفتوح على هذه المجموعة بالفعل.');
                }

                $report = new UserCollectionReport;
                $report->forceFill([
                    'user_collection_id' => $collection->id,
                    'reporter_id' => $reporter->id,
                    'reason' => $reason,
                    'status' => UserCollectionReport::STATUS_OPEN,
                    'open_marker' => 1,
                ])->save();

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw new EngagementException('لديك بلاغ مفتوح على هذه المجموعة بالفعل.');
        }
    }

    /** هل لدى المستخدم بلاغ مفتوح على هذه المجموعة؟ (لعرض «تم الإبلاغ» بدل النموذج). */
    public function hasOpenReport(?User $user, int $collectionId): bool
    {
        return $user !== null && UserCollectionReport::query()->where('user_collection_id', $collectionId)->where('reporter_id', $user->id)->where('status', UserCollectionReport::STATUS_OPEN)->exists();
    }

    /** @return int عدد البلاغات المفتوحة على المجموعة (للإشراف فقط). */
    public function openCount(UserCollection $collection): int
    {
        return UserCollectionReport::query()->where('user_collection_id', $collection->id)->where('status', UserCollectionReport::STATUS_OPEN)->count();
    }
}
