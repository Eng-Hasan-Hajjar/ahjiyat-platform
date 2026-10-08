<?php

namespace App\Services\Chat;

use App\Models\ChatThread;
use App\Models\User;
use App\Services\Teams\TeamMembershipService;

/**
 * بيانات قائمة المحادثات الجانبية (E22) - **قراءة فقط** وبلا أي إنشاء للغرف عند العرض (الغرفة العامة المفقودة = 0 غير مقروء لا إنشاء).
 * نفس مصادر E21 (ChatUnreadService/ChatThreadService/عضوية الفريق): لا منطق وصول ولا عدّ جديد، فقط تجميع للعرض بصفحة الغرفة.
 */
class ChatSidebar
{
    public function __construct(protected ChatUnreadService $unread, protected ChatThreadService $threads, protected TeamMembershipService $members) {}

    /** @return array{conversations: \Illuminate\Support\Collection, team: mixed, cap: int, teamUnread: int, globalUnread: int} */
    public function for(User $user): array
    {
        $membership = $this->members->membershipOf($user);
        $global = ChatThread::query()->where('type', ChatThread::TYPE_GLOBAL)->first();
        $teamThread = $membership ? $this->threads->findForTeam($membership->team) : null;

        return [
            'conversations' => $this->unread->directList($user),
            'team' => $membership?->team,
            'cap' => (int) config('chat.unread_cap', 99),
            'teamUnread' => $teamThread ? $this->unread->forThread($user, $teamThread) : 0,
            'globalUnread' => $global ? $this->unread->forThread($user, $global) : 0,
        ];
    }
}
