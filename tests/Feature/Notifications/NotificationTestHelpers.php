<?php

use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/** مساعدات E15 (تُضمَّن بـrequire_once من كل ملف اختبار فلا اعتماد على ترتيب التحميل). */
if (! function_exists('e15Note')) {
    function e15Note(User $user, array $o = []): DatabaseNotification
    {
        $type = $o['type'] ?? NotificationType::AchievementUnlocked;

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type->value,
            'data' => [
                'type' => $type->value,
                'category' => $type->category()->value,
                'title' => $o['title'] ?? 'عنوان إشعار',
                'body' => $o['body'] ?? 'نص إشعار',
                'icon' => $type->icon(),
                'action_route' => $o['route'] ?? $type->defaultRoute(),
                'action_params' => $o['params'] ?? [],
                'idempotency_key' => 'k',
                'refs' => [],
            ],
            'type_key' => $type->value,
            'category' => $type->category()->value,
            'idempotency_key' => $o['key'] ?? (string) Str::uuid(),
            'read_at' => $o['read_at'] ?? null,
            'created_at' => $o['created_at'] ?? now(),
        ]);
    }

    /** يُنشئ صف سلسلة كما يكتبه StreakService فعليًا (تاريخ نصي Y-m-d). */
    function e15Streak(User $user, int $streak, string $lastActiveDate): PlayerStreak
    {
        return PlayerStreak::create([
            'user_id' => $user->id,
            'current_streak' => $streak,
            'longest_streak' => $streak,
            'last_active_date' => $lastActiveDate,
        ]);
    }
}
