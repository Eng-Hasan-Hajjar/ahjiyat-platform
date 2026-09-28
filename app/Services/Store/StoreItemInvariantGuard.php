<?php

namespace App\Services\Store;

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\StoreItem;

/**
 * E11.1: السلطة النهائية الوحيدة لقواعد العناصر التجميلية. تُستدعى من
 * StoreItem::saving فتغطي كل مسار كتابة Eloquent. Filament يبقى UX فقط.
 *
 * تصميم متوافق مع البيانات القديمة: كل قاعدة تُفحَص عند الإنشاء أو عند تغيير
 * الحقول المعنية فقط - سجل قديم غير مطابق لا يفشل حفظه لسبب لا علاقة له.
 *
 * حدود معلنة: الكتابة عبر Query Builder (StoreItem::query()->update()،
 * DB::table()) أو saveQuietly() لا تُطلق أحداث Eloquent فتتجاوز هذا الحارس.
 * لا قيود CHECK بقاعدة البيانات (لا Schema جديدة بهذه المرحلة).
 */
class StoreItemInvariantGuard
{
    /** مصدر واحد لصيغة اللون - يستخدمه Filament وطبقة العرض أيضًا. \z لا $ حتى لا يمرّ سطر جديد لاحق. */
    public const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}\z/';

    public const TITLE_MAX_LENGTH = 60;

    private const COSMETIC_FIELDS = ['cosmetic_slot', 'cosmetic_text', 'cosmetic_color'];

    public function enforce(StoreItem $item): void
    {
        // نموذج مُحمَّل جزئيًا (select محدود) بلا item_type: لا نحكم ولا نُعدِّل بالتخمين.
        if (! array_key_exists('item_type', $item->getAttributes())) {
            return;
        }

        $isNew = ! $item->exists;

        if (! $isNew) {
            $this->assertUsedCosmeticNotMutated($item);
        }

        $this->normalizeBlanks($item);

        if ($item->item_type !== StoreItem::TYPE_COSMETIC) {
            $this->clearCosmeticFields($item);

            return;
        }

        $this->assertInventoryFulfillment($item, $isNew);
        $this->assertSlotAllowed($item, $isNew);
        $this->assertColorValid($item, $isNew);
        $this->applyTitleRules($item, $isNew);
        $this->assertImagePresent($item, $isNew);
    }

    /** بند 9/11: عنصر تجميلي مُستخدَم فعليًا - لا تغيير فتحة مُسنَدة ولا نوع/تسليم. */
    protected function assertUsedCosmeticNotMutated(StoreItem $item): void
    {
        if ($item->getOriginal('item_type') !== StoreItem::TYPE_COSMETIC) {
            return;
        }

        $typeChanged = $item->isDirty(['item_type', 'fulfillment_type']);
        // إسناد فتحة لعنصر قديم بلا فتحة (null → قيمة) مسموح مرة واحدة - هو المسار الوحيد ليصير قابلًا للتجهيز.
        $slotChanged = $item->getOriginal('cosmetic_slot') !== null && $item->isDirty('cosmetic_slot');

        if (! $typeChanged && ! $slotChanged) {
            return;
        }

        if (! $item->hasCosmeticUsageHistory()) {
            return;
        }

        throw new StoreItemInvariantViolation(
            $typeChanged
                ? 'لا يمكن تغيير نوع أو طريقة تسليم عنصر تجميلي سبق استخدامه.'
                : 'لا يمكن تغيير فتحة عنصر تجميلي سبق استخدامه.'
        );
    }

    protected function normalizeBlanks(StoreItem $item): void
    {
        foreach (self::COSMETIC_FIELDS as $field) {
            if ($item->getAttribute($field) === '') {
                $item->setAttribute($field, null);
            }
        }
    }

    /** بند 8: عنصر غير تجميلي لا يحمل بيانات تجميلية عالقة - تُنظَّف تلقائيًا. */
    protected function clearCosmeticFields(StoreItem $item): void
    {
        foreach (self::COSMETIC_FIELDS as $field) {
            if ($item->getAttribute($field) !== null) {
                $item->setAttribute($field, null);
            }
        }
    }

    /** بند 6: أي عنصر تجميلي تسليمه "مخزون" حصرًا. */
    protected function assertInventoryFulfillment(StoreItem $item, bool $isNew): void
    {
        if (! $isNew && ! $item->isDirty(['item_type', 'fulfillment_type', 'cosmetic_slot'])) {
            return;
        }

        if ($item->fulfillment_type !== StoreItem::FULFILLMENT_INVENTORY) {
            throw new StoreItemInvariantViolation('العنصر التجميلي يجب أن يكون تسليمه "مخزون" حصرًا.');
        }
    }

    /** بند 7: أي قيمة غير null يجب أن تكون من COSMETIC_SLOTS. null يبقى Legacy غير قابل للتجهيز. */
    protected function assertSlotAllowed(StoreItem $item, bool $isNew): void
    {
        $slot = $item->cosmetic_slot;

        if ($slot === null || (! $isNew && ! $item->isDirty('cosmetic_slot'))) {
            return;
        }

        if (! in_array($slot, StoreItem::COSMETIC_SLOTS, true)) {
            throw new StoreItemInvariantViolation('فتحة تجميلية غير صالحة.');
        }
    }

    /** بند 12/13/16: Hex بستة أرقام فقط، أو null. */
    protected function assertColorValid(StoreItem $item, bool $isNew): void
    {
        $color = $item->cosmetic_color;

        if ($color === null || (! $isNew && ! $item->isDirty('cosmetic_color'))) {
            return;
        }

        if (! is_string($color) || preg_match(self::COLOR_PATTERN, $color) !== 1) {
            throw new StoreItemInvariantViolation('لون غير صالح - المسموح فقط صيغة Hex مثل #AABBCC.');
        }
    }

    /** بند 14/15: اللقب يتطلب نصًا عاديًا بطول آمن؛ غير اللقب لا يحمل نصًا/لونًا. */
    protected function applyTitleRules(StoreItem $item, bool $isNew): void
    {
        if ($item->cosmetic_slot !== StoreItem::SLOT_TITLE) {
            $item->setAttribute('cosmetic_text', null);
            $item->setAttribute('cosmetic_color', null);

            return;
        }

        if (! $isNew && ! $item->isDirty(['cosmetic_slot', 'cosmetic_text'])) {
            return;
        }

        $text = $item->cosmetic_text;

        if (! is_string($text) || trim($text) === '') {
            throw new StoreItemInvariantViolation('اللقب يتطلب نصًا.');
        }

        if (mb_strlen($text) > self::TITLE_MAX_LENGTH) {
            throw new StoreItemInvariantViolation('نص اللقب أطول من الحد المسموح ('.self::TITLE_MAX_LENGTH.' حرفًا).');
        }

        if (str_contains($text, '<') || str_contains($text, '>')) {
            throw new StoreItemInvariantViolation('نص اللقب يجب أن يكون نصًا عاديًا بلا وسوم.');
        }
    }

    /** بند 17: كل فتحة غير اللقب تتطلب image_path (الحضور فقط - نوع/حجم الملف يبقى لـFilament/E8). */
    protected function assertImagePresent(StoreItem $item, bool $isNew): void
    {
        $slot = $item->cosmetic_slot;

        if ($slot === null || $slot === StoreItem::SLOT_TITLE) {
            return;
        }

        if (! $isNew && ! $item->isDirty(['cosmetic_slot', 'image_path', 'item_type'])) {
            return;
        }

        if (blank($item->image_path)) {
            throw new StoreItemInvariantViolation('هذا النوع من العناصر التجميلية يتطلب صورة.');
        }
    }
}