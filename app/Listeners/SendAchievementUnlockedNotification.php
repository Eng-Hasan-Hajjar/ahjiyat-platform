<?php

namespace App\Listeners;

use App\Events\AchievementUnlocked;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

class SendAchievementUnlockedNotification
{
    public function handle(AchievementUnlocked $event): void
    {
        try {
            app(NotificationDispatcher::class)->dispatch(
                $event->user,
                NotificationType::AchievementUnlocked,
                ['name' => $event->achievement->name],
                "achievement:{$event->user->id}:{$event->achievement->id}",
                ['achievement_id' => $event->achievement->id],
            );
        } catch (\Throwable $e) {
            report($e); // لا يصل أي فشل إشعار إلى مسار اللعب.
        }
    }
}
