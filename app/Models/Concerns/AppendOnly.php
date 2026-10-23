<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * E38 - سجلات إلحاقية فقط: الإدراج مسموح؛ التعديل والحذف مرفوضان على مستوى النموذج (دفاع إضافي فوق غياب أي مسار لهما في الخدمات).
 * النماذج التي تسمح بتعديل أعمدة مشتقة محددة (مثل active_marker) تعرّف `mutableColumns()` صراحةً بدل هذه السمة.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (): void {
            throw new LogicException('هذا السجل إلحاقي فقط ولا يقبل التعديل.');
        });

        static::deleting(function (): void {
            throw new LogicException('هذا السجل إلحاقي فقط ولا يقبل الحذف.');
        });
    }
}
