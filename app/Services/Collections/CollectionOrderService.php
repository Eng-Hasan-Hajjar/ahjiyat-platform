<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E35 - ترتيب عناصر المجموعة. **المالك وحده** (owned() محصور بالمالك، 404 لغيره) تحت **قفل صف المجموعة** نفسه الذي تأخذه الإضافة/الإزالة، فلا تتشابك إعادة الترتيب
 * مع إضافة متزامنة ولا مع ترتيب آخر.
 *
 * الترتيب المخزَّن = (position, id) تصاعديًا: حتمي دائمًا حتى لو تكرر الموضع. كل عملية **تطبّع** المواضع إلى 1..n ثم تكتب الصفوف المتغيّرة فقط بتحديث CASE واحد.
 *  - reorder(): قائمة معرّفات أعمال (جزئية مسموحة للصفحات المرقَّمة): تُعاد توزيع **المواضع التي تشغلها** تلك الأعمال بينها بالترتيب الجديد، وبقية العناصر لا تتحرك.
 *    كل معرّف يجب أن يخص المجموعة وإلا خطأ ولا يُكتب شيء؛ التكرار مرفوض.
 *  - move(): خطوة واحدة up|down مقابل الجار (يعمل بلا JavaScript وعبر حدود الصفحات).
 * لا أثر على الاقتصاد؛ تلمس updated_at للمجموعة فقط.
 */
class CollectionOrderService
{
    public function __construct(protected EngagementGuard $guard, protected CollectionService $collections) {}

    /** @param list<string> $workPublicIds @return array{changed: bool} */
    public function reorder(User $user, string $collectionPublicId, array $workPublicIds): array
    {
        $this->guard->assertCanInteract($user);

        $ids = array_values($workPublicIds);

        if ($ids === [] || count($ids) > (int) config('engagement.collections.max_items')) {
            throw new EngagementException('قائمة الترتيب غير صالحة.');
        }

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new EngagementException('قائمة الترتيب غير صالحة.');
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new EngagementException('قائمة الترتيب تحوي عناصر مكررة.');
        }

        return DB::transaction(function () use ($user, $collectionPublicId, $ids) {
            $collection = $this->collections->owned($user, $collectionPublicId, true);
            $rows = $this->ordered($collection->id);

            $indexByWork = [];

            foreach ($rows as $index => $row) {
                $indexByWork[$row->work_public_id] = $index;
            }

            $slots = [];

            foreach ($ids as $id) {
                if (! array_key_exists($id, $indexByWork)) {
                    throw new EngagementException('بعض العناصر لا تخص هذه المجموعة.');
                }

                $slots[] = $indexByWork[$id];
            }

            sort($slots);

            $order = array_map(fn ($row) => (int) $row->id, $rows);

            foreach ($slots as $k => $slotIndex) {
                $order[$slotIndex] = (int) $rows[$indexByWork[$ids[$k]]]->id;
            }

            $changed = $this->persist($rows, $order);

            if ($changed) {
                $collection->touch();
            }

            return ['changed' => $changed];
        });
    }

    /** @return array{changed: bool} */
    public function move(User $user, string $collectionPublicId, string $workPublicId, string $direction): array
    {
        $this->guard->assertCanInteract($user);

        if (! in_array($direction, ['up', 'down'], true) || ! Str::isUlid($workPublicId)) {
            throw new EngagementException('طلب الترتيب غير صالح.');
        }

        return DB::transaction(function () use ($user, $collectionPublicId, $workPublicId, $direction) {
            $collection = $this->collections->owned($user, $collectionPublicId, true);
            $rows = $this->ordered($collection->id);
            $index = null;

            foreach ($rows as $i => $row) {
                if ($row->work_public_id === $workPublicId) {
                    $index = $i;

                    break;
                }
            }

            if ($index === null) {
                throw new EngagementException('هذا العنصر ليس في المجموعة.');
            }

            $order = array_map(fn ($row) => (int) $row->id, $rows);
            $neighbor = $direction === 'up' ? $index - 1 : $index + 1;

            if ($neighbor >= 0 && $neighbor < count($order)) {
                [$order[$index], $order[$neighbor]] = [$order[$neighbor], $order[$index]];
            }

            $changed = $this->persist($rows, $order);

            if ($changed) {
                $collection->touch();
            }

            return ['changed' => $changed];
        });
    }

    /** @return list<object{id: int, position: int, work_public_id: string}> بالترتيب الحالي (position, id). */
    protected function ordered(int $collectionId): array
    {
        return DB::table('user_collection_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.user_collection_id', $collectionId)
            ->orderBy('i.position')->orderBy('i.id')
            ->select('i.id', 'i.position', 'w.public_id as work_public_id')
            ->get()->all();
    }

    /**
     * يكتب المواضع المطبَّعة 1..n بحسب $order (معرّفات عناصر بالترتيب الجديد)، للصفوف التي تغيّر موضعها فقط، بتحديث CASE واحد.
     *
     * @param  list<object{id: int, position: int}>  $rows
     * @param  list<int>  $order
     */
    protected function persist(array $rows, array $order): bool
    {
        $current = [];

        foreach ($rows as $row) {
            $current[(int) $row->id] = (int) $row->position;
        }

        $updates = [];

        foreach ($order as $i => $itemId) {
            $position = $i + 1;

            if (($current[$itemId] ?? null) !== $position) {
                $updates[$itemId] = $position;
            }
        }

        if ($updates === []) {
            return false;
        }

        $cases = '';
        $bindings = [];

        foreach ($updates as $itemId => $position) {
            $cases .= ' WHEN ? THEN ?';
            $bindings[] = $itemId;
            $bindings[] = $position;
        }

        $ids = array_keys($updates);

        DB::update('UPDATE user_collection_items SET position = CASE id'.$cases.' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')', array_merge($bindings, $ids));

        return true;
    }
}
