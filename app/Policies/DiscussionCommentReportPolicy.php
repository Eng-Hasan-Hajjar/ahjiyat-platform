<?php

namespace App\Policies;

use App\Models\User;

/**
 * E36 - بلاغات التعليقات: العرض بـdiscussions.view. **لا إنشاء ولا تعديل خام ولا حذف** من لوحة الإدارة: النص لا يُكتب باسم أحد ولا يُمحى الدليل،
 * وكل تغيير حالة (إخفاء/استعادة/قفل/حسم بلاغ) يمرّ بـDiscussionModerationService (يتحقق من discussions.moderate + تدقيق + إشعار). بالصلاحيات لا بأسماء الأدوار.
 */
class DiscussionCommentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('discussions.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('discussions.view');
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
