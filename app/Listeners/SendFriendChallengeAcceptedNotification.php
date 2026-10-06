<?php

namespace App\Listeners;

use App\Events\FriendChallengeAccepted;
use App\Models\FriendChallenge;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "قبل {اسم} تحدّيك" للمتحدّي فقط، مرة واحدة (friend-challenge-accepted:{id}). فشله لا يمسّ التحدي. */
class SendFriendChallengeAcceptedNotification
{
    public function handle(FriendChallengeAccepted $event): void
    {
        try {
            $c = FriendChallenge::query()->with(['challenger', 'opponent:id,name', 'puzzle:id,title'])->find($event->challengeId);

            if ($c === null || ! in_array($c->status, [FriendChallenge::STATUS_ACCEPTED, FriendChallenge::STATUS_COMPLETED], true)) {
                return;
            }

            app(NotificationDispatcher::class)->dispatch(
                $c->challenger,
                NotificationType::FriendChallengeAccepted,
                ['name' => $c->opponent->name, 'puzzle' => $c->puzzle->title],
                "friend-challenge-accepted:{$c->id}",
                ['challenge_id' => $c->id],
                ['challenge' => $c->public_id],
                'friends.challenges.show',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
