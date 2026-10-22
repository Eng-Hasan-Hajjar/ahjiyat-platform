<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Engagement\EngagementCounters;
use App\Services\Engagement\EngagementGuard;
use App\Services\Social\BlockService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E36 - إنشاء التعليق والرد عليه وتعديله وحذفه. **المسار الوحيد** لكتابة تعليق (لا كتابة من المتحكمات). كل القرارات تُقرأ حيّة من القاعدة لحظة الفعل.
 *
 *  - المشارك: بريد موثَّق وغير مجمَّد (EngagementGuard، قراءة حيّة) + مفتاح DISCUSSIONS_ENABLED. الطلب يحمل النص ومعرّف الأب فقط؛ user_id/status/likes_count من الخدمة وحدها.
 *  - الموضوع ظاهر للمشاهد (السجل) والنقاش مفتوح (يُعاد فحص القفل **تحت قفل صف النقاش** داخل المعاملة فلا سباق مع قفل المشرف).
 *  - الحظر: لا تعليق على محتوى من بينك وبينه حظر بأي اتجاه (نفس مفهوم E16 لـ«لا تفاعل»)، ولا ردّ على فرع كاتبه محظور. الرسالة عامة لا تكشف اتجاه الحظر.
 *  - العمق: جذر + مستوى ردود واحد. الرد على ردّ يُلحَق بجذره (لا عمق ثانٍ أبدًا). الأب من النقاش نفسه (والقاعدة تفرضه بمفتاح مركّب) وحيّ، وجذره حيّ:
 *    لا ردّ على تعليق مخفي إشرافيًا ولا على جذر محذوف (اللحد يبقى للردود القائمة فقط).
 *  - التعديل: صاحب التعليق وحده، تعليق حيّ، نقاش مفتوح، داخل النافذة (DISCUSSIONS_EDIT_WINDOW_MINUTES من إنشائه). لا يتجاوز مخفيًّا إشرافيًا. نسخة الدليل محفوظة بالبلاغ لا هنا.
 *  - الحذف: ناعم (deleted_at) مع تفريغ النص (خصوصية). جذر بلا ردود يختفي، وجذر بردود يظهر لحدًا محايدًا (DiscussionReader). لا حذف لتعليق مخفي إشرافيًا (الدليل للإشراف).
 *  - comments_count للنقاش يُعدَّل ذريًا في المعاملة نفسها (+1 إنشاء، −1 حذف)؛ مصالحته discussions:reconcile.
 *  - لا أثر اقتصادي/تقدّمي إطلاقًا: لا جواهر ولا XP ولا مهام ولا سلسلة ولا إحالات ولا منافسة. الإشعارات بعد الـcommit وفشلها لا يلمس التعليق.
 */
class DiscussionCommentService
{
    public function __construct(
        protected EngagementGuard $guard,
        protected DiscussionThreadService $threads,
        protected BlockService $blocks,
        protected DiscussionNotifier $notifier,
    ) {}

    public function create(User $user, DiscussionSubject $subject, string $rawBody, ?string $parentPublicId = null): DiscussionComment
    {
        $this->assertEnabled();
        $this->guard->assertCanInteract($user);

        $body = DiscussionBody::prepare($rawBody);
        $hidden = $this->blocks->hiddenIds($user);

        if ($subject->ownerId !== (int) $user->id && in_array($subject->ownerId, $hidden, true)) {
            throw new DiscussionException('لا يمكنك المشاركة في هذا النقاش حاليًا.');
        }

        $thread = $this->threads->findFor($subject);
        $parent = null;
        $root = null;

        if ($parentPublicId !== null && $parentPublicId !== '') {
            $parent = $thread !== null && Str::isUlid($parentPublicId)
                ? DiscussionComment::query()->where('public_id', Str::upper($parentPublicId))->where('discussion_thread_id', $thread->id)->first()
                : null;
            $root = $parent === null ? null : ($parent->parent_id === null ? $parent : DiscussionComment::query()->find($parent->parent_id));

            if ($parent === null || $root === null) {
                throw new DiscussionException('التعليق الذي تردّ عليه غير متاح.');
            }

            if (! $parent->isLive() || ! $root->isLive()) {
                throw new DiscussionException('لا يمكن الرد على هذا التعليق.');
            }

            foreach ([$parent->user_id, $root->user_id] as $authorId) {
                if ($authorId !== null && in_array((int) $authorId, $hidden, true)) {
                    throw new DiscussionException('لا يمكنك الرد على هذا التعليق حاليًا.');
                }
            }
        }

        $window = (int) config('discussions.duplicate_window_seconds');

        if ($window > 0 && $thread !== null && DiscussionComment::query()->where('discussion_thread_id', $thread->id)->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds($window))->where('body', $body)->exists()) {
            throw new DiscussionException('أرسلت هذا النص نفسه للتو. انتظر قليلًا قبل تكراره.');
        }

