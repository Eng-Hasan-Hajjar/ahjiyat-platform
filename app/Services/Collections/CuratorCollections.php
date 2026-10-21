<?php

namespace App\Services\Collections;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * E35 - قسم «مجموعات» بملف اللاعب العام (لا ملف أمين مستقل ولا CuratorProfile). المعروض فقط ما يجوز إدراجه علنًا الآن لهذا المشاهد (CollectionAccess::listableQuery):
 * عامة ∧ غير مخفية ∧ ملف الأمين عام ∧ فيها عمل ظاهر ∧ بلا حظر. «غير المدرجة» والخاصة لا تظهر بالملف أبدًا (حتى لمالكها: مجموعاته الخاصة في «مجموعاتي»).
 * استعلامان ثابتان (القائمة + أعداد الأعمال الظاهرة دفعة واحدة).
 */
class CuratorCollections
{
    public function __construct(protected CollectionAccess $access) {}

    /** @return Collection<int, object{public_id: string, title: string, description: ?string, saves_count: int, visible_count: int}> */
    public function forProfile(User $owner, ?User $viewer): Collection
    {
        $limit = max(1, (int) config('collections.profile.section_limit'));

        $rows = $this->access->listableQuery($viewer, 1)
            ->where('user_collections.user_id', $owner->id)
            ->select('user_collections.id', 'user_collections.public_id', 'user_collections.title', 'user_collections.description', 'user_collections.saves_count')
            ->orderByDesc('user_collections.saves_count')->orderByDesc('user_collections.updated_at')->orderByDesc('user_collections.id')
            ->limit($limit)->get();

        $counts = $this->access->visibleCounts($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $rows->map(fn ($r) => (object) [
            'public_id' => (string) $r->public_id,
            'title' => (string) $r->title,
            'description' => $r->description,
            'saves_count' => (int) $r->saves_count,
            'visible_count' => $counts[(int) $r->id] ?? 0,
        ]);
    }
}
