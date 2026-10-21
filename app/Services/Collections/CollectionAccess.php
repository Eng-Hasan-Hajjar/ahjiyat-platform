<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Services\Social\BlockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E35 - **بوابة القراءة العامة الوحيدة** للمجموعات (المشاهد ≠ المالك). كل سطح عام (الصفحة، البحث، الاكتشاف، ملف اللاعب، المحفوظات، الأرفف) يمرّ من هنا،
 * فقاعدة الظهور بمكان واحد ولا تتناقض الأسطح:
 *
 *   مجموعة تُقرأ علنًا ⟺ visibility ≠ private ∧ ¬is_moderation_hidden ∧ مالكها موثَّق ∧ غير مجمَّد ∧ لا حظر بأي اتجاه بينه وبين المشاهد.
 *   وتُدرَج (بحث/اكتشاف/ملف) فقط إن كانت public ∧ ملف المالك public (انظر قرار الخصوصية) ∧ فيها عدد كافٍ من الأعمال الظاهرة.
 *
 * قرار الخصوصية (موثَّق): لا تُنشر مجموعة «عامة» إلا من ملف شخصي عام، لأن الإدراج في البحث/الاكتشاف يُظهر اسم الأمين. «غير المدرجة» لا تشترط ذلك: الاسم يظهر في صفحتها
 * فقط لمن يحقّ له رؤية الملف (profileViewableBy) وإلا تبقى بنسبة محايدة. لو جعل المالك ملفه غير عام لاحقًا: تخرج مجموعاته من البحث/الاكتشاف/الملف فورًا (فحص حيّ)
 * ويبقى رابطها المباشر يعمل بنسبة محايدة (لا نغيّر مجموعته نفسها سرًّا).
 *
 * الكاش لا يتجاوز هذا الصنف أبدًا: القوائم المخزَّنة معرّفات فقط وتُعاد عبر listableQuery قبل العرض.
 */
class CollectionAccess
{
    public function __construct(protected BlockService $blocks) {}

    /** أساس الأسطح العامة: غير خاصة ∧ غير مخفية إشرافيًا ∧ مالك موثَّق غير مجمَّد (ضمّ واحد بأعمدة الأهلية فقط). */
    public function sharedQuery(): Builder
    {
        return UserCollection::query()
            ->join('users as owner', 'owner.id', '=', 'user_collections.user_id')
            ->where('user_collections.visibility', '!=', UserCollection::VISIBILITY_PRIVATE)
            ->where('user_collections.is_moderation_hidden', false)
            ->whereNotNull('owner.email_verified_at')
            ->where('owner.is_frozen', false);
    }

    /**
     * المجموعات المُدرَجة علنًا: public ∧ ملف المالك عام ∧ ≥ $minVisibleItems عملًا ظاهرًا ∧ بلا حظر مع المشاهد.
     * يُستخدم للبحث والاكتشاف وملف اللاعب والرفوف (اختيار الأمثل: ضمّ users + فحص عدّ مرتبط بحدّ أدنى صغير).
     */
    public function listableQuery(?User $viewer, int $minVisibleItems = 1): Builder
    {
        $query = $this->sharedQuery()
            ->where('user_collections.visibility', UserCollection::VISIBILITY_PUBLIC)
            ->where('owner.profile_visibility', User::VISIBILITY_PUBLIC);

        if ($viewer !== null) {
            $hidden = $this->blocks->hiddenIds($viewer);

            if ($hidden !== []) {
                $query->whereNotIn('user_collections.user_id', $hidden);
            }
        }

        return $this->withMinVisibleItems($query, max(1, $minVisibleItems));
    }

    /** يشترط ≥ $min عملًا ظاهرًا (is_visible) بالمجموعة. الحدّ 1 = EXISTS (الأرخص)؛ فوقه عدّ مرتبط على صفوف المجموعة (سقفها صغير). */
    public function withMinVisibleItems(Builder $query, int $min): Builder
    {
        $min = max(1, $min);

        if ($min === 1) {
            return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('user_collection_items as vi')
                ->join('creator_puzzles as vw', 'vw.id', '=', 'vi.creator_puzzle_id')
                ->whereColumn('vi.user_collection_id', 'user_collections.id')->where('vw.is_visible', true));
        }

