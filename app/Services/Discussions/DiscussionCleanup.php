<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Engagement\EngagementCounters;
use Illuminate\Support\Facades\DB;

/**
 * E36 - تنظيف النقاشات عند حذف حساب أو حذف كيان.
 *
 *  - purgeUser: (1) إعجابات المستخدم تُحذف مع إنقاص likes_count العلني (لا رقم ينسب لمن لم يعد موجودًا)؛ (2) تعليقاته تتحوّل إلى **لحود محذوفة** (الجسم يُفرَّغ، deleted_at)
 *    وعدّاد النقاش يُنقَص بالحيّ منها فقط، فلا يبقى نص منسوب أو مجهول الهوية بعد الحذف (user_id يصير NULL بقيد القاعدة)؛ (3) بلاغات تعليقاته تُحذف (الدليل لا معنى له بلا حساب)؛
 *    (4) نقاشات مجموعاته العامة تُحذف كاملة قبل حذف المجموعات. بلاغاته هو على الآخرين تبقى بلا هوية (reporter_id → NULL بالقاعدة).
 *  - forSubject: حذف الكيان يحذف نقاشه كاملًا (تعليقات + إعجابات + بلاغات بالتتابع). الأعمال والسلاسل لا تُحذف من المنصة (تُخفى فقط)، فالمستدعي الفعلي مجموعة تُحذف.
 *  - لا يُستدعى من مسار الأدوار/الإشراف. يُستدعى من مستمع deleting على User (قبل EngagementCleanup حتى تبقى المجموعات قابلة للحل) ومن CollectionService::delete.
 *  - فشله لا يمنع الحذف (المستمع يلتقط الاستثناء)؛ ما يتبقى يلتقطه discussions:reconcile (نقاش يتيم/عدّاد).
 */
class DiscussionCleanup
{
    public function purgeUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            DB::table('discussion_comment_likes')->where('user_id', $user->id)->select('id', 'discussion_comment_id')->chunkById(500, function ($rows) {
                foreach ($rows->groupBy('discussion_comment_id') as $commentId => $group) {
                    EngagementCounters::adjust('discussion_comments', (int) $commentId, ['likes_count' => -$group->count()]);
                }

                DB::table('discussion_comment_likes')->whereIn('id', $rows->pluck('id'))->delete();
            });

            // لحود محذوفة بدل بقاء نص منسوب. نحتسب الحيّ لكل نقاش قبل التفريغ.
            DiscussionComment::query()->where('user_id', $user->id)->select('id', 'discussion_thread_id', 'status', 'deleted_at')->chunkById(500, function ($rows) {
                $live = $rows->filter(fn (DiscussionComment $c) => $c->status === DiscussionComment::STATUS_VISIBLE && $c->deleted_at === null);

                foreach ($live->groupBy('discussion_thread_id') as $threadId => $group) {
                    EngagementCounters::adjust('discussion_threads', (int) $threadId, ['comments_count' => -$group->count()]);
                }

                $ids = $rows->pluck('id')->all();
                DiscussionCommentReport::query()->whereIn('discussion_comment_id', $ids)->delete();
                DiscussionComment::query()->whereIn('id', $ids)->whereNull('deleted_at')->update(['body' => '', 'deleted_at' => now()]);
                DiscussionComment::query()->whereIn('id', $ids)->whereNotNull('deleted_at')->update(['body' => '']);
            });

            $this->forCollections(UserCollection::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        });
    }

    public function forSubject(string $type, int $subjectId): int
    {
        return $this->deleteThreads(DiscussionThread::query()->where('subject_type', $type)->where('subject_id', $subjectId)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** @param  list<int>  $collectionIds */
    public function forCollections(array $collectionIds): int
    {
        if ($collectionIds === []) {
            return 0;
        }

        return $this->deleteThreads(DiscussionThread::query()->where('subject_type', DiscussionThread::TYPE_PUBLIC_COLLECTION)->whereIn('subject_id', $collectionIds)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** @param  list<int>  $threadIds */
    public function deleteThreads(array $threadIds): int
    {
        if ($threadIds === []) {
            return 0;
        }

        DB::transaction(function () use ($threadIds) {
            // الردود أولًا ثم الجذور (النقاش يتتابع بقيد القاعدة؛ هذا ترتيب صريح لا يعتمد على عمق التتابع الذاتي).
            DiscussionComment::query()->whereIn('discussion_thread_id', $threadIds)->whereNotNull('parent_id')->delete();
            DiscussionComment::query()->whereIn('discussion_thread_id', $threadIds)->delete();
            DiscussionThread::query()->whereIn('id', $threadIds)->delete();
        });

        return count($threadIds);
    }
}
