<?php

namespace App\Services\Chat;

use App\Models\ChatThread;
use App\Models\User;
use App\Services\Social\BlockService;
use App\Services\Social\FriendshipService;
use App\Services\Teams\TeamMembershipService;

/**
 * **مصدر الحقيقة الوحيد** لسياسات الدردشة (E21): من يقرأ ومن يرسل، وهو نفسه ما تستعمله القنوات الخاصة (البثّ) والمتحكّمات والخدمات: لا نسخة ثانية.
 * - الحساب: بريد موثَّق وغير مجمَّد (لكل الأنواع).
 * - direct: القراءة لطرفي الغرفة **دائمًا** (التاريخ يبقى بعد حظر/إلغاء صداقة)؛ الإرسال يتطلب صداقة مقبولة (E16) وبلا حظر بأي اتجاه.
 * - team: القراءة والإرسال لأعضاء الفريق **الحاليين** فقط (خروجه يقطع وصوله فورًا)؛ الفريق المعطَّل للقراءة فقط.
 * - global: القراءة لكل موثَّق غير مجمَّد؛ الإرسال بشرط ألا يكون مكتومًا (الكتم مستقل عن التجميد).
 */
class ChatAccess
{
    public function __construct(
        protected FriendshipService $friends,
        protected BlockService $blocks,
        protected TeamMembershipService $members,
        protected ChatMuteService $mutes,
    ) {}

    public static function message(string $reason): string
    {
        return match ($reason) {
            'unverified' => 'وثّق بريدك الإلكتروني لاستعمال الدردشة.',
            'frozen' => 'حسابك مجمَّد: لا يمكنك إرسال رسائل.',
            'forbidden' => 'لا صلاحية لك بهذه المحادثة.',
            'self' => 'لا يمكنك مراسلة نفسك.',
            'blocked' => 'لا يمكنك إرسال رسائل بسبب الحظر.',
            'not_friends' => 'لا يمكنك إرسال رسائل لأنكما لم تعودا أصدقاء.',
            'muted' => 'أنت مكتوم مؤقتًا عن الدردشة العامة: يمكنك القراءة فقط.',
            'team_inactive' => 'الفريق غير مفعَّل: الدردشة للقراءة فقط.',
            'closed' => 'هذه المحادثة مغلقة.',
            'duplicate' => 'لا تكرّر الرسالة نفسها بهذه السرعة.',
            'empty' => 'الرسالة فارغة.',
            'too_long' => 'الرسالة أطول من الحد المسموح.',
            'edit_window' => 'انتهت مهلة تعديل الرسالة.',
            'not_owner' => 'يمكنك التعديل على رسائلك فقط.',
            'not_editable' => 'لا يمكن تعديل هذه الرسالة.',
            'already_reported' => 'سبق أن أبلغت عن هذه الرسالة.',
            'own_message' => 'لا يمكنك الإبلاغ عن رسالتك.',
            'not_reportable' => 'لا يمكن الإبلاغ عن هذه الرسالة.',
            'invalid_category' => 'تصنيف البلاغ غير صالح.',
            'invalid_duration' => 'مدة الكتم غير صالحة.',
            'reason_required' => 'سبب الإجراء الإشرافي مطلوب.',
            'bad_target' => 'لا يمكن تطبيق هذا الإجراء على هذا المستخدم.',
            default => 'تعذّر تنفيذ الطلب.',
        };
    }

    /** سبب منع الحساب نفسه (null = مؤهَّل). */
    public function accountBlocker(User $user): ?string
    {
        return match (true) {
            ! $user->hasVerifiedEmail() => 'unverified',
            $user->is_frozen === true => 'frozen',
            default => null,
        };
    }

    public function canRead(User $user, ChatThread $thread): bool
    {
        if ($this->accountBlocker($user) !== null) {
            return false;
        }

        return match (true) {
            $thread->isDirect() => $thread->hasParticipant($user->getKey()),
            $thread->isTeam() => (int) $this->members->membershipOf($user)?->team_id === (int) $thread->team_id,
            $thread->isGlobal() => true,
            default => false,
        };
    }

    /** منع الإرسال بين مستخدمين (صداقة + حظر) بمعزل عن الغرفة: يُستعمل أيضًا قبل إنشاء غرفة مباشرة جديدة. */
    public function directSendBlocker(User $user, User $other): ?string
    {
        return match (true) {
            $user->is($other) => 'self',
            $this->blocks->blockedEitherWay($user, $other) => 'blocked',
            ! in_array($other->getKey(), $this->friends->friendIds($user), true) => 'not_friends',
            default => null,
        };
    }

    /** سبب منع الإرسال (null = يستطيع). */
    public function sendBlocker(User $user, ChatThread $thread): ?string
    {
        if (($reason = $this->accountBlocker($user)) !== null) {
            return $reason;
        }

        if (! $thread->is_active) {
            return 'closed';
        }

        if (! $this->canRead($user, $thread)) {
            return 'forbidden';
        }

        return match (true) {
            $thread->isDirect() => ($other = User::query()->find($thread->otherParticipantId($user->getKey()))) === null ? 'forbidden' : $this->directSendBlocker($user, $other),
            $thread->isTeam() => $thread->team()->where('is_active', true)->exists() ? null : 'team_inactive',
            $thread->isGlobal() => $this->mutes->activeFor($user) !== null ? 'muted' : null,
            default => 'forbidden',
        };
    }

    /** مالك/مشرف الفريق **الحاليان** لغرفة فريق (للإخفاء الإشرافي داخل الفريق). */
    public function canModerateTeamThread(User $user, ChatThread $thread): bool
    {
        if (! $thread->isTeam() || $this->accountBlocker($user) !== null) {
            return false;
        }

        $membership = $this->members->membershipOf($user);

        return $membership !== null && (int) $membership->team_id === (int) $thread->team_id && $membership->isManager();
    }
}
