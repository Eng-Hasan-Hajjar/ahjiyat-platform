<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * E36 - الوصول إلى النقاش وسياق التعليق. الإنشاء **كسول** (لا نقاش مسبق لكل كيان) وآمن للسباق: الفرادة بقيد قاعدة بيانات؛ الخاسر يعيد قراءة الصف الفائز.
 * contextFor هو المسار الوحيد لتحليل تعليق من معرّفه العام لمشاهد: يتحقق من أن الموضوع **ظاهر لهذا المشاهد الآن** (DiscussionSubjectRegistry) ومن الحظر
 * (مؤلف التعليق ومؤلف جذره ليسا بين محظوري/حاظري المشاهد، فلا يصل لمحتوى فرع محظور). النتيجة null = 404 موحّد بلا كشف السبب.
 */
class DiscussionThreadService
{
    public function __construct(protected DiscussionSubjectRegistry $registry, protected BlockService $blocks) {}

    public function findFor(DiscussionSubject $subject): ?DiscussionThread
    {
        return DiscussionThread::query()->where('subject_type', $subject->type)->where('subject_id', $subject->id)->first();
    }

    public function firstOrCreate(DiscussionSubject $subject): DiscussionThread
    {
        $thread = $this->findFor($subject);

        if ($thread !== null) {
            return $thread;
        }

        try {
            $thread = new DiscussionThread;
            $thread->forceFill(['subject_type' => $subject->type, 'subject_id' => $subject->id, 'comments_count' => 0])->save();

            return $thread;
        } catch (UniqueConstraintViolationException) {
            return $this->findFor($subject) ?? throw new DiscussionException('تعذّر فتح النقاش الآن، حاول مجددًا.');
        }
    }

    public function contextFor(string $commentPublicId, ?User $viewer): ?DiscussionContext
    {
        if (! Str::isUlid($commentPublicId)) {
            return null;
        }

        $comment = DiscussionComment::query()->where('public_id', Str::upper($commentPublicId))->with('thread')->first();

        if ($comment === null || $comment->thread === null) {
            return null;
        }

        $root = $comment->parent_id === null ? $comment : DiscussionComment::query()->find($comment->parent_id);

        if ($root === null) {
            return null;
        }

        if ($viewer !== null) {
            $hidden = array_flip($this->blocks->hiddenIds($viewer));

            foreach ([$comment->user_id, $root->user_id] as $authorId) {
                if ($authorId !== null && array_key_exists((int) $authorId, $hidden)) {
                    return null;
                }
            }
        }

        $subject = $this->registry->resolveThread($comment->thread, $viewer);

        return $subject === null ? null : new DiscussionContext($comment, $comment->thread, $subject, $root);
    }
}
