<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\User;
use App\Services\Engagement\EngagementCounters;
use App\Services\Engagement\EngagementGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * E36 - الإعجاب (نوع تفاعل وحيد) بتعليق. المستخدم موثَّق غير مجمَّد؛ التعليق **ظاهر لهذا المشاهد** (موضوع ظاهر، بلا حظر) وحيّ (لا إعجاب على محذوف أو مخفي)؛
 * لا إعجاب على تعليقك (تقليل مؤشرات الغرور)؛ لا إعجاب جديد على نقاش مقفل (القراءة فقط). الفرادة (مستخدم، تعليق) بقيد قاعدة بيانات: الإعجاب المكرر والإلغاء
 * المكرر آمنان (Idempotent) والعدّاد المجمَّع يتغيّر **فقط حين يتغيّر صفّ فعلًا** داخل معاملة. من أعجب خاص (لا واجهة تعرضه). لا أثر اقتصادي ولا إشعار لكل إعجاب.
 * إلغاء الإعجاب مسموح دائمًا (حتى على نقاش مقفل أو تعليق صار مخفيًا) ما دام الصف موجودًا.
 */
class DiscussionLikeService
{
    public function __construct(protected EngagementGuard $guard, protected DiscussionThreadService $threads) {}

    /** @return array{changed: bool, likes_count: int} */
    public function like(User $user, string $publicId): array
    {
        $this->assertEnabled();
        $this->guard->assertCanInteract($user);

        $context = $this->threads->contextFor($publicId, $user) ?? throw new ModelNotFoundException;
        $comment = $context->comment;

        if (! $comment->isLive()) {
            throw new DiscussionException('لا يمكن الإعجاب بهذا التعليق.');
        }

        if ((int) $comment->user_id === (int) $user->id) {
            throw new DiscussionException('لا يمكنك الإعجاب بتعليقك.');
        }

        if ($context->thread->is_locked) {
            throw new DiscussionException('تم إغلاق النقاش من قبل الإدارة.');
        }

        $changed = DB::transaction(function () use ($comment, $user) {
            $inserted = DB::table('discussion_comment_likes')->insertOrIgnore([[
                'user_id' => $user->id,
                'discussion_comment_id' => $comment->id,
                'created_at' => now(),
            ]]) === 1;

            if ($inserted) {
                EngagementCounters::adjust('discussion_comments', (int) $comment->id, ['likes_count' => 1]);
            }

            return $inserted;
        });

        return ['changed' => $changed, 'likes_count' => (int) DiscussionComment::query()->whereKey($comment->id)->value('likes_count')];
    }

    /** @return array{changed: bool, likes_count: int} */
    public function unlike(User $user, string $publicId): array
    {
        $this->assertEnabled();
        $this->guard->assertCanInteract($user);

        $context = $this->threads->contextFor($publicId, $user) ?? throw new ModelNotFoundException;
        $comment = $context->comment;

        $changed = DB::transaction(function () use ($comment, $user) {
            $deleted = DB::table('discussion_comment_likes')->where('user_id', $user->id)->where('discussion_comment_id', $comment->id)->delete() > 0;

            if ($deleted) {
                EngagementCounters::adjust('discussion_comments', (int) $comment->id, ['likes_count' => -1]);
            }

            return $deleted;
        });

        return ['changed' => $changed, 'likes_count' => (int) DiscussionComment::query()->whereKey($comment->id)->value('likes_count')];
    }

    protected function assertEnabled(): void
    {
        if (! (bool) config('discussions.enabled', true)) {
            throw new DiscussionException('النقاشات متوقفة مؤقتًا.');
        }
    }
}
