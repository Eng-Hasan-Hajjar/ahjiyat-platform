<?php

namespace App\Services\Social;

/** نتيجة إجراء اجتماعي. الرسائل محايدة عمدًا: لا تكشف أن الطرف الآخر حظرنا أو عطّل الطلبات. */
enum FriendActionResult: string
{
    case Created = 'created';
    case AlreadyPending = 'already_pending';
    case AlreadyFriends = 'already_friends';
    case Accepted = 'accepted';
    case AcceptedExisting = 'accepted_existing';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Removed = 'removed';
    case Blocked = 'blocked';
    case AlreadyBlocked = 'already_blocked';
    case Unblocked = 'unblocked';
    case NotFound = 'not_found';
    case NotAllowed = 'not_allowed';
    case Unavailable = 'unavailable';
    case Cooldown = 'cooldown';
    case Self = 'self';

    public function isSuccess(): bool
    {
        return in_array($this, [
            self::Created, self::AlreadyPending, self::AlreadyFriends, self::Accepted, self::AcceptedExisting,
            self::Declined, self::Cancelled, self::Removed, self::Blocked, self::AlreadyBlocked, self::Unblocked,
        ], true);
    }

    public function message(): string
    {
        return match ($this) {
            self::Created => 'أُرسل طلب الصداقة.',
            self::AlreadyPending => 'طلبك قيد الانتظار بالفعل.',
            self::AlreadyFriends => 'أنتما صديقان بالفعل.',
            self::Accepted => 'أصبحتما صديقين.',
            self::AcceptedExisting => 'كان لدى هذا اللاعب طلب صداقة لك، فأصبحتما صديقين.',
            self::Declined => 'تم رفض الطلب.',
            self::Cancelled => 'أُلغي الطلب.',
            self::Removed => 'أُزيل الصديق.',
            self::Blocked => 'تم حظر اللاعب.',
            self::AlreadyBlocked => 'اللاعب محظور بالفعل.',
            self::Unblocked => 'أُلغي الحظر.',
            self::NotFound => 'لا يوجد طلب مطابق.',
            self::NotAllowed => 'لا يمكنك تنفيذ هذا الإجراء.',
            self::Unavailable => 'لا يمكن إرسال طلب صداقة لهذا اللاعب.',
            self::Cooldown => 'حاول مجددًا لاحقًا.',
            self::Self => 'لا يمكنك تنفيذ هذا الإجراء على نفسك.',
        };
    }
}
