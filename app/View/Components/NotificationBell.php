<?php

namespace App\View\Components;

use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * جرس الإشعارات بشريط التنقل. تكلفة الاستعلام ثابتة وصغيرة: استعلامان فقط لمستخدم موثَّق (عدّ غير المقروء
 * بفهرس notifications_inbox_index + أحدث N إشعارًا) - لا استعلام لكل عنصر ولا تحميل لكل الصفوف. الضيف
 * (وغير الموثَّق/المجمَّد) لا يرى شيئًا ولا يُنفَّذ أي استعلام.
 */
class NotificationBell extends Component
{
    public bool $show = false;

    public int $unread = 0;

    public Collection $recent;

    public function __construct()
    {
        $this->recent = collect();
        $user = auth()->user();

        if ($user === null || $user->email_verified_at === null || $user->is_frozen) {
            return;
        }

        $this->show = true;
        $this->unread = $user->unreadNotifications()->count();
        $this->recent = $user->notifications()->limit((int) config('player_notifications.dropdown_limit'))->get();
    }

    public function render(): View
    {
        return view('components.notification-bell');
    }
}
