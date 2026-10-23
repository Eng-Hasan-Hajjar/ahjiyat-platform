<?php

namespace App\Services\CreatorFinance\Concerns;

use App\Models\CreatorEarningAccount;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceException;

/**
 * E39 - حراس مشتركة لخدمات المالية: صلاحية `creator-finance.*` تُفرض داخل الخدمة نفسها (لا تكفي حماية الواجهة)، ولا يعالج أحدٌ حسابه الخاص.
 * Super Admin يمرّ بالبوابة العامة للصلاحيات فقط؛ قاعدة «لا معالجة لحسابك» تسري على الجميع بلا استثناء.
 */
trait AuthorizesCreatorFinance
{
    /** @throws CreatorFinanceException */
    protected function authorizeFinance(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new CreatorFinanceException('لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }

    /** @throws CreatorFinanceException */
    protected function assertNotOwnAccount(User $actor, CreatorEarningAccount $account): void
    {
        if ((int) $actor->getKey() === (int) $account->user_id) {
            throw new CreatorFinanceException('لا يجوز لك معالجة حسابك المالي الخاص.');
        }
    }
}
