<?php

namespace App\Services\Chat;

use App\Models\ChatThread;
use App\Models\User;
use App\Services\Social\BlockService;

/**
 * يبني إعداد واجهة الغرفة (E21-H): كل قدرة (إرسال/إشراف/تعديل/حذف/إبلاغ) **تُحسب بالخادم** وتُمرَّر للعميل، فالأزرار تعكس السياسة لا العكس.
 * الرسائل صفحة بالمؤشر (الأحدث 40 افتراضيًا) لا تاريخ كامل. الغرفة التي لم تُنشأ بعد (محادثة مباشرة جديدة) تُبنى بلا معرّف حتى أول إرسال.
 */
class ChatRoomView
{
    public function __construct(protected ChatAccess $access, protected ChatMessageService $messages, protected ChatMessagePresenter $presenter, protected BlockService $blocks) {}

    /** @return array{send: bool, moderate: bool, blocker: ?string, blocker_message: ?string} */
    public function caps(User $user, ?ChatThread $thread, ?User $directOther = null): array
    {
        $blocker = $thread !== null ? $this->access->sendBlocker($user, $thread) : ($directOther !== null ? $this->access->directSendBlocker($user, $directOther) : 'forbidden');
        $moderate = $thread !== null && ($thread->isGlobal() ? $user->can('chat.moderate') && $this->access->accountBlocker($user) === null : $this->access->canModerateTeamThread($user, $thread));

        return ['send' => $blocker === null, 'moderate' => (bool) $moderate, 'blocker' => $blocker, 'blocker_message' => $blocker !== null ? ChatAccess::message($blocker) : null];
    }

    /** @return array<string, string> قوالب مسارات العمليات على رسالة (يحلّ العميل :id). */
    public function messageEndpoints(): array
    {
        $tpl = fn (string $name) => str_replace('__ID__', ':id', route($name, ['message' => '__ID__']));

        return ['update' => $tpl('chat.messages.update'), 'destroy' => $tpl('chat.messages.destroy'), 'report' => $tpl('chat.messages.report'), 'hide' => $tpl('chat.messages.hide'), 'restore' => $tpl('chat.messages.restore')];
    }

    public function threadEndpoints(ChatThread $thread): array
    {
        return ['older' => route('chat.messages', $thread), 'read' => route('chat.read', $thread)];
    }

    public function config(User $user, ?ChatThread $thread, string $type, string $title, string $sendUrl, ?User $directOther = null, ?string $subtitle = null): array
    {
        $caps = $this->caps($user, $thread, $directOther);
        $page = $thread !== null ? $this->messages->page($thread, $user) : ['messages' => collect(), 'has_more' => false];
        $hidden = $thread !== null && $thread->isGlobal()
            ? \App\Models\User::query()->whereIn('id', $this->blocks->hiddenIds($user))->pluck('public_id')->all() : [];

        return [
            'type' => $type, 'title' => $title, 'subtitle' => $subtitle,
            'thread' => $thread?->public_id, 'channel' => $thread !== null ? 'chat.'.$thread->public_id : null,
            'viewer' => ['public_id' => $user->public_id, 'name' => $user->name],
            'caps' => $caps, 'hidden_sender_ids' => $hidden,
            'limits' => ['max_length' => (int) config('chat.message_max_length', 2000), 'edit_window_minutes' => (int) config('chat.edit_window_minutes', 15)],
            'categories' => config('chat.report_categories', []),
            'endpoints' => ['send' => $sendUrl, 'message' => $this->messageEndpoints()] + ($thread !== null ? $this->threadEndpoints($thread) : []),
            'messages' => $page['messages']->map(fn ($m) => $this->presenter->forViewer($m, $user, $caps))->values()->all(),
            'has_more' => $page['has_more'],
        ];
    }

    /** يُرجَع بعد الإرسال لتحديث العميل (معرّف الغرفة/القناة/المسارات إن كانت جديدة). */
    public function threadInfo(ChatThread $thread): array
    {
        return ['public_id' => $thread->public_id, 'channel' => 'chat.'.$thread->public_id] + $this->threadEndpoints($thread);
    }
}
