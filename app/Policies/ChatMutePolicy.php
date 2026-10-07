<?php

namespace App\Policies;

use App\Models\User;

/** كتم الدردشة العامة (E21-N2): العرض والإدارة بـchat.moderate (الكتم/الرفع عبر ChatMuteService المدقَّقة). لا تعديل أو حذف خام. */
class ChatMutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('chat.moderate');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('chat.moderate');
    }

    public function create(User $user): bool
    {
        return false;     // الكتم إجراء مجال (ChatMuteService) لا نموذج خام
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
