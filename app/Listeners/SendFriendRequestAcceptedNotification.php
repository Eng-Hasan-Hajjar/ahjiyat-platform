<?php

namespace App\Listeners;

use App\Events\FriendshipAccepted;
use App\Models\Friendship;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Social\BlockService;

/**
 * "قبل {اسم} طلب صداقتك" للمرسِل الأصلي، مرة واحدة (friend-accepted:{friendship}). لا إشعار عند الرفض أو الإزالة أو الحظر. أي فشل
 * يُلتقَط ولا يمسّ الصداقة. معلوماتي فقط.
 */
class SendFriendRequestAcceptedNotification
{
    public function handle(FriendshipAccepted $event): void
    {
        try {
            $friendship = Friendship::query()->with(['requester', 'addressee:id,name'])->find($event->friendshipId);

            if ($friendship === null || $friendship->status !== Friendship::STATUS_ACCEPTED
                || app(BlockService::class)->blockedEitherWay($friendship->requester, $friendship->addressee)) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $friendship->requester,
                NotificationType::FriendRequestAccepted,
                ['name' => $friendship->addressee->name],
                "friend-accepted:{$friendship->id}",
                ['friendship_id' => $friendship->id],
                [],
                'friends.index',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
