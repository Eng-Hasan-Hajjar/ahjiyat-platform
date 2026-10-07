<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\User;

/**
 * شكل الرسالة المعروض (E21). النص **عادي دائمًا** (لا HTML ولا Markdown) ويُهرَّب بكل مسار عرض (Alpine x-text)، والـJSON يُهرَّب بـ@json. الرسالة المحذوفة/المخفية لا تحمل نصها
 * للعامة (حالة deleted/hidden فقط). `public()` للبثّ والقوائم العامة: بلا حقول خاصة. `forViewer()` يضيف أعلام قدرات المشاهد (تعديل/حذف/إبلاغ/إخفاء) من الخادم لا من العميل.
 */
class ChatMessagePresenter
{
    /** @return array{id: int, thread: string, sender: ?array{public_id: string, name: string}, state: string, body: ?string, edited: bool, created_at: string} */
    public function public(ChatMessage $m): array
    {
        $m->loadMissing('thread:id,public_id', 'sender:id,name,public_id');

        return [
            'id' => $m->getKey(),
            'thread' => $m->thread->public_id,
            'sender' => $m->sender ? ['public_id' => $m->sender->public_id, 'name' => $m->sender->name] : null,
            'state' => $m->isDeleted() ? 'deleted' : ($m->isHidden() ? 'hidden' : 'normal'),
            'body' => $m->isNormal() ? $m->body : null,
            'edited' => $m->isNormal() && $m->edited_at !== null,
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array{send: bool, moderate: bool}  $caps  قدرات المشاهد بالغرفة (محسوبة مرة بالخادم)
     */
    public function forViewer(ChatMessage $m, User $viewer, array $caps): array
    {
        $payload = $this->public($m);
        $mine = $m->sender_id !== null && (int) $m->sender_id === (int) $viewer->getKey();
        $withinWindow = $m->created_at->copy()->addMinutes((int) config('chat.edit_window_minutes', 15))->greaterThanOrEqualTo(now());

        return $payload + [
            'mine' => $mine,
            'can_edit' => $mine && $m->isNormal() && $withinWindow && $caps['send'],
            'can_delete' => $mine && ! $m->isDeleted(),
            'can_report' => ! $mine && $m->isNormal() && $m->sender_id !== null,
            'can_hide' => $caps['moderate'] && $m->isNormal() && ! $mine,
            'can_restore' => $caps['moderate'] && $m->isHidden() && ! $m->isDeleted(),
            // يراه من يملك الإخفاء/الاستعادة فقط (ليقرّر الاستعادة)، لا العامة.
            'moderation_body' => $caps['moderate'] && $m->isHidden() && ! $m->isDeleted() ? $m->body : null,
        ];
    }
}
