<?php

namespace App\Policies;

use App\Models\User;

/** صلاحيات المنافسات (E17). الإجراءات الحساسة بدورة الحياة بصلاحيات مستقلة قابلة للتفويض الجزئي. */
class CompetitiveEventPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'competitive_events';

    /** الحذف لمسوّدة فقط: ما نُشر يحتفظ بتاريخه (يُلغى ولا يُحذف). */
    public function delete(User $user, $model): bool
    {
        return parent::delete($user, $model) && $model->status === \App\Models\CompetitiveEvent::STATUS_DRAFT;
    }

    public function viewRewards(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.rewards.view");
    }

    public function manageRewards(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.rewards.manage");
    }

    public function retryRewards(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.rewards.retry");
    }

    public function publish(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.publish");
    }

    public function cancel(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.cancel");
    }

    public function finalize(User $user, $model): bool
    {
        return $user->can("{$this->prefix}.finalize");
    }
}