        $thread ??= $this->threads->firstOrCreate($subject);

        $comment = DB::transaction(function () use ($thread, $user, $body, $parent, $root) {
            $locked = DiscussionThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_locked) {
                throw new DiscussionException('تم إغلاق النقاش من قبل الإدارة.');
            }

            if ($parent !== null && $root !== null) {
                $ids = array_values(array_unique([(int) $parent->id, (int) $root->id]));
                $fresh = DiscussionComment::query()->whereIn('id', $ids)->lockForUpdate()->get();

                if ($fresh->count() !== count($ids) || $fresh->contains(fn (DiscussionComment $c) => ! $c->isLive())) {
                    throw new DiscussionException('لا يمكن الرد على هذا التعليق.');
                }
            }

            $comment = new DiscussionComment;
            $comment->forceFill([
                'discussion_thread_id' => $locked->id,
                'user_id' => $user->id,
                'parent_id' => $root?->id,
                'body' => $body,
                'status' => DiscussionComment::STATUS_VISIBLE,
            ])->save();

            EngagementCounters::adjust('discussion_threads', (int) $locked->id, ['comments_count' => 1]);
            DiscussionThread::query()->whereKey($locked->id)->update(['last_activity_at' => now()]);

            return $comment;
        });

        $this->notifier->created($comment, $root, $subject);

        return $comment;
    }

    public function edit(User $user, string $publicId, string $rawBody): DiscussionComment
    {
        $this->assertEnabled();
        $this->guard->assertCanInteract($user);

        $context = $this->threads->contextFor($publicId, $user) ?? throw new ModelNotFoundException;
        $this->assertAuthor($context->comment, $user, 'تعديل');

        if (! $context->comment->isLive()) {
            throw new DiscussionException('لا يمكن تعديل هذا التعليق.');
        }

        if ($context->thread->is_locked) {
            throw new DiscussionException('تم إغلاق النقاش من قبل الإدارة.');
        }

        $this->assertInsideEditWindow($context->comment);
        $body = DiscussionBody::prepare($rawBody);

        if ($body === (string) $context->comment->body) {
            return $context->comment;
        }

        return DB::transaction(function () use ($context, $body) {
            $thread = DiscussionThread::query()->whereKey($context->thread->id)->lockForUpdate()->firstOrFail();
            $locked = DiscussionComment::query()->whereKey($context->comment->id)->lockForUpdate()->firstOrFail();

            if ($thread->is_locked) {
                throw new DiscussionException('تم إغلاق النقاش من قبل الإدارة.');
            }

            if (! $locked->isLive()) {
                throw new DiscussionException('لا يمكن تعديل هذا التعليق.');
            }

            $this->assertInsideEditWindow($locked);
            $locked->forceFill(['body' => $body, 'edited_at' => now()])->save();

            return $locked;
        });
    }

    public function delete(User $user, string $publicId): void
    {
        $this->assertEnabled();
        $this->guard->assertCanInteract($user);

        $context = $this->threads->contextFor($publicId, $user) ?? throw new ModelNotFoundException;
        $this->assertAuthor($context->comment, $user, 'حذف');

        if (! $context->comment->isLive()) {
            throw new DiscussionException('لا يمكن حذف هذا التعليق.');
        }

        DB::transaction(function () use ($context) {
            $locked = DiscussionComment::query()->whereKey($context->comment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isLive()) {
                throw new DiscussionException('لا يمكن حذف هذا التعليق.');
            }

            $locked->forceFill(['body' => '', 'deleted_at' => now()])->save();
            EngagementCounters::adjust('discussion_threads', (int) $locked->discussion_thread_id, ['comments_count' => -1]);
        });
    }

    protected function assertEnabled(): void
    {
        if (! (bool) config('discussions.enabled', true)) {
            throw new DiscussionException('النقاشات متوقفة مؤقتًا.');
        }
    }

    protected function assertAuthor(DiscussionComment $comment, User $user, string $verb): void
    {
        if ($comment->user_id === null || (int) $comment->user_id !== (int) $user->id) {
            throw new DiscussionException('لا يمكنك '.$verb.' تعليق غيرك.');
        }
    }

    protected function assertInsideEditWindow(DiscussionComment $comment): void
    {
        $minutes = (int) config('discussions.edit_window_minutes');

        if ($minutes <= 0 || $comment->created_at === null || $comment->created_at->lt(now()->subMinutes($minutes))) {
            throw new DiscussionException('انتهت مهلة تعديل التعليق'.($minutes > 0 ? ' ('.$minutes.' دقيقة)' : '').'.');
        }
    }
}
