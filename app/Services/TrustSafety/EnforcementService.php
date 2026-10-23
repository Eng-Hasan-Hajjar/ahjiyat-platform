<?php

namespace App\Services\TrustSafety;

use App\Models\ChatMessage;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Chat\ChatModerationService;
use App\Services\Collections\CollectionModerationService;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorVisibilityService;
use App\Services\Discussions\DiscussionException;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Engagement\EngagementException;
use App\Services\OperationalAuditService;
use App\Services\Reputation\CommunityReputationService;
use App\Services\UserAccountService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * E38 - تنفيذ إجراءات الإشراف **عبر خدمات المجال الرسمية فقط** (لا كتابة مباشرة لحالة أي مجال):
 *   E28 CreatorVisibilityService/CreatorApplicationService، E35 CollectionModerationService، E36 DiscussionModerationService، E21 ChatModerationService، UserAccountService.
 *
 *  - قائمة بيضاء مغلقة (config trust_safety.actions)؛ **الهدف يُشتقّ من موضوع الحالة** ولا يأتي أبدًا من العميل.
 *  - الإجراء الشديد (تجميد/تعليق مبدع): سبب أطول + تأكيد صريح + حالة فعّالة معيَّنة للمنفِّذ (أو مشرف أعلى).
 *  - صلاحيتان: صلاحية الثقة والسلامة + صلاحية خدمة المجال نفسها (الطبقتان تُفرضان؛ Super Admin يتجاوزهما بالبوابة العامة فقط، وحمايات خدمات المجال تبقى).
 *  - معاملة واحدة: حجز الإجراء (قيد unique يمنع التكرار تحت التزامن) + استدعاء الخدمة + الحدث + التدقيق؛ أي فشل يُرجع الكل.
 *  - بعد الـcommit فقط: إشعار المستخدم المعني (security) وتحديث السمعة E37 عند تغيّر الظهور. فشلهما يُلتقَط ولا يُرجع الإجراء.
 *  - لا نقاط/عقوبة آلية/حظر دائم؛ السمعة والدعم والشهرة لا تدخل في قرار أي إجراء.
 */
class EnforcementService
{
    /** إجراءات يراها المستخدم المعني بصفحة «الأمان والإشراف». */
    private const USER_VISIBLE = ['warn_user', 'hide_content', 'suspend_creator', 'freeze_user'];

    /** إجراءات قد تغيّر أهلية الظهور العام ⇒ تحديث السمعة بعد الـcommit. */
    private const VISIBILITY_CHANGING = ['hide_content', 'restore_content', 'suspend_creator', 'restore_creator', 'freeze_user', 'unfreeze_user'];

    public function __construct(
        protected CaseService $cases,
        protected OperationalAuditService $audit,
        protected ModerationNotifier $notifier,
        protected CreatorVisibilityService $visibility,
        protected CreatorApplicationService $creators,
        protected CollectionModerationService $collections,
        protected DiscussionModerationService $discussions,
        protected ChatModerationService $chat,
        protected UserAccountService $accounts,
    ) {}

    /**
     * الإجراءات المتاحة لهذه الحالة لهذا المشرف (للواجهة). لا تعني أن التنفيذ سينجح؛ التنفيذ يعيد كل الفحوص.
     *
     * @return list<array{key:string,label:string,severe:bool,kind:string}>
     */
    public function available(ModerationCase $case, User $actor): array
    {
        $out = [];

        foreach ((array) config('trust_safety.actions') as $key => $def) {
            if (! $actor->can($def['permission']) || ! $actor->can('trust-safety.manage-cases')) {
                continue;
            }

            if (! $this->applicable($case, $key)) {
                continue;
            }

            $out[] = ['key' => $key, 'label' => ModerationAction::LABELS[$key] ?? $key, 'severe' => (bool) $def['severe'], 'kind' => $def['kind']];
        }

        return $out;
    }

