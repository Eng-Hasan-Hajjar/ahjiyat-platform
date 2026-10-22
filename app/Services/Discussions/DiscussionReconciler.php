<?php

namespace App\Services\Discussions;

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\UserCollection;
use Illuminate\Support\Facades\DB;

/**
 * E36 - مصالحة النقاشات مع صفوفها (الصفوف هي المصدر الوحيد للحقيقة). على الطلب فقط (لا جدولة، لا مسح كامل مجدول).
 *
 *  - عدّادات (تُصلَح): discussion_threads.comments_count = التعليقات الحيّة (visible ∧ غير محذوفة)، و discussion_comments.likes_count = صفوف الإعجاب.
 *    الإصلاح يعيد الحساب **تحت قفل الصف** ويكتب القيمة المطلقة فلا يتعارض مع كتابة حيّة. dry-run يكشف فقط.
 *  - شذوذات هيكلية (تُكشَف ولا تُصلَح تلقائيًا؛ القرار بشري): ردّ أبوه ردّ (عمق > 1)، بلاغ مفتوح بلا open_marker أو مغلق ومعه open_marker، نقاش يتيم (الكيان لم يعد موجودًا).
 *  - النقاش اليتيم الوحيد الذي يُعالَج تلقائيًا: --purge-orphans يحذفه كاملًا (لا يُعرض أصلًا لأن الكيان لا يُحلّ). يُعاد تقييم الشرط عند الحذف.
 *  - لا يلمس أي اقتصاد أو تقدّم؛ يكتب أعمدة العدّادات حصرًا (وحذف اليتيم عند الطلب).
 */
class DiscussionReconciler
{
    public function __construct(protected DiscussionCleanup $cleanup) {}

    /**
     * @return array{threads: int, comments: int, drift: list<array{table: string, id: int, column: string, stored: int, actual: int}>, fixed: int, anomalies: list<array{type: string, id: int, detail: string}>, purged: int}
     */
    public function run(bool $dryRun = true, int $chunk = 500, bool $purgeOrphans = false): array
    {
        $chunk = max(10, $chunk);
        $drift = [];
        $fixed = 0;
        $threads = 0;
        $comments = 0;

        DiscussionThread::query()->select('id', 'comments_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$threads, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $threads += count($ids);
            $live = DB::table('discussion_comments')->whereIn('discussion_thread_id', $ids)->where('status', DiscussionComment::STATUS_VISIBLE)->whereNull('deleted_at')
                ->groupBy('discussion_thread_id')->selectRaw('discussion_thread_id as id, COUNT(*) as c')->pluck('c', 'id');

            foreach ($rows as $row) {
                $actual = (int) ($live[$row->id] ?? 0);

                if ((int) $row->comments_count !== $actual) {
                    $drift[] = ['table' => 'discussion_threads', 'id' => (int) $row->id, 'column' => 'comments_count', 'stored' => (int) $row->comments_count, 'actual' => $actual];

                    if (! $dryRun) {
                        $this->repairThread((int) $row->id);
                        $fixed++;
                    }
                }
            }
        });

