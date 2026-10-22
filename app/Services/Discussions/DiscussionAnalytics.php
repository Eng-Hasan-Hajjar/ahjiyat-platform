<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E36 - تحليلات النقاشات **للإدارة فقط**: أرقام مجمَّعة محدودة ومخزَّنة (CachedSnapshot، discussions.analytics.cache_seconds)، **بلا أي بيانات شخصية**:
 * لا أسماء كاتبين ولا مبلِّغين ولا نصوص. لا تحليلات لمبدعي المحتوى في E36 (قرار مقصود): مالك المحتوى يرى العدّاد العلني فقط؛ أي لوحة له ستكشف سلوك المعلّقين.
 * الكاش للعرض الإداري فقط ولا يدخل في أي قرار (إخفاء/حظر/قفل تُقرأ حيّة من الخدمات).
 */
class DiscussionAnalytics
{
    public const CACHE_KEY = 'discussions.analytics.admin';

    /** @return array{threads: int, locked_threads: int, live_comments: int, replies: int, hidden: int, deleted: int, likes: int, open_reports: int, by_type: array<string, int>, per_day: array<string, int>, generated_at: string} */
    public function admin(): array
    {
        return CachedSnapshot::remember(self::CACHE_KEY, (int) config('discussions.analytics.cache_seconds'), fn () => $this->compute());
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // الكاش اختياري.
        }
    }

    /** @return array<string, mixed> */
    protected function compute(): array
    {
        $byType = array_fill_keys(array_keys(DiscussionThread::TYPES), 0);

        foreach (DiscussionThread::query()->groupBy('subject_type')->selectRaw('subject_type, COUNT(*) as c')->pluck('c', 'subject_type') as $type => $count) {
            $byType[(string) $type] = (int) $count;
        }

        $days = max(1, min(60, (int) config('discussions.analytics.days')));
        $since = now()->subDays($days - 1)->startOfDay();
        $perDay = [];

        for ($i = 0; $i < $days; $i++) {
            $perDay[$since->copy()->addDays($i)->toDateString()] = 0;
        }

        DiscussionComment::query()->where('created_at', '>=', $since)->orderBy('created_at')->select('created_at')->toBase()->chunk(1000, function ($rows) use (&$perDay) {
            foreach ($rows as $row) {
                $day = substr((string) $row->created_at, 0, 10);

                if (array_key_exists($day, $perDay)) {
                    $perDay[$day]++;
                }
            }
        });

        return [
            'threads' => DiscussionThread::query()->count(),
            'locked_threads' => DiscussionThread::query()->where('is_locked', true)->count(),
            'live_comments' => DiscussionComment::query()->live()->count(),
            'replies' => DiscussionComment::query()->live()->whereNotNull('parent_id')->count(),
            'hidden' => DiscussionComment::query()->where('status', DiscussionComment::STATUS_HIDDEN)->whereNull('deleted_at')->count(),
            'deleted' => DiscussionComment::query()->whereNotNull('deleted_at')->count(),
            'likes' => (int) DB::table('discussion_comment_likes')->count(),
            'open_reports' => DiscussionCommentReport::query()->where('status', DiscussionCommentReport::STATUS_OPEN)->count(),
            'by_type' => $byType,
            'per_day' => $perDay,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
