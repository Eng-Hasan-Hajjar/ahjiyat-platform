<?php

namespace App\Listeners;

use App\Events\FriendChallengeCreated;
use App\Models\FriendChallenge;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "تحدّاك {اسم} في أحجية" للخصم فقط، مرة واحدة (friend-challenge-received:{id})، وفقط إن بقي التحدي pending. فشله لا يمسّ التحدي. */
class SendFriendChallengeReceivedNotification
{
    public function handle(FriendChallengeCreated $event): void
    {
        try {
            $c = FriendChallenge::query()->with(['challenger:id,name', 'opponent', 'puzzle:id,title'])->find($event->challengeId);

            if ($c === null || $c->status !== FriendChallenge::STATUS_PENDING) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $c->opponent,
                NotificationType::FriendChallengeReceived,
                ['name' => $c->challenger->name, 'puzzle' => $c->puzzle->title],
                "friend-challenge-received:{$c->id}",
                ['challenge_id' => $c->id],
                ['challenge' => $c->public_id],
                'friends.challenges.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
