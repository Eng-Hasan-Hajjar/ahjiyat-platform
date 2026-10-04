<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * تفضيلات اللاعب. الإلزامي (الأمان) يتجاوز التفضيل دائمًا. لا صف = الافتراضيات (الكل مفعَّل).
 * القراءة لا تُنشئ صفوفًا (لا Side effect)؛ الكتابة وحدها تُنشئ الصف.
 */
class NotificationPreferenceService
{
    public function forUser(User $user): NotificationPreference
    {
        return NotificationPreference::query()->firstOrNew(['user_id' => $user->id]);
    }

    public function isEnabled(User $user, NotificationCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        $column = $category->preferenceColumn();

        return $column === null ? true : (bool) $this->forUser($user)->{$column};
    }

    /**
     * @param  array<string, mixed>  $flags  أعمدة التفضيل المسموحة فقط؛ أي مفتاح آخر (ومنه "security") يُتجاهل.
     */
    public function update(User $user, array $flags): NotificationPreference
    {
        $preference = $this->forUser($user);

        foreach (NotificationCategory::optional() as $category) {
            $column = $category->preferenceColumn();

            if ($column !== null && array_key_exists($column, $flags)) {
                $preference->{$column} = (bool) $flags[$column];
            }
        }

        $preference->user_id = $user->id;
        $preference->save();

        return $preference;
    }
}
