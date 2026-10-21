<?php

namespace App\Services\Editorial;

use App\Models\EditorialShelf;
use App\Models\EditorialShelfItem;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * E35 - إدارة الأرفف التحريرية. **كل كتابة هنا**: صلاحية editorial.manage (بالصلاحيات لا بأسماء الأدوار) + معاملة بقفل + تدقيق + تفريغ كاش القراءة.
 *
 *  - الحالات: draft → active → archived (المؤرشف نهائي: لإعادة الاستعمال يُنسخ رف جديد). التفعيل يتطلب ≥ 1 عنصر، ونافذة جدولة لم تنتهِ، وسقفًا لعدد الرفوف الفعّالة بالموضع.
 *  - المواضع المسموحة: discover + home فقط (قائمة مغلقة EditorialShelf::PLACEMENTS). النصوص عادية (NoMarkup) بلا HTML؛ المفتاح الداخلي slug فريد.
 *  - العناصر: (نوع من EditorialContentType، معرّف رقمي) مع ترتيب صريح؛ فريدة بالرف؛ يُتحقق من وجودها وأهليتها **لحظة الحفظ**، وتُعاد الأهلية وقت القراءة دائمًا.
 *    لا تُخزَّن أصناف ولا مسارات ولا روابط ولا HTML.
 *  - الجدولة بتوقيت المنصة (UTC) وتُقيَّم وقت القراءة (لا مهمة مجدولة).
 *  - الأحداث: shelf_created, shelf_updated, shelf_activated, shelf_archived, shelf_items_changed.
 */
