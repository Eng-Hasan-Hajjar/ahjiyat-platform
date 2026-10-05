<?php

namespace App\Services\Social;

/** حالة العلاقة من منظور المستخدم الحالي تجاه لاعب آخر (تعداد مغلق). */
enum FriendRelation: string
{
    case Self = 'self';
    case None = 'none';                       // يمكن إرسال طلب
    case OutgoingPending = 'outgoing_pending';
    case IncomingPending = 'incoming_pending';
    case Friends = 'friends';
    case BlockedByMe = 'blocked_by_me';       // أنا حظرته
    case Unavailable = 'unavailable';         // لا إجراء متاح (حظرني، أو لا يستقبل طلبات، أو ملف غير مرئي) - لا نكشف السبب
}
