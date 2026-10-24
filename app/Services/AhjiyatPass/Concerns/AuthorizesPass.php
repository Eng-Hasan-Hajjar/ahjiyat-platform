<?php

namespace App\Services\AhjiyatPass\Concerns;

use App\Models\User;
use App\Services\AhjiyatPass\PassException;

/**
 * E40 - صلاحية `passes.*` تُفرض داخل الخدمة نفسها (لا تكفي حماية الواجهة). Super Admin يمرّ بالبوابة العامة للصلاحيات فقط.
 */
trait AuthorizesPass
{
    /** @throws PassException */
    protected function authorizePass(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new PassException('forbidden', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }
}
