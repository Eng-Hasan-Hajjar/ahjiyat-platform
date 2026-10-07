<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatReadState;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * رسائل الدردشة (E21): إرسال/تعديل/حذف ناعم/صفحات بالمؤشر/قراءة. **الخادم صاحب السلطة**: المرسل من المصادَقة، والغرفة من المسار المخوَّل (لا من الطلب)، والصلاحية من ChatAccess،
 * ثم معاملة ثم commit ثم بثّ (ChatBroadcaster). النص عادي: يُقصّ ويُنظَّف من محارف التحكم، 1–2000 محرفًا (config)، والفارغ مرفوض. لا تسجيل لنص الرسائل. لا اقتصاد/XP/مهام للدردشة.
 */
class ChatMessageService
{
    public function __construct(protected ChatAccess $access, protected ChatBroadcaster $broadcaster, protected BlockService $blocks) {}

    public function normalize(string $raw): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $raw);
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $body = trim($body);
        $length = mb_strlen($body);

        if ($length < (int) config('chat.message_min_length', 1)) {
            throw new ChatException('empty');
        }

        if ($length > (int) config('chat.message_max_length', 2000)) {
            throw new ChatException('too_long');
        }

        return $body;
    }

    public function send(User $user, ChatThread $thread, string $rawBody): ChatMessage
    {
        $body = $this->normalize($rawBody);

        if (($reason = $this->access->sendBlocker($user, $thread)) !== null) {
            throw new ChatException($reason);
        }

        if ($thread->isGlobal() && $this->isDuplicate($user, $thread, $body)) {
            throw new ChatException('duplicate');
        }

        $message = DB::transaction(function () use ($user, $thread, $body) {
            $message = new ChatMessage;
            $message->forceFill(['chat_thread_id' => $thread->getKey(), 'sender_id' => $user->getKey(), 'body' => $body])->save();

            ChatThread::query()->whereKey($thread->getKey())->update(['last_message_id' => $message->getKey(), 'last_message_at' => $message->created_at]);
            $this->advanceRead($thread->getKey(), $user->getKey(), $message->getKey());     // رسائلي لا تُحسب غير مقروءة عندي

            return $message;
        });

        $this->broadcaster->publish($message);

        return $message;
    }

    protected function isDuplicate(User $user, ChatThread $thread, string $body): bool
    {
        return ChatMessage::query()->where('chat_thread_id', $thread->getKey())->where('sender_id', $user->getKey())
            ->where('created_at', '>=', now()->subSeconds((int) config('chat.global.duplicate_window_seconds', 60)))
            ->whereRaw('LOWER(body) = ?', [mb_strtolower($body)])->exists();
    }

    public function edit(User $user, ChatMessage $message, string $rawBody): ChatMessage
    {
        $thread = $message->thread;

        if ((int) $message->sender_id !== (int) $user->getKey()) {
            throw new ChatException('not_owner');
        }

        if (! $message->isNormal()) {
            throw new ChatException('not_editable');
        }

        if (($reason = $this->access->sendBlocker($user, $thread)) !== null) {
            throw new ChatException($reason);
        }

        if ($message->created_at->copy()->addMinutes((int) config('chat.edit_window_minutes', 15))->lessThan(now())) {
            throw new ChatException('edit_window');
        }

        $body = $this->normalize($rawBody);

        if ($body !== $message->body) {
            $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
            $this->broadcaster->publish($message, updated: true);
        }

        return $message;
    }

    /** حذف المرسل لرسالته (Tombstone): النص يبقى بالقاعدة للمراجعة، ويُعرض «تم حذف هذه الرسالة». Idempotent. */
    public function deleteOwn(User $user, ChatMessage $message): ChatMessage
    {
        if ((int) $message->sender_id !== (int) $user->getKey()) {
            throw new ChatException('not_owner');
        }

        if (! $this->access->canRead($user, $message->thread)) {
            throw new ChatException('forbidden');
        }

        if (! $message->isDeleted()) {
            $message->forceFill(['deleted_at' => now()])->save();
            $this->broadcaster->publish($message, updated: true);
        }

        return $message;
    }

    /**
     * صفحة رسائل بالمؤشر (لا history كاملًا): الأحدث أولًا ثم تُعاد تصاعديًا. before = أقدم، after = ما بعد معرّف (للّحاق).
     * بالغرفة العامة تُرشَّح رسائل المحظورين (بأي اتجاه) حسب المشاهد فقط: المصدر يبقى كاملًا.
     *
     * @return array{messages: Collection<int, ChatMessage>, has_more: bool}
     */
    public function page(ChatThread $thread, User $viewer, ?int $before = null, ?int $after = null, ?int $limit = null): array
    {
        $limit = max(1, min($limit ?? (int) config('chat.page_size', 40), (int) config('chat.max_page_size', 60)));
        $query = ChatMessage::query()->where('chat_thread_id', $thread->getKey())->with('sender:id,name,public_id');

        if ($thread->isGlobal() && ($hidden = $this->blocks->hiddenIds($viewer)) !== []) {
            $query->where(fn ($q) => $q->whereNull('sender_id')->orWhereNotIn('sender_id', $hidden));
        }

        if ($after !== null) {
            $rows = $query->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();

            return ['messages' => $rows->take($limit)->values(), 'has_more' => $rows->count() > $limit];
        }

        $rows = $query->when($before !== null, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit($limit + 1)->get();

        return ['messages' => $rows->take($limit)->reverse()->values(), 'has_more' => $rows->count() > $limit];
    }

    /** يعلّم الغرفة مقروءة حتى أحدث رسالة (أو المعرّف المحدَّد). رتيب (لا يتراجع) وIdempotent. لمن يملك القراءة فقط. */
    public function markRead(User $user, ChatThread $thread, ?int $upTo = null): void
    {
        if (! $this->access->canRead($user, $thread)) {
            throw new ChatException('forbidden');
        }

        $latest = (int) ChatThread::query()->whereKey($thread->getKey())->value('last_message_id');
        $target = $upTo === null ? $latest : min($upTo, $latest);

        $this->advanceRead($thread->getKey(), $user->getKey(), $target > 0 ? $target : null);
    }

    /** upsert رتيب: صف واحد لكل (غرفة، مستخدم)، ومؤشر القراءة لا يتراجع أبدًا. */
    protected function advanceRead(int $threadId, int $userId, ?int $messageId): void
    {
        ChatReadState::query()->insertOrIgnore([['chat_thread_id' => $threadId, 'user_id' => $userId, 'last_read_message_id' => null, 'last_read_at' => null, 'created_at' => now(), 'updated_at' => now()]]);

        ChatReadState::query()->where('chat_thread_id', $threadId)->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->when($messageId !== null, fn ($w) => $w->orWhere('last_read_message_id', '<', $messageId)))
            ->update(['last_read_message_id' => $messageId, 'last_read_at' => now(), 'updated_at' => now()]);
    }
}
