<?php

namespace App\Broadcasting;

use App\Models\ChatThread;
use App\Models\User;
use App\Services\Chat\ChatAccess;

/**
 * تخويل القناة الخاصة chat.{معرّف الغرفة العام} (E21-F7/F8/F13..F15): **القاعدة نفسها** ChatAccess::canRead المستعملة بالمتحكّمات والخدمات (لا نسخة ثانية):
 * direct = طرفا المحادثة فقط، team = أعضاء الفريق الحاليون فقط (خروجه يقطع الاشتراك عند إعادة التخويل)، global = موثَّق غير مجمَّد. الضيف مرفوض قبل الوصول هنا.
 */
class ChatThreadChannel
{
    public function __construct(protected ChatAccess $access) {}

    public function join(User $user, string $thread): bool
    {
        $room = ChatThread::query()->where('public_id', $thread)->first();

        return $room !== null && $this->access->canRead($user, $room);
    }
}
