<?php

namespace App\Listeners;

use App\Events\FriendChallengeCompleted;
use App\Models\FriendChallenge;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/** "نتيجة التحدي جاهزة" لكل طرف مرة واحدة (friend-challenge-result:{id}:{user}) بعد اكتمال نتيجتَي الطرفين فقط. فشلها لا يمسّ النتيجة. */
class SendFriendChallengeResultNotification
{
    public function handle(FriendChallengeCompleted $event): void
    {
        try {
            $c = FriendChallenge::query()->with(['challenger', 'opponent', 'puzzle:id,title'])->find($event->challengeId);

            if ($c === null || $c->status !== FriendChallenge::STATUS_COMPLETED) {
                return;
            }

            foreach ([[$c->challenger, $c->opponent], [$c->opponent, $c->challenger]] as [$me, $other]) {
                $outcome = $c->is_draw ? 'تعادلتما' : ($c->winner_user_id === $me->id ? 'فزت' : 'خسرت');

                app(NotificationDispatcher::class)->dispatch(
                    $me,
                    NotificationType::FriendChallengeResultReady,
                    ['name' => $other->name, 'puzzle' => $c->puzzle->title, 'outcome' => $outcome],
                    "friend-challenge-result:{$c->id}:{$me->id}",
                    ['challenge_id' => $c->id],
                    ['challenge' => $c->public_id],
                    'friends.challenges.show',
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
