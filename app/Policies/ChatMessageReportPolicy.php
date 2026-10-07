<?php

namespace App\Policies;

use App\Models\User;

/** بلاغات الدردشة (E21-E8): العرض بـchat.reports.view، والإجراءات (إخفاء/كتم/مراجعة) بـchat.moderate عبر الخدمات. لا إنشاء ولا حذف ولا تعديل خام (تُنشأ البلاغات من المستخدمين فقط). بالصلاحيات لا بأسماء الأدوار. */
class ChatMessageReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('chat.reports.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('chat.reports.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