        return $query->whereRaw(
            '(select count(*) from user_collection_items vi inner join creator_puzzles vw on vw.id = vi.creator_puzzle_id where vi.user_collection_id = user_collections.id and vw.is_visible = ?) >= ?',
            [true, $min]
        );
    }

    /**
     * المجموعة كما يراها هذا المشاهد عبر الرابط العام، أو null (= 404 بلا كشف السبب: غير موجودة/خاصة/مخفية/مالك مجمَّد/حظر).
     * المالك يرى مجموعاته المشتركة غير المخفية بهذا الرابط أيضًا (معاينة)، أما الخاصة فمسار المالك (collections.show) وحده.
     */
    public function findViewable(string $publicId, ?User $viewer): ?UserCollection
    {
        /** @var UserCollection|null $collection */
        $collection = $this->sharedQuery()->where('user_collections.public_id', $publicId)->select('user_collections.*')->first();

        if ($collection === null) {
            return null;
        }

        if ($viewer !== null && (int) $collection->user_id !== (int) $viewer->id && in_array((int) $collection->user_id, $this->blocks->hiddenIds($viewer), true)) {
            return null;
        }

        $collection->load('user:id,name,public_id,profile_visibility');

        return $collection;
    }

    /** هل يجوز لهذا المشاهد حفظ/الإبلاغ عن هذه المجموعة الآن؟ (مشتركة ظاهرة له). */
    public function isViewableBy(UserCollection $collection, ?User $viewer): bool
    {
        return $this->findViewable((string) $collection->public_id, $viewer) !== null;
    }

    /** نسبة الأمين: اسم + رابط الملف إن حقّ للمشاهد رؤية الملف، وإلا null (يعرض العرض تسمية محايدة). */
    public function attribution(UserCollection $collection, ?User $viewer): ?array
    {
        $owner = $collection->relationLoaded('user') ? $collection->user : $collection->user()->first(['id', 'name', 'public_id', 'profile_visibility']);

        if ($owner === null || ! $owner->profileViewableBy($viewer)) {
            return null;
        }

        return ['name' => (string) $owner->name, 'url' => route('players.show', $owner)];
    }

    /** عدد الأعمال الظاهرة الآن بالمجموعة. */
    public function visibleCount(int $collectionId): int
    {
        return (int) DB::table('user_collection_items as i')->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.user_collection_id', $collectionId)->where('w.is_visible', true)->count();
    }

    /**
     * أعداد الأعمال الظاهرة لدفعة مجموعات باستعلام تجميع واحد.
     *
     * @param  list<int>  $collectionIds
     * @return array<int, int>
     */
    public function visibleCounts(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        return DB::table('user_collection_items as i')->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->whereIn('i.user_collection_id', $collectionIds)->where('w.is_visible', true)
            ->groupBy('i.user_collection_id')->selectRaw('i.user_collection_id as id, COUNT(*) as c')->pluck('c', 'id')
            ->map(fn ($c) => (int) $c)->all();
    }

    /** مسار صورة الغلاف: أول عمل ظاهر (بترتيب المالك) له صورة. لا رفع ولا معالجة. null = بدون صورة (عنصر نائب). */
    public function coverPath(int $collectionId): ?string
    {
        $path = DB::table('user_collection_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->join('puzzles as p', 'p.id', '=', 'w.published_puzzle_id')
            ->where('i.user_collection_id', $collectionId)->where('w.is_visible', true)
            ->whereNotNull('p.image_path')->where('p.image_path', '!=', '')
            ->orderBy('i.position')->orderBy('i.id')
            ->value('p.image_path');

        return is_string($path) && $path !== '' ? $path : null;
    }
}
