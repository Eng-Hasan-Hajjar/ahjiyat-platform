<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * E38 - ما يراه **المستخدم المعني** عن إجراءات الإشراف التي تخصّه. قراءة فقط، بشكل ثابت وحصري:
 *   يُعرض: نوع الإجراء، التصنيف المعلَن، رسالة الفريق (public_note)، التاريخ، حالة الاعتراض ورسالة قراره، رقم مرجعي للحالة.
 *   **لا يُعرض أبدًا**: هوية المبلِّغ، اسم المشرف (يُكتب «فريق الإشراف»)، السبب الداخلي، ملاحظات الأدلة، إشارات الاحتيال، تفاصيل مستخدمين آخرين.
 * الاستعلام يبدأ دائمًا من target_user_id = المستخدم نفسه (لا IDOR: لا معرّف إجراء يُقرأ دون هذا القيد).
 */
class UserSafetyService
{
    public function __construct(protected AppealService $appeals) {}

    /** @return array{frozen:bool,frozen_since:?CarbonInterface,items:list<array<string,mixed>>,has_freeze_action:bool} */
    public function overview(User $user): array
    {
        $actions = ModerationAction::query()
            ->where('target_user_id', $user->id)->where('user_visible', true)
            ->orderByDesc('id')->limit((int) config('trust_safety.lists.user_page_limit'))->get();

        $appeals = $actions->isEmpty() ? collect() : ModerationAppeal::query()
            ->where('appellant_id', $user->id)->whereIn('moderation_action_id', $actions->pluck('id'))
            ->orderBy('id')->get()->groupBy('moderation_action_id');

        $cases = $actions->isEmpty() ? collect() : ModerationCase::query()->whereIn('id', $actions->pluck('moderation_case_id')->unique())->pluck('case_number', 'id');

        $items = $actions->map(fn (ModerationAction $a) => $this->present($user, $a, $appeals->get($a->id, collect()), $cases->get($a->moderation_case_id)))->values()->all();

        return [
            'frozen' => (bool) $user->is_frozen,
            'frozen_since' => $user->frozen_at,
            'items' => $items,
            'has_freeze_action' => $actions->contains(fn ($a) => $a->action === 'freeze_user' && ! $a->isReversed()),
        ];
    }

    /** عرض إجراء واحد للمستخدم (أو null). مستقل عن حدّ القائمة. @return array<string,mixed>|null */
    public function item(User $user, string $publicId): ?array
    {
        $action = $this->find($user, $publicId);

        if ($action === null) {
            return null;
        }

        $appeals = ModerationAppeal::query()->where('appellant_id', $user->id)->where('moderation_action_id', $action->id)->orderBy('id')->get();
        $number = ModerationCase::query()->whereKey($action->moderation_case_id)->value('case_number');

        return $this->present($user, $action, $appeals, $number);
    }

    /** إجراء واحد يخصّ المستخدم (أو null: لا فرق بين غير موجود وليس لك). */
    public function find(User $user, string $publicId): ?ModerationAction
    {
        return ModerationAction::query()->where('public_id', $publicId)->where('target_user_id', $user->id)->where('user_visible', true)->first();
    }

    /** @param \Illuminate\Support\Collection<int,ModerationAppeal> $appeals @return array<string,mixed> */
    protected function present(User $user, ModerationAction $action, $appeals, ?string $caseNumber): array
    {
        $latest = $appeals->last();
        $reason = $this->appeals->ineligibleReason($user, $action);
        $state = match (true) {
            $action->isReversed() => 'reversed',
            $latest !== null && $latest->isActive() => $latest->status,
            $latest !== null && $latest->status !== ModerationAppeal::STATUS_WITHDRAWN => $latest->status,
            $reason === null => 'eligible',
            ! (bool) config('trust_safety.actions.'.$action->action.'.appealable', false) => 'not_appealable',
            $action->appealable_until !== null && $action->appealable_until->isPast() => 'expired',
            default => 'not_appealable',
        };

        return [
            'public_id' => $action->public_id,
            'label' => ModerationAction::LABELS[$action->action] ?? $action->action,
            'action' => $action->action,
            'category_label' => PolicyCatalog::categoryLabel($action->category),
            'message' => $action->public_note,
            'created_at' => $action->created_at,
            'reversed_at' => $action->reversed_at,
            'reference' => $caseNumber,
            'appeal_state' => $state,
            'appeal_state_label' => $this->stateLabel($state),
            'can_appeal' => $reason === null,
            'appeal_until' => $action->appealable_until,
            'decision_message' => $latest !== null && ! $latest->isActive() && $latest->status !== ModerationAppeal::STATUS_WITHDRAWN ? $latest->decision_message : null,
            'appeal_public_id' => $latest?->public_id,
            'can_withdraw' => $latest !== null && $latest->status === ModerationAppeal::STATUS_PENDING,
            'issuer' => 'فريق الإشراف',
        ];
    }

    protected function stateLabel(string $state): string
    {
        return [
            'eligible' => 'يمكنك الاعتراض', 'pending' => 'اعتراضك بانتظار المراجعة', 'in_review' => 'اعتراضك قيد المراجعة',
            'accepted' => 'قُبل اعتراضك', 'rejected' => 'رُفض اعتراضك', 'withdrawn' => 'سحبت اعتراضك', 'reversed' => 'أُلغي هذا الإجراء',
            'expired' => 'انتهت مهلة الاعتراض', 'not_appealable' => 'غير قابل للاعتراض',
        ][$state] ?? $state;
    }
}
