<?php

namespace App\Support;

/**
 * E25 - هوية الإصدار. مصدرها الوحيد البيئة (APP_RELEASE أو RELEASE_SHA) عبر config('operations.release.id').
 * القيمة تُعرض علنًا في /ready ويُضاف لسياق السجل، لذلك لا تُقبل إلا بصيغة آمنة قصيرة (لا مسافات ولا أسطر ولا رموز غريبة).
 * لا تقرأ git ولا الملفات ولا أي سر.
 */
final class ReleaseInfo
{
    public const PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** المعرّف كما ضُبط (آمن) أو null. */
    public static function id(): ?string
    {
        $value = config('operations.release.id');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    /** للعرض: SHA كامل (40 خانة سداسية) يُختصر إلى 7، وغيره يُعرض كما هو. */
    public static function short(): ?string
    {
        $id = self::id();

        if ($id === null) {
            return null;
        }

        return preg_match('/^[0-9a-f]{40}$/i', $id) === 1 ? strtolower(substr($id, 0, 7)) : $id;
    }

    public static function isConfigured(): bool
    {
        return self::id() !== null;
    }
}
