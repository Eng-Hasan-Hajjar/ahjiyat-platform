<?php

namespace App\Policies;

use App\Models\User;

/**
 * E45 - مقالات معرفة تطوّر AI: العرض ai-assistant.view، الإنشاء/التعديل ai-assistant.knowledge.manage (مسودات)، النشر والسحب بصلاحية ai-assistant.knowledge.publish عبر KnowledgePublisher.
 * لا حذف: السحب (retired) هو المسار ويحفظ التاريخ.
 */
class AiKnowledgeArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai-assistant.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('ai-assistant.view');
    }

    public function create(User $user): bool
    {
        return $user->can('ai-assistant.knowledge.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('ai-assistant.knowledge.manage') && $model->status !== 'retired';
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
