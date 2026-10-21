<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionSave;
use App\Services\Engagement\EngagementCounters;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementGuard;
use App\Services\Social\BlockService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * E35 - حفظ المجموعات العامة/غير المدرجة. **بلا سلطة اقتصادية** (لا جواهر ولا XP ولا مهام ولا سلسلة) ولا إشعار لأي طرف.
 *
 *  - هوية من حفظ **خاصة**: لا واجهة تعرضها لأحد (ولا للمالك). العدّاد المجمَّع saves_count وحده علني (حافظون مميَّزون بقيد فريد).
 *  - لا حفظ لمجموعتك. الحفظ يشترط مجموعة ظاهرة لهذا المشاهد الآن (CollectionAccess: عامة/غير مدرجة، غير مخفية، مالك سليم، بلا حظر) وإلا 404 بلا كشف.
 *  - تكرار الحفظ idempotent ولا يضاعف العدّاد. الإزالة مسموحة دائمًا حتى لو صارت المجموعة غير متاحة (ينظّف المستخدم قائمته) ولا تكشف شيئًا عن المجموعة.
 *  - سقف لما يحفظه المستخدم (collections.saves.max_per_user). العدّاد يُحدَّث ذريًّا تحت قفل صف المجموعة فلا ضياع تحت التزامن.
 */
class CollectionSaveService
{
    public function __construct(protected EngagementGuard $guard, protected CollectionAccess $access, protected BlockService $blocks) {}

    /** @return array{changed: bool, count: int} */
    public function save(User $user, string $publicId): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $publicId) {
            $locked = UserCollection::query()->where('public_id', $publicId)->lockForUpdate()->first();

            if ($locked === null || $this->access->findViewable($publicId, $user) === null) {
                throw new ModelNotFoundException;
            }

            if ((int) $locked->user_id === (int) $user->id) {
                throw new EngagementException('لا يمكنك حفظ مجموعتك.');
            }

            if (UserCollectionSave::query()->where('user_id', $user->id)->where('user_collection_id', $locked->id)->exists()) {
                return ['changed' => false, 'count' => $this->count($locked->id)];
            }

            if (UserCollectionSave::query()->where('user_id', $user->id)->count() >= (int) config('collections.saves.max_per_user')) {
                throw new EngagementException('بلغت الحد الأقصى للمجموعات المحفوظة ('.(int) config('collections.saves.max_per_user').'). أزل بعضها لحفظ المزيد.');
            }

            $row = new UserCollectionSave;
            $row->forceFill(['user_id' => $user->id, 'user_collection_id' => $locked->id, 'created_at' => now()])->save();
            EngagementCounters::adjust('user_collections', $locked->id, ['saves_count' => 1]);

            return ['changed' => true, 'count' => $this->count($locked->id)];
        });
    }

    /** @return array{changed: bool, count: int} */
    public function remove(User $user, string $publicId): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $publicId) {
            $locked = UserCollection::query()->where('public_id', $publicId)->lockForUpdate()->first(['id']);

            if ($locked === null) {
                return ['changed' => false, 'count' => 0];
            }

            $deleted = UserCollectionSave::query()->where('user_id', $user->id)->where('user_collection_id', $locked->id)->delete();

            if ($deleted > 0) {
                EngagementCounters::adjust('user_collections', $locked->id, ['saves_count' => -1]);
            }

            return ['changed' => $deleted > 0, 'count' => $this->count($locked->id)];
        });
    }

    /**
     * أي من هذه المجموعات (معرّفات داخلية) محفوظ للمشاهد؟ استعلام واحد لدفعة كاملة (لا استعلام لكل بطاقة). خاص بالمشاهد: لا كاش مشترك.
     *
     * @param  list<int>  $collectionIds
     * @return array<int, true>
     */
    public function savedAmong(?User $viewer, array $collectionIds): array
    {
        if ($viewer === null || $collectionIds === []) {
            return [];
        }

        return array_fill_keys(UserCollectionSave::query()->where('user_id', $viewer->id)->whereIn('user_collection_id', $collectionIds)->pluck('user_collection_id')->all(), true);
    }

    /**
     * صفحة «المجموعات المحفوظة» للمالك فقط (user_id الجلسة): كل صف إما متاح (بيانات عرض) أو placeholder محايد بلا عنوان ولا مالك ولا سبب.
     * الإتاحة تُحسب حيًّا من نفس قاعدة CollectionAccess بالدفعة: عدم الإتاحة = خاصة/مخفية/مالك مجمَّد أو غير موثَّق/حظر.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function savedPage(User $user, int $perPage): LengthAwarePaginator
    {
        $page = UserCollectionSave::query()->where('user_collection_saves.user_id', $user->id)
            ->join('user_collections as c', 'c.id', '=', 'user_collection_saves.user_collection_id')
            ->join('users as owner', 'owner.id', '=', 'c.user_id')
            ->select([
                'user_collection_saves.id as save_id', 'c.id as collection_id', 'c.public_id', 'c.title', 'c.description', 'c.visibility', 'c.is_moderation_hidden', 'c.saves_count',
                'c.user_id as owner_id', 'owner.name as owner_name', 'owner.public_id as owner_public_id', 'owner.profile_visibility as owner_profile_visibility',
                'owner.email_verified_at as owner_verified_at', 'owner.is_frozen as owner_frozen',
            ])
            ->orderByDesc('user_collection_saves.id')
            ->paginate($perPage)->withQueryString();

        $blocked = array_flip($this->blocks->hiddenIds($user));
        $available = $page->getCollection()->filter(fn ($r) => $this->isAvailable($r, $blocked))->pluck('collection_id')->map(fn ($id) => (int) $id)->all();
        $counts = $this->access->visibleCounts($available);

        return $page->through(function ($r) use ($blocked, $counts) {
            if (! $this->isAvailable($r, $blocked)) {
                return ['available' => false, 'public_id' => $r->public_id];
            }

            $ownerViewable = $r->owner_profile_visibility === User::VISIBILITY_PUBLIC || ($r->owner_profile_visibility === User::VISIBILITY_MEMBERS);

            return [
                'available' => true,
                'public_id' => $r->public_id,
                'title' => $r->title,
                'description' => $r->description,
                'saves_count' => (int) $r->saves_count,
                'visible_count' => $counts[(int) $r->collection_id] ?? 0,
                'owner_name' => $ownerViewable ? $r->owner_name : null,
                'owner_url' => $ownerViewable ? route('players.show', $r->owner_public_id) : null,
            ];
        });
    }

    /** @param array<int, int|string> $blocked مقلوب hiddenIds (id => index) */
    protected function isAvailable(object $row, array $blocked): bool
    {
        return $row->visibility !== UserCollection::VISIBILITY_PRIVATE
            && ! (bool) $row->is_moderation_hidden
            && $row->owner_verified_at !== null
            && ! (bool) $row->owner_frozen
            && ! array_key_exists((int) $row->owner_id, $blocked);
    }

    protected function count(int $collectionId): int
    {
        return (int) DB::table('user_collections')->where('id', $collectionId)->value('saves_count');
    }
}
