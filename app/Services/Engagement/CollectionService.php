<?php

namespace App\Services\Engagement;

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Rules\NoMarkup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E29 - المجموعات الشخصية (**خاصة فقط**: لا مشاركة عامة ولا فهرسة ولا بلاغات بهذه المرحلة، قرار مقصود).
 *
 *  - الملكية: كل قراءة/كتابة تحلّ المجموعة بمعرّفها العام **مع user_id المالك** (غير المالك = ModelNotFound = 404، لا 403 يكشف الوجود). لا IDOR.
 *  - العنوان والوصف نص عادي: تُطبَّع المسافات ثم يتحقق Validator بقواعد NoMarkup (نفس قواعد الطلب HTTP، مصدرها هنا وحده).
 *  - سقفان قابلان للضبط: عدد المجموعات للمستخدم وعدد العناصر بالمجموعة، يُفحصان تحت قفل صف المالك/المجموعة (لا تجاوز بالتزامن).
 *  - العضوية تشير للعمل الثابت؛ إخفاء العمل لا يحذفها (يعود الظهور بالاستعادة)، لكنها لا تُضاف لعمل غير ظاهر.
 *  - حذف المجموعة يحذف عضوياتها فقط (لا الأحجيات). لا أثر على الاقتصاد أو التقدّم.
 */
class CollectionService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** قواعد التحقق الوحيدة لعنوان/وصف المجموعة (يستعملها طلب HTTP والخدمة معًا). */
    public static function rules(): array
    {
        $c = (array) config('engagement.collections');

        return [
            'title' => ['required', 'string', 'min:'.(int) $c['title_min'], 'max:'.(int) $c['title_max'], new NoMarkup],
            'description' => ['nullable', 'string', 'max:'.(int) $c['description_max'], new NoMarkup],
        ];
    }

    public function create(User $user, string $title, ?string $description = null): UserCollection
    {
        $this->guard->assertCanInteract($user);
        $data = $this->normalize($title, $description);

        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);   // يُسلسل إنشاءات المستخدم الواحد: السقف لا يُتجاوز بالتزامن

            if (UserCollection::query()->where('user_id', $user->id)->count() >= (int) config('engagement.collections.max_per_user')) {
                throw new EngagementException('بلغت الحد الأقصى لعدد المجموعات ('.(int) config('engagement.collections.max_per_user').'). احذف مجموعة لإنشاء أخرى.');
            }

            $collection = new UserCollection;
            $collection->forceFill(['user_id' => $user->id] + $data)->save();

            return $collection;
        });
    }

    public function update(User $user, string $publicId, string $title, ?string $description = null): UserCollection
    {
        $this->guard->assertCanInteract($user);
        $data = $this->normalize($title, $description);
        $collection = $this->owned($user, $publicId);
        $collection->forceFill($data)->save();

        return $collection;
    }

    public function delete(User $user, string $publicId): void
    {
        $this->guard->assertCanInteract($user);
        $collection = $this->owned($user, $publicId);

        DB::transaction(function () use ($collection) {
            UserCollectionItem::query()->where('user_collection_id', $collection->id)->delete();
            $collection->delete();
        });
    }

    /** مجموعة المستخدم بمعرّفها العام، وإلا ModelNotFoundException (404) سواء لم توجد أو كانت لغيره. */
    public function owned(User $user, string $publicId, bool $lock = false): UserCollection
    {
        $query = UserCollection::query()->where('user_id', $user->id)->where('public_id', $publicId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array{changed: bool} */
    public function add(User $user, string $collectionPublicId, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $collectionPublicId, $work) {
            $collection = $this->owned($user, $collectionPublicId, true);
            $visible = (bool) CreatorPuzzle::query()->whereKey($work->id)->value('is_visible');

            if (! $visible) {
                throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
            }

            if (UserCollectionItem::query()->where('user_collection_id', $collection->id)->where('creator_puzzle_id', $work->id)->exists()) {
                return ['changed' => false];
            }

            if (UserCollectionItem::query()->where('user_collection_id', $collection->id)->count() >= (int) config('engagement.collections.max_items')) {
                throw new EngagementException('امتلأت هذه المجموعة (الحد الأقصى '.(int) config('engagement.collections.max_items').' أحجية).');
            }

            $item = new UserCollectionItem;
            $item->forceFill(['user_collection_id' => $collection->id, 'creator_puzzle_id' => $work->id, 'created_at' => now()])->save();
            $collection->touch();

            return ['changed' => true];
        });
    }

    /** إنشاء مجموعة سريع من صفحة الأحجية ثم إضافتها إليها (معاملة واحدة: لا مجموعة فارغة تُترك عند فشل الإضافة). */
    public function createAndAdd(User $user, string $title, CreatorPuzzle $work): UserCollection
    {
        return DB::transaction(function () use ($user, $title, $work) {
            $collection = $this->create($user, $title);
            $this->add($user, $collection->public_id, $work);

            return $collection;
        });
    }

    public function remove(User $user, string $collectionPublicId, string $workPublicId): bool
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $collectionPublicId, $workPublicId) {
            $collection = $this->owned($user, $collectionPublicId, true);
            $workId = CreatorPuzzle::query()->where('public_id', $workPublicId)->value('id');

            if ($workId === null) {
                return false;
            }

            $deleted = UserCollectionItem::query()->where('user_collection_id', $collection->id)->where('creator_puzzle_id', $workId)->delete();

            if ($deleted > 0) {
                $collection->touch();
            }

            return $deleted > 0;
        });
    }

    /**
     * مجموعات المستخدم لمنتقي «أضف إلى مجموعة»: استعلام واحد (has_work بمقطع فرعي) بلا استعلام لكل مجموعة.
     *
     * @return Collection<int, UserCollection>
     */
    public function pickerFor(User $user, int $workId): Collection
    {
        return UserCollection::query()->where('user_id', $user->id)
            ->select('id', 'public_id', 'title')
            ->withExists(['items as has_work' => fn ($q) => $q->where('creator_puzzle_id', $workId)])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit((int) config('engagement.collections.max_per_user'))
            ->get();
    }

    /** @return array{title: string, description: string|null} */
    protected function normalize(string $title, ?string $description): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $description = $description === null ? null : trim((string) preg_replace('/[ \t]+/u', ' ', $description));
        $description = ($description === '' || $description === null) ? null : $description;

        Validator::make(['title' => $title, 'description' => $description], self::rules(), [], ['title' => 'عنوان المجموعة', 'description' => 'وصف المجموعة'])->validate();

        return ['title' => $title, 'description' => $description];
    }
}
