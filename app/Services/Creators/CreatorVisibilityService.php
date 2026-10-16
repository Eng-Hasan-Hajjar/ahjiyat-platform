<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E28 - كل ما يخصّ ظهور المنشور للعامة بمكان واحد:
 *  - إخفاء/استعادة إشرافي فوري (يُبقي التاريخ كله؛ سبب 'moderation' لا تلغيه إلا استعادة إشرافية).
 *  - مصالحة التعليق/التجميد: مبدع غير معتمَد أو حساب مجمَّد = منشوراته تُخفى (سبب creator_suspended/account_frozen) وتعود عند الرفع.
 *    idempotent: تُستدعى بعد كل تغيّر حالة (تعليق/استعادة/اعتماد/تجميد/رفع تجميد) ولا تمسّ المخفي إشرافيًا.
 * is_visible تُشتقّ بحفظ النموذج نفسه، فكل المسارات تمرّ بالحفظ (لا تحديث جماعي خام).
 */
class CreatorVisibilityService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected CreatorNotifier $notifier,
    ) {}

    public function hide(CreatorPuzzle $work, User $actor, string $note): CreatorPuzzle
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        $work = DB::transaction(function () use ($work, $actor, $note) {
            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($work->id);

            if (! $work->isPublished()) {
                throw new CreatorException('لا يوجد منشور لإخفائه.');
            }

            if ($work->isHidden() && $work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION) {
                throw new CreatorException('هذا المنشور مخفي بالفعل.');
            }

            $work->forceFill([
                'hidden_at' => now(),
                'hidden_by' => $actor->id,
                'hidden_reason' => CreatorPuzzle::HIDDEN_MODERATION,
                'hidden_note' => $note,
            ])->save();

            $this->audit->log('community_puzzle_hidden', $work, ['title' => $work->published_title, 'note' => $note], $actor);

            return $work;
        });

        $this->notifier->publicationHidden($work->loadMissing('creator.user'));

        return $work;
    }

    public function restore(CreatorPuzzle $work, User $actor): CreatorPuzzle
    {
        $this->assertReviewer($actor);

        return DB::transaction(function () use ($work, $actor) {
            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($work->id);

            if ($work->hidden_reason !== CreatorPuzzle::HIDDEN_MODERATION) {
                throw new CreatorException('هذا المنشور غير مخفي إشرافيًا.');
            }

            $work->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
            $this->audit->log('community_puzzle_restored', $work, ['title' => $work->published_title], $actor);

            // مبدع معلَّق/حساب مجمَّد يبقى منشوره مخفيًا بسببه الخاص.
            $this->reconcile($work->creator()->first());

            return $work->refresh();
        });
    }

    /** يضبط إخفاء كل منشورات المبدع بحسب حالته وحالة حسابه. آمن للتكرار. */
    public function reconcile(?CreatorProfile $profile): void
    {
        if ($profile === null) {
            return;
        }

        $profile->loadMissing('user');
        $reason = match (true) {
            ! $profile->isApproved() => CreatorPuzzle::HIDDEN_CREATOR_SUSPENDED,
            (bool) $profile->user?->is_frozen => CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN,
            default => null,
        };

        CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)
            ->whereNotNull('published_puzzle_id')
            ->chunkById(100, function ($works) use ($reason) {
                foreach ($works as $work) {
                    if ($reason !== null && $work->hidden_at === null) {
                        $work->forceFill(['hidden_at' => now(), 'hidden_by' => null, 'hidden_reason' => $reason, 'hidden_note' => null])->save();
                    } elseif ($reason === null && in_array($work->hidden_reason, [CreatorPuzzle::HIDDEN_CREATOR_SUSPENDED, CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN], true)) {
                        $work->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
                    } elseif ($reason !== null && $work->hidden_reason !== CreatorPuzzle::HIDDEN_MODERATION && $work->hidden_reason !== $reason) {
                        $work->forceFill(['hidden_reason' => $reason])->save(); // تبدّل السبب (تعليق ← تجميد) دون كشف المنشور
                    }
                }
            });
    }

    public function reconcileForUser(User $user): void
    {
        $this->reconcile($user->creatorProfile()->first());
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) < (int) config('creators.limits.note_min') || mb_strlen($note) > (int) config('creators.limits.note_max')) {
            throw new CreatorException('اكتب سببًا واضحًا ('.config('creators.limits.note_min').'–'.config('creators.limits.note_max').' حرفًا).');
        }

        return $note;
    }
}