class EditorialShelfService
{
    public function __construct(protected OperationalAuditService $audit, protected EditorialShelfResolver $resolver) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): EditorialShelf
    {
        $this->authorize($actor);
        $clean = $this->validated($data, null);

        $shelf = DB::transaction(function () use ($actor, $clean) {
            $shelf = new EditorialShelf;
            $shelf->forceFill($clean + ['status' => EditorialShelf::STATUS_DRAFT, 'created_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $this->audit->log('shelf_created', $shelf, ['internal_key' => $shelf->internal_key, 'placement' => $shelf->placement], $actor);

            return $shelf;
        });

        EditorialShelves::flush();

        return $shelf;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, EditorialShelf $shelf, array $data): EditorialShelf
    {
        $this->authorize($actor);

        $updated = DB::transaction(function () use ($actor, $shelf, $data) {
            $locked = EditorialShelf::query()->whereKey($shelf->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EditorialShelf::STATUS_ARCHIVED) {
                throw new InvalidArgumentException('لا يمكن تعديل رف مؤرشف.');
            }

            $clean = $this->validated($data + $locked->only(['internal_key', 'title', 'subtitle', 'placement', 'starts_at', 'ends_at', 'sort_order', 'max_items']), $locked);
            $changed = array_keys(array_filter($clean, fn ($v, $k) => (string) $locked->getAttribute($k) !== (string) $v, ARRAY_FILTER_USE_BOTH));

            $locked->forceFill($clean + ['updated_by' => $actor->id])->save();
            $this->audit->log('shelf_updated', $locked, ['internal_key' => $locked->internal_key, 'changed' => $changed], $actor);

            return $locked;
        });

        EditorialShelves::flush();

        return $updated;
    }

    public function activate(User $actor, EditorialShelf $shelf): EditorialShelf
    {
        $this->authorize($actor);

        $activated = DB::transaction(function () use ($actor, $shelf) {
            $locked = EditorialShelf::query()->whereKey($shelf->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EditorialShelf::STATUS_ARCHIVED) {
                throw new InvalidArgumentException('لا يمكن تفعيل رف مؤرشف.');
            }

            if ($locked->status === EditorialShelf::STATUS_ACTIVE) {
                return $locked;
            }

            if (! EditorialShelfItem::query()->where('editorial_shelf_id', $locked->id)->exists()) {
                throw new InvalidArgumentException('أضف عنصرًا واحدًا على الأقل قبل التفعيل.');
            }

            if ($locked->ends_at !== null && $locked->ends_at <= now()) {
                throw new InvalidArgumentException('انتهت نافذة الجدولة لهذا الرف؛ عدّل تاريخ الانتهاء أولًا.');
            }

            $cap = max(1, (int) config('collections.shelves.max_per_placement'));
            $others = EditorialShelf::query()->where('placement', $locked->placement)->where('status', EditorialShelf::STATUS_ACTIVE)->whereKeyNot($locked->id)->count();

            if ($others >= $cap) {
                throw new InvalidArgumentException('بلغ هذا الموضع الحد الأقصى من الرفوف الفعّالة ('.$cap.'). أرشف رفًا أولًا.');
            }

            $locked->forceFill(['status' => EditorialShelf::STATUS_ACTIVE, 'updated_by' => $actor->id])->save();
            $this->audit->log('shelf_activated', $locked, ['internal_key' => $locked->internal_key, 'placement' => $locked->placement], $actor);

            return $locked;
        });

        EditorialShelves::flush();

        return $activated;
    }

    public function archive(User $actor, EditorialShelf $shelf): EditorialShelf
    {
        $this->authorize($actor);

        $archived = DB::transaction(function () use ($actor, $shelf) {
            $locked = EditorialShelf::query()->whereKey($shelf->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EditorialShelf::STATUS_ARCHIVED) {
                return $locked;
            }

            $locked->forceFill(['status' => EditorialShelf::STATUS_ARCHIVED, 'updated_by' => $actor->id])->save();
            $this->audit->log('shelf_archived', $locked, ['internal_key' => $locked->internal_key], $actor);

            return $locked;
        });

        EditorialShelves::flush();

        return $archived;
    }

    /**
     * يستبدل عناصر الرف كلها بالقائمة المعطاة (بترتيبها). كل عنصر: [type, id]. يرفض نوعًا مجهولًا أو معرّفًا غير موجود/غير مؤهَّل أو مكررًا أو تجاوز السقف.
     *
     * @param  list<array{0: string, 1: int|string}>  $items
     */
    public function setItems(User $actor, EditorialShelf $shelf, array $items): EditorialShelf
    {
        $this->authorize($actor);
        $cap = max(1, (int) config('collections.shelves.max_items_cap'));

        if (count($items) > $cap) {
            throw new InvalidArgumentException('الحد الأقصى لعناصر الرف '.$cap.'.');
        }

        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            $type = EditorialContentType::tryFrom((string) ($item[0] ?? ''));
            $id = $item[1] ?? null;

            if ($type === null || ! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                throw new InvalidArgumentException('عنصر غير صالح في الرف.');
            }

            $key = $type->value.':'.(int) $id;

            if (isset($seen[$key])) {
                throw new InvalidArgumentException('لا يمكن تكرار المحتوى نفسه في الرف.');
            }

            if (! $this->resolver->exists($type, (int) $id)) {
                throw new InvalidArgumentException('المحتوى المختار ('.$type->label().') غير متاح للعرض الآن.');
            }

            $seen[$key] = true;
            $normalized[] = [$type->value, (int) $id];
        }

        $updated = DB::transaction(function () use ($actor, $shelf, $normalized) {
            $locked = EditorialShelf::query()->whereKey($shelf->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EditorialShelf::STATUS_ARCHIVED) {
                throw new InvalidArgumentException('لا يمكن تعديل رف مؤرشف.');
            }

            if ($normalized === [] && $locked->status === EditorialShelf::STATUS_ACTIVE) {
                throw new InvalidArgumentException('لا يمكن إفراغ رف فعّال؛ أرشفه أو أضف عنصرًا.');
            }

            EditorialShelfItem::query()->where('editorial_shelf_id', $locked->id)->delete();

            foreach ($normalized as $position => [$type, $id]) {
                $row = new EditorialShelfItem;
                $row->forceFill(['editorial_shelf_id' => $locked->id, 'content_type' => $type, 'content_id' => $id, 'position' => $position + 1])->save();
            }

            $locked->forceFill(['updated_by' => $actor->id])->save();
            $this->audit->log('shelf_items_changed', $locked, ['internal_key' => $locked->internal_key, 'count' => count($normalized), 'types' => array_values(array_unique(array_column($normalized, 0)))], $actor);

            return $locked;
        });

        EditorialShelves::flush();

        return $updated;
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('editorial.manage'), 403);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, ?EditorialShelf $existing): array
    {
        $cap = max(1, (int) config('collections.shelves.max_items_cap'));

        $validator = Validator::make($data, [
            'internal_key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{2,63}$/', Rule::unique('editorial_shelves', 'internal_key')->ignore($existing?->id)],
            'title' => ['required', 'string', 'min:2', 'max:80', new NoMarkup],
            'subtitle' => ['nullable', 'string', 'max:160', new NoMarkup],
            'placement' => ['required', Rule::in(array_keys(EditorialShelf::PLACEMENTS))],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:'.$cap],
        ], [], [
            'internal_key' => 'المفتاح الداخلي', 'title' => 'العنوان', 'subtitle' => 'العنوان الفرعي', 'placement' => 'الموضع',
            'starts_at' => 'البداية', 'ends_at' => 'النهاية', 'sort_order' => 'الترتيب', 'max_items' => 'أقصى عدد عناصر',
        ]);

        $valid = $validator->validate();

        return [
            'internal_key' => (string) $valid['internal_key'],
            'title' => trim((string) $valid['title']),
            'subtitle' => filled($valid['subtitle'] ?? null) ? trim((string) $valid['subtitle']) : null,
            'placement' => (string) $valid['placement'],
            'starts_at' => filled($valid['starts_at'] ?? null) ? $valid['starts_at'] : null,
            'ends_at' => filled($valid['ends_at'] ?? null) ? $valid['ends_at'] : null,
            'sort_order' => (int) ($valid['sort_order'] ?? 0),
            'max_items' => (int) ($valid['max_items'] ?? config('collections.shelves.default_max_items')),
        ];
    }
}