    /** @param array{reason?:?string,public_note?:?string,confirm?:mixed} $input */
    public function apply(ModerationCase $case, User $actor, string $action, array $input = []): ModerationAction
    {
        $def = PolicyCatalog::action($action);
        abort_unless($actor->can('trust-safety.manage-cases') && $actor->can($def['permission']), 403);

        $severe = (bool) $def['severe'];
        $reason = PlainText::clean((string) ($input['reason'] ?? ''), (int) config($severe ? 'trust_safety.reason.severe_min' : 'trust_safety.reason.min'), (int) config('trust_safety.reason.max'), 'السبب');
        $publicNote = $action === 'warn_user'
            ? PlainText::clean((string) ($input['public_note'] ?? ''), 3, 500, 'رسالة التنبيه')
            : PlainText::optional($input['public_note'] ?? null, 500, 'رسالة المستخدم');

        if ($severe && ! filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new ModerationException('هذا إجراء شديد ويتطلب تأكيدًا صريحًا.');
        }

        try {
            $row = DB::transaction(function () use ($case, $actor, $action, $def, $reason, $publicNote) {
                $locked = ModerationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                $this->cases->assertActive($locked);
                $this->cases->assertCanDrive($locked, $actor);
                $this->cases->assertCanWork($locked, $actor);

                $target = $this->resolveTarget($locked, $action);
                $this->assertDomainPermission($actor, $action, $locked);

                $row = $this->claimRow($locked, $actor, $action, $def, $target, $reason, $publicNote);
                $this->perform($action, $target, $actor, $locked, $publicNote);
                $this->markReversed($action, $def, $target['key'], $actor);

                $locked->forceFill(['first_responded_at' => $locked->first_responded_at ?? now()])->save();
                $this->cases->recordExternal($locked, $actor, 'action_applied', null, $action, null, ['action' => $row->public_id, 'target' => $target['key']]);
                $this->audit->log('moderation_action_applied', $row, [
                    'case' => $locked->public_id, 'number' => $locked->case_number, 'action' => $action, 'target' => $target['key'],
                    'category' => $locked->category, 'policy_version' => PolicyCatalog::policyVersion(), 'severe' => (bool) $def['severe'], 'reason' => $reason,
                ], $actor);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ModerationException('هذا الإجراء مُطبَّق بالفعل على الهدف.');
        } catch (HttpException $e) {
            // حمايات خدمات المجال (آخر مدير أعلى، الحساب الذاتي، صلاحيات التفويض) تُترجَم لرسالة آمنة؛ لا تُتجاوَز.
            throw new ModerationException($e->getMessage() !== '' ? $e->getMessage() : 'غير مسموح بهذا الإجراء.');
        } catch (CreatorException|EngagementException|DiscussionException $e) {
            throw new ModerationException($e->getMessage());
        }

        $this->afterCommit($row);

        return $row;
    }

    /** إجراء قابل للتطبيق على موضوع هذه الحالة؟ (لتصفية الواجهة فقط) */
    public function applicable(ModerationCase $case, string $action): bool
    {
        $subject = $case->subject_type;
        $content = ['community_puzzle', 'user_collection', 'discussion_comment', 'chat_message'];

        return match ($action) {
            'no_action' => true,
            'warn_user', 'suspend_creator', 'restore_creator', 'freeze_user', 'unfreeze_user' => $case->subject_user_id !== null,
            'hide_content', 'restore_content' => in_array($subject, $content, true),
            'lock_discussion', 'unlock_discussion' => $subject === 'discussion_comment',
            default => false,
        };
    }

    /**
     * يستخلص الهدف **من موضوع الحالة** (لا من المدخلات).
     *
     * @return array{key:string,subject_type:?string,subject_id:?int,user_id:?int,model:mixed}
     */
    protected function resolveTarget(ModerationCase $case, string $action): array
    {
        if (! $this->applicable($case, $action)) {
            throw new ModerationException('هذا الإجراء لا ينطبق على موضوع الحالة.');
        }

        $subjectType = $case->subject_type;
        $subjectId = $case->subject_id !== null ? (int) $case->subject_id : null;
        $userId = $case->subject_user_id !== null ? (int) $case->subject_user_id : null;

        switch ($action) {
            case 'no_action':
                return ['key' => 'case:'.$case->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'user_id' => $userId, 'model' => null];

            case 'warn_user':
                return ['key' => 'user:'.$userId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'user_id' => $userId, 'model' => null];

            case 'hide_content':
            case 'restore_content':
                $model = match ($subjectType) {
                    'community_puzzle' => CreatorPuzzle::query()->find($subjectId),
                    'user_collection' => UserCollection::query()->find($subjectId),
                    'discussion_comment' => DiscussionComment::query()->find($subjectId),
                    'chat_message' => ChatMessage::query()->with('thread')->find($subjectId),
                    default => null,
                };

                if ($model === null) {
                    throw new ModerationException('المحتوى المستهدف لم يعد موجودًا.');
                }

                return ['key' => $subjectType.':'.$subjectId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'user_id' => $userId, 'model' => $model];

            case 'lock_discussion':
            case 'unlock_discussion':
                $comment = DiscussionComment::query()->with('thread')->find($subjectId);

                if ($comment === null || $comment->thread === null) {
                    throw new ModerationException('النقاش المستهدف لم يعد موجودًا.');
                }

                return ['key' => 'discussion_thread:'.$comment->thread->id, 'subject_type' => 'discussion_thread', 'subject_id' => (int) $comment->thread->id, 'user_id' => $userId, 'model' => $comment->thread];

            case 'suspend_creator':
            case 'restore_creator':
                $profile = CreatorProfile::query()->where('user_id', $userId)->first();

                if ($profile === null) {
                    throw new ModerationException('هذا المستخدم ليس مبدعًا.');
                }

                return ['key' => 'creator_profile:'.$profile->id, 'subject_type' => 'creator_profile', 'subject_id' => (int) $profile->id, 'user_id' => $userId, 'model' => $profile];

            case 'freeze_user':
            case 'unfreeze_user':
                $user = User::query()->find($userId);

                if ($user === null) {
                    throw new ModerationException('الحساب المستهدف لم يعد موجودًا.');
                }

                if ($action === 'freeze_user' && $user->is_frozen) {
                    throw new ModerationException('الحساب مجمَّد بالفعل.');
                }

                if ($action === 'unfreeze_user' && ! $user->is_frozen) {
                    throw new ModerationException('الحساب غير مجمَّد.');
                }

                return ['key' => 'user:'.$user->id, 'subject_type' => 'user', 'subject_id' => (int) $user->id, 'user_id' => (int) $user->id, 'model' => $user];
        }

        throw new ModerationException('إجراء غير مسموح.');
    }

    /** صلاحية خدمة المجال (إضافةً لصلاحية الثقة والسلامة). */
    protected function assertDomainPermission(User $actor, string $action, ModerationCase $case): void
    {
        $permission = match ($action) {
            'hide_content', 'restore_content' => match ($case->subject_type) {
                'community_puzzle' => 'creators.review',
                'user_collection' => 'collections.moderate',
                'discussion_comment' => 'discussions.moderate',
                'chat_message' => 'chat.moderate',
                default => null,
            },
            'lock_discussion', 'unlock_discussion' => 'discussions.moderate',
            'suspend_creator', 'restore_creator' => 'creators.manage',
            'freeze_user' => 'users.freeze',
            'unfreeze_user' => 'users.unfreeze',
            default => null,
        };

        if ($permission !== null && ! $actor->can($permission)) {
            throw new ModerationException('تحتاج صلاحية «'.$permission.'» لتنفيذ هذا الإجراء.');
        }
    }

    /** يحجز صف الإجراء (قيد unique على الإجراء الفعّال + الهدف). @param array<string,mixed> $def @param array<string,mixed> $target */
    protected function claimRow(ModerationCase $case, User $actor, string $action, array $def, array $target, string $reason, ?string $publicNote): ModerationAction
    {
        $reversible = in_array($action, ['hide_content', 'lock_discussion', 'suspend_creator', 'freeze_user'], true);
        $window = (int) config('trust_safety.appeals.window_days.'.$action, 0);
        $visible = in_array($action, self::USER_VISIBLE, true) && $target['user_id'] !== null;

        $row = new ModerationAction;
        $row->forceFill([
            'moderation_case_id' => $case->id, 'action' => $action, 'target_user_id' => $target['user_id'],
            'subject_type' => $target['subject_type'], 'subject_id' => $target['subject_id'], 'target_key' => $target['key'],
            'category' => $case->category, 'policy_version' => PolicyCatalog::policyVersion(),
            'reason' => $reason, 'public_note' => $publicNote, 'performed_by' => $actor->id,
            'user_visible' => $visible,
            'appealable_until' => ($def['appealable'] ?? false) && $window > 0 && $visible ? now()->addDays($window) : null,
            'active_marker' => $reversible ? 1 : null,
        ])->save();

        return $row;
    }

    /** يستدعي خدمة المجال الرسمية. @param array<string,mixed> $target */
    protected function perform(string $action, array $target, User $actor, ModerationCase $case, ?string $publicNote): void
    {
        $label = PolicyCatalog::categoryLabel($case->category);
        $note = 'مخالفة لإرشادات المجتمع — '.$label.($publicNote !== null ? ': '.$publicNote : '');

        match ($action) {
            'no_action', 'warn_user' => null,

            'hide_content' => match ($case->subject_type) {
                'community_puzzle' => $this->visibility->hide($target['model'], $actor, mb_substr($note, 0, (int) config('creators.limits.note_max'))),
                'user_collection' => $this->collections->hide($target['model'], $actor, mb_substr($note, 0, (int) config('collections.reports.note_max'))),
                'discussion_comment' => $this->discussions->hide($target['model'], $actor, $this->discussionReason($case->category), null),
                'chat_message' => $this->hideChat($target['model'], $actor, $label),
            },

            'restore_content' => match ($case->subject_type) {
                'community_puzzle' => $this->visibility->restore($target['model'], $actor),
                'user_collection' => $this->collections->restore($target['model'], $actor),
                'discussion_comment' => $this->discussions->restore($target['model'], $actor),
                'chat_message' => $this->restoreChat($target['model'], $actor),
            },

            'lock_discussion' => $this->discussions->lockThread($target['model'], $actor, null),
            'unlock_discussion' => $this->discussions->unlockThread($target['model'], $actor),

            // الملاحظة الظاهرة للمبدع/الحساب ثابتة المحتوى: لا نص داخلي يتسرّب إلى حقول المجال.
            'suspend_creator' => $this->creators->suspend($target['model'], $actor, mb_substr($note, 0, (int) config('creators.limits.note_max'))),
            'restore_creator' => $this->creators->restore($target['model'], $actor, 'استعادة بقرار الإشراف'),
            'freeze_user' => $this->accounts->freeze($target['model'], $actor, 'حالة إشراف '.$case->case_number),
            'unfreeze_user' => $this->accounts->unfreeze($target['model'], $actor),

            default => throw new ModerationException('إجراء غير مسموح.'),
        };
    }

    protected function discussionReason(string $category): string
    {
        $key = (string) config('trust_safety.discussion_reason_map.'.$category, 'other');

        return array_key_exists($key, \App\Models\DiscussionCommentReport::REASONS) ? $key : 'other';
    }

    protected function hideChat(ChatMessage $message, User $actor, string $label): void
    {
        if (! $this->chat->canModerate($actor, $message)) {
            throw new ModerationException('لا يمكنك الإشراف على هذه الرسالة (لا بلاغ عليها أو خارج نطاقك).');
        }

        if ($message->isHidden() || $message->isDeleted()) {
            throw new ModerationException('الرسالة مخفية أو محذوفة بالفعل.');
        }

        $this->chat->hide($actor, $message, mb_substr($label, 0, (int) config('chat.moderation_reason_max', 200)));
    }

    protected function restoreChat(ChatMessage $message, User $actor): void
    {
        if (! $this->chat->canModerate($actor, $message)) {
            throw new ModerationException('لا يمكنك الإشراف على هذه الرسالة (لا بلاغ عليها أو خارج نطاقك).');
        }

        if (! $message->isHidden()) {
            throw new ModerationException('الرسالة غير مخفية.');
        }

        $this->chat->restore($actor, $message, 'استعادة بقرار الإشراف');
    }

    /** الإجراء العاكس يُغلق الإجراء الفعّال الأصلي على الهدف نفسه (من أي حالة) ويحرّر قيده الفريد. @param array<string,mixed> $def */
    protected function markReversed(string $action, array $def, string $targetKey, User $actor): void
    {
        $reverses = $def['reverses'] ?? null;

        if ($reverses === null) {
            return;
        }

        $original = ModerationAction::query()->where('action', $reverses)->where('target_key', $targetKey)->where('active_marker', 1)->lockForUpdate()->first();

        if ($original === null) {
            return; // طُبِّق الأصل خارج هذه الطبقة؛ العكس مسموح عبر خدمة المجال وحدها.
        }

        $original->forceFill(['active_marker' => null, 'reversed_at' => now(), 'reversed_by' => $actor->id])->save();
        $originCase = ModerationCase::query()->find($original->moderation_case_id);

        if ($originCase !== null) {
            $this->cases->recordExternal($originCase, $actor, 'action_reversed', $reverses, $action, null, ['action' => $original->public_id, 'by' => 'moderator']);
        }
    }

    /** الإجراء الذي يعكسه كل إجراء قابل للاعتراض عند قبول الاعتراض. warn_user يُسحب فقط (بلا استدعاء مجال). */
    public const APPEAL_REVERSAL = ['hide_content' => 'restore_content', 'suspend_creator' => 'restore_creator', 'freeze_user' => 'unfreeze_user'];

    /**
     * قبول اعتراض: يعكس الإجراء الأصلي **عبر خدمة المجال الرسمية** ويسجّل إجراءً عاكسًا جديدًا؛ الأصل لا يُحذف (يُوسَم معكوسًا فقط).
     * يُستدعى داخل معاملة قرار الاعتراض. إن كان الأثر قد زال خارج هذه الطبقة (استُعيد المحتوى/رُفع التجميد) يُوسَم الأصل معكوسًا دون استدعاء مجال.
     * يعيد صف الإجراء العاكس أو null (سحب تنبيه/معكوس مسبقًا/زال أثره).
     */
    public function reverseForAppeal(ModerationAction $original, User $reviewer, ModerationAppeal $appeal): ?ModerationAction
    {
        abort_unless($reviewer->can('trust-safety.review-appeals'), 403);

        $original = ModerationAction::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

        if ($original->isReversed()) {
            return null;
        }

        $case = ModerationCase::query()->findOrFail($original->moderation_case_id);

        if ($original->action === 'warn_user') {
            $this->closeOriginal($original, $reviewer);
            $this->cases->recordExternal($case, $reviewer, 'action_reversed', 'warn_user', 'withdrawn', null, ['action' => $original->public_id, 'by' => 'appeal', 'appeal' => $appeal->public_id]);

            return null;
        }

        $reverse = self::APPEAL_REVERSAL[$original->action] ?? throw new ModerationException('هذا الإجراء لا يُعكس عبر الاعتراض.');

        if (! $this->stillApplied($original)) {
            $this->closeOriginal($original, $reviewer);
            $this->cases->recordExternal($case, $reviewer, 'action_reversed', $original->action, $reverse, null, ['action' => $original->public_id, 'by' => 'appeal', 'appeal' => $appeal->public_id, 'already_reverted' => true]);

            return null;
        }

        $def = PolicyCatalog::action($reverse);
        $this->assertDomainPermission($reviewer, $reverse, $case);
        $target = $this->resolveTarget($case, $reverse);

        try {
            $row = $this->claimRow($case, $reviewer, $reverse, $def, $target, 'قبول اعتراض', null);
            $this->perform($reverse, $target, $reviewer, $case, null);
        } catch (HttpException $e) {
            throw new ModerationException($e->getMessage() !== '' ? $e->getMessage() : 'غير مسموح بهذا الإجراء.');
        } catch (CreatorException|EngagementException|DiscussionException $e) {
            throw new ModerationException($e->getMessage());
        }

        $this->closeOriginal($original, $reviewer);
        $this->cases->recordExternal($case, $reviewer, 'action_reversed', $original->action, $reverse, null, ['action' => $original->public_id, 'reversal' => $row->public_id, 'by' => 'appeal', 'appeal' => $appeal->public_id]);
        $this->audit->log('moderation_action_reversed', $row, ['case' => $case->public_id, 'original' => $original->public_id, 'action' => $reverse, 'appeal' => $appeal->public_id], $reviewer);

        return $row;
    }

    /** يُستدعى بعد الـcommit من AppealService: إشعار/تحديث سمعة للإجراء العاكس. */
    public function afterReversal(?ModerationAction $row, ModerationAction $original): void
    {
        if ($row !== null) {
            $this->afterCommit($row);
        } elseif ($original->target_user_id !== null) {
            try {
                app(CommunityReputationService::class)->refreshUsers([(int) $original->target_user_id]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function closeOriginal(ModerationAction $original, User $reviewer): void
    {
        $original->forceFill(['active_marker' => null, 'reversed_at' => now(), 'reversed_by' => $reviewer->id])->save();
    }

    /** هل أثر الإجراء الأصلي قائم الآن بحالة المجال؟ */
    protected function stillApplied(ModerationAction $original): bool
    {
        $id = (int) $original->subject_id;

        return match ($original->action) {
            'hide_content' => match ($original->subject_type) {
                'community_puzzle' => (bool) CreatorPuzzle::query()->whereKey($id)->where('hidden_reason', CreatorPuzzle::HIDDEN_MODERATION)->whereNotNull('hidden_at')->exists(),
                'user_collection' => (bool) UserCollection::query()->whereKey($id)->where('is_moderation_hidden', true)->exists(),
                'discussion_comment' => (bool) DiscussionComment::query()->whereKey($id)->where('status', DiscussionComment::STATUS_HIDDEN)->exists(),
                'chat_message' => (bool) ChatMessage::query()->whereKey($id)->whereNotNull('hidden_at')->exists(),
                default => false,
            },
            'suspend_creator' => (bool) CreatorProfile::query()->whereKey($id)->where('status', CreatorProfile::STATUS_SUSPENDED)->exists(),
            'freeze_user' => (bool) User::query()->whereKey($id)->where('is_frozen', true)->exists(),
            default => false,
        };
    }

    protected function afterCommit(ModerationAction $row): void
    {
        $this->notifier->notice($row);

        if ($row->target_user_id !== null && in_array($row->action, self::VISIBILITY_CHANGING, true)) {
            try {
                app(CommunityReputationService::class)->refreshUsers([(int) $row->target_user_id]);
            } catch (Throwable $e) {
                report($e); // فشل التحديث لا يُرجع إجراء السلامة؛ reputation:recalculate يعيد الاتساق.
            }
        }
    }
}
