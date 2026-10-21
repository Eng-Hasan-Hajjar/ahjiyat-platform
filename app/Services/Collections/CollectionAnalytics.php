<?php

namespace App\Services\Collections;

use App\Models\EditorialShelf;
use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Models\UserCollectionSave;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E35 - تحليلات المجموعات.
 *
 *  - للمالك (forOwner): مجمَّعة فقط على مجموعاته هو: الظهور، عدد الحفظ (رقم مجمَّع)، عدد الأعمال الظاهرة، حالة الإخفاء. **لا هوية حافظ ولا مبلِّغ ولا عدد بلاغات ولا مشاهدات**
 *    (لا تتبّع مشاهدات أصلًا). استعلامان ثابتان مهما كثرت المجموعات (سقفها max_per_user).
 *  - للإدارة (admin): أرقام مجمَّعة محدودة ومخزَّنة (CachedSnapshot، collections.analytics.cache_seconds) بلا أي بيانات شخصية؛ قائمة الأعلى حفظًا بعدد محدود وبعناوين فقط.
 */
class CollectionAnalytics
{
    public const CACHE_KEY = 'collections.analytics.admin';

    public function __construct(protected CollectionAccess $access) {}

    /** @return list<array{public_id: string, title: string, visibility: string, saves_count: int, visible_count: int, hidden: bool, reason: ?string}> */
    public function forOwner(User $owner): array
    {
        $rows = UserCollection::query()->where('user_id', $owner->id)
            ->select('id', 'public_id', 'title', 'visibility', 'saves_count', 'is_moderation_hidden', 'moderation_reason')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit((int) config('engagement.collections.max_per_user'))->get();

        $counts = $this->access->visibleCounts($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $rows->map(fn ($r) => [
            'public_id' => (string) $r->public_id,
            'title' => (string) $r->title,
            'visibility' => (string) $r->visibility,
            'saves_count' => (int) $r->saves_count,
            'visible_count' => $counts[(int) $r->id] ?? 0,
            'hidden' => (bool) $r->is_moderation_hidden,
            'reason' => $r->is_moderation_hidden ? $r->moderation_reason : null,
        ])->values()->all();
    }

    /** @return array{by_visibility: array<string, int>, hidden: int, saves_total: int, open_reports: int, shelves: array<string, int>, top: list<array{title: string, saves_count: int}>, generated_at: string} */
    public function admin(): array
    {
        return CachedSnapshot::remember(self::CACHE_KEY, (int) config('collections.analytics.cache_seconds'), fn () => $this->computeAdmin());
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // الكاش اختياري.
        }
    }

    protected function computeAdmin(): array
    {
        $byVisibility = array_fill_keys(UserCollection::VISIBILITIES, 0);

        foreach (UserCollection::query()->groupBy('visibility')->selectRaw('visibility, COUNT(*) as c')->pluck('c', 'visibility') as $visibility => $count) {
            $byVisibility[(string) $visibility] = (int) $count;
        }

        $shelves = array_fill_keys(array_keys(EditorialShelf::STATUSES), 0);

        foreach (EditorialShelf::query()->groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status') as $status => $count) {
            $shelves[(string) $status] = (int) $count;
        }

        return [
            'by_visibility' => $byVisibility,
            'hidden' => UserCollection::query()->where('is_moderation_hidden', true)->count(),
            'saves_total' => UserCollectionSave::query()->count(),
            'open_reports' => UserCollectionReport::query()->where('status', UserCollectionReport::STATUS_OPEN)->count(),
            'shelves' => $shelves,
            'top' => DB::table('user_collections')->where('visibility', UserCollection::VISIBILITY_PUBLIC)->where('is_moderation_hidden', false)
                ->orderByDesc('saves_count')->orderByDesc('id')->limit((int) config('collections.analytics.top_limit'))
                ->get(['title', 'saves_count'])->map(fn ($r) => ['title' => (string) $r->title, 'saves_count' => (int) $r->saves_count])->all(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