        DiscussionComment::query()->select('id', 'likes_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$comments, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $comments += count($ids);
            $likes = DB::table('discussion_comment_likes')->whereIn('discussion_comment_id', $ids)->groupBy('discussion_comment_id')
                ->selectRaw('discussion_comment_id as id, COUNT(*) as c')->pluck('c', 'id');

            foreach ($rows as $row) {
                $actual = (int) ($likes[$row->id] ?? 0);

                if ((int) $row->likes_count !== $actual) {
                    $drift[] = ['table' => 'discussion_comments', 'id' => (int) $row->id, 'column' => 'likes_count', 'stored' => (int) $row->likes_count, 'actual' => $actual];

                    if (! $dryRun) {
                        $this->repairComment((int) $row->id);
                        $fixed++;
                    }
                }
            }
        });

        $anomalies = $this->anomalies();
        $purged = 0;

        if ($purgeOrphans && ! $dryRun) {
            $orphanIds = $this->orphanThreadIds();
            $purged = $this->cleanup->deleteThreads($orphanIds);
            $anomalies = array_values(array_filter($anomalies, fn (array $a) => $a['type'] !== 'orphan_thread'));
        }

        return ['threads' => $threads, 'comments' => $comments, 'drift' => $drift, 'fixed' => $fixed, 'anomalies' => $anomalies, 'purged' => $purged];
    }

    /** @return list<array{type: string, id: int, detail: string}> */
    public function anomalies(): array
    {
        $out = [];

        $deep = DB::table('discussion_comments as c')->join('discussion_comments as p', 'p.id', '=', 'c.parent_id')->whereNotNull('p.parent_id')->orderBy('c.id')->limit(50)->get(['c.id']);

        foreach ($deep as $row) {
            $out[] = ['type' => 'deep_reply', 'id' => (int) $row->id, 'detail' => 'ردّ أبوه ردّ (العمق المسموح مستوى واحد)'];
        }

        $openNoMarker = DB::table('discussion_comment_reports')->where('status', 'open')->whereNull('open_marker')->orderBy('id')->limit(50)->get(['id']);

        foreach ($openNoMarker as $row) {
            $out[] = ['type' => 'open_report_without_marker', 'id' => (int) $row->id, 'detail' => 'بلاغ مفتوح بلا open_marker (قيد البلاغ المفتوح الواحد لا يحميه)'];
        }

        $closedWithMarker = DB::table('discussion_comment_reports')->where('status', '!=', 'open')->whereNotNull('open_marker')->orderBy('id')->limit(50)->get(['id']);

        foreach ($closedWithMarker as $row) {
            $out[] = ['type' => 'closed_report_with_marker', 'id' => (int) $row->id, 'detail' => 'بلاغ مغلق ما زال open_marker (يمنع بلاغًا جديدًا من المستخدم نفسه)'];
        }

        foreach ($this->orphanThreadIds(50) as $id) {
            $out[] = ['type' => 'orphan_thread', 'id' => $id, 'detail' => 'نقاش كيانه لم يعد موجودًا (discussions:reconcile --purge-orphans يحذفه)'];
        }

        return $out;
    }

    /** @return list<int> */
    public function orphanThreadIds(?int $limit = null): array
    {
        $tables = [
            DiscussionThread::TYPE_COMMUNITY_PUZZLE => (new CreatorPuzzle)->getTable(),
            DiscussionThread::TYPE_CREATOR_SERIES => (new CreatorSeries)->getTable(),
            DiscussionThread::TYPE_PUBLIC_COLLECTION => (new UserCollection)->getTable(),
        ];
        $ids = [];

        foreach ($tables as $type => $table) {
            $query = DB::table('discussion_threads')->where('subject_type', $type)
                ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from($table)->whereColumn($table.'.id', 'discussion_threads.subject_id'))
                ->orderBy('discussion_threads.id');

            if ($limit !== null) {
                $query->limit($limit);
            }

            foreach ($query->pluck('discussion_threads.id') as $id) {
                $ids[] = (int) $id;
            }
        }

        // نوع مجهول (بعد إزالة نوع مدعوم) = يتيم أيضًا.
        $unknown = DB::table('discussion_threads')->whereNotIn('subject_type', array_keys($tables))->orderBy('id');

        if ($limit !== null) {
            $unknown->limit($limit);
        }

        foreach ($unknown->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        sort($ids);

        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    protected function repairThread(int $id): void
    {
        DB::transaction(function () use ($id) {
            $locked = DiscussionThread::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $actual = DiscussionComment::query()->where('discussion_thread_id', $id)->live()->count();
            DiscussionThread::query()->whereKey($id)->update(['comments_count' => $actual]);
        });
    }

    protected function repairComment(int $id): void
    {
        DB::transaction(function () use ($id) {
            $locked = DiscussionComment::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $actual = (int) DB::table('discussion_comment_likes')->where('discussion_comment_id', $id)->count();
            DiscussionComment::query()->whereKey($id)->update(['likes_count' => $actual]);
        });
    }
}
