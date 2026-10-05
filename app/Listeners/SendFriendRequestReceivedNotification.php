<?php

namespace App\Listeners;

use App\Events\FriendRequestCreated;
use App\Models\Friendship;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Social\BlockService;

/**
 * "أرسل لك {اسم} طلب صداقة" للمستلم، مرة واحدة لكل طلب حقيقي (المفتاح friend-request:{friendship}). لا إشعار إن لم يعد الطلب
 * قائمًا (حُذف/قُبل) أو بين الطرفين حظر. معلوماتي فقط. أي فشل يُلتقَط ولا يمسّ الصداقة.
 */
class SendFriendRequestReceivedNotification
{
    public function handle(FriendRequestCreated $event): void
    {
        try {
            $friendship = Friendship::query()->with(['requester:id,name', 'addressee'])->find($event->friendshipId);

            if ($friendship === null || $friendship->status !== Friendship::STATUS_PENDING
                || app(BlockService::class)->blockedEitherWay($friendship->requester, $friendship->addressee)) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $friendship->addressee,
                NotificationType::FriendRequestReceived,
                ['name' => $friendship->requester->name],
                "friend-request:{$friendship->id}",
                ['friendship_id' => $friendship->id],
                [],
                'friends.index',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
