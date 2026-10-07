<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatReadState;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Social\BlockService;
use App\Services\Teams\TeamMembershipService;
use Illuminate\Support\Collection;

/**
 * غير المقروء (E21-G): **مؤشر واحد** لكل (غرفة، مستخدم) لا صف لكل رسالة. العدّ مفهرس (thread,id) ومحدود بسقف (لا عدّ تاريخ ضخم): يرجع حتى cap+1 والواجهة تعرض «99+».
 * يُحسب فقط: رسائل غيري، الطبيعية (غير محذوفة/مخفية)، وبالعامة دون رسائل المحظورين. الغرفة العامة بلا مؤشر قراءة = 0 (لا شارة 99+ لحساب لم يفتحها بعد). لا إشعار لكل رسالة (الدردشة تملك عدّادها).
 */
class ChatUnreadService
{
    public function __construct(protected ChatAccess $access, protected TeamMembershipService $members, protected BlockService $blocks) {}

    public function forThread(User $user, ChatThread $thread, ?int $lastRead = null, bool $stateKnown = false): int
    {
        if (! $this->access->canRead($user, $thread) || $thread->last_message_id === null) {
            return 0;
        }

        if (! $stateKnown) {
            $state = ChatReadState::query()->where('chat_thread_id', $thread->getKey())->where('user_id', $user->getKey())->first();
            $lastRead = $state?->last_read_message_id;
            $stateKnown = $state !== null;
        }

        if ($thread->isGlobal() && ! $stateKnown) {
            return 0;
        }

        $cap = (int) config('chat.unread_cap', 99);
        $query = ChatMessage::query()->where('chat_thread_id', $thread->getKey())->where('id', '>', (int) $lastRead)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->getKey()))->whereNull('hidden_at')->whereNull('deleted_at');

        if ($thread->isGlobal() && ($hidden = $this->blocks->hiddenIds($user)) !== []) {
            $query->where(fn ($q) => $q->whereNull('sender_id')->orWhereNotIn('sender_id', $hidden));
        }

        return min($query->limit($cap + 1)->pluck('id')->count(), $cap + 1);
    }

    /**
     * غير المقروء لمجموعة محادثات **مباشرة** باستعلام واحد مجمَّع (لا استعلام لكل محادثة): الرسائل الطبيعية من غيري بعد مؤشر قراءتي، مقيَّدة بسقف cap+1 لكل غرفة.
     * (الفريق والعامة غرفتان فقط فتُعدّان بـforThread المقيَّد بحدّ.)
     *
     * @param  Collection<int, ChatThread>  $threads
     * @return array<int, int> thread_id => عدد
     */
    public function directCounts(User $user, Collection $threads): array
    {
        if ($threads->isEmpty()) {
            return [];
        }

        $rows = ChatMessage::query()->selectRaw('chat_messages.chat_thread_id as tid, count(*) as c')
            ->leftJoin('chat_read_states as rs', fn ($j) => $j->on('rs.chat_thread_id', '=', 'chat_messages.chat_thread_id')->where('rs.user_id', $user->getKey()))
            ->whereIn('chat_messages.chat_thread_id', $threads->pluck('id'))
            ->whereRaw('chat_messages.id > coalesce(rs.last_read_message_id, 0)')
            ->where(fn ($q) => $q->whereNull('chat_messages.sender_id')->orWhere('chat_messages.sender_id', '!=', $user->getKey()))
            ->whereNull('chat_messages.hidden_at')->whereNull('chat_messages.deleted_at')
            ->groupBy('chat_messages.chat_thread_id')->pluck('c', 'tid');

        $cap = (int) config('chat.unread_cap', 99) + 1;

        return $threads->mapWithKeys(fn (ChatThread $t) => [$t->id => min((int) ($rows[$t->id] ?? 0), $cap)])->all();
    }

    /** مجموع غير المقروء لشارة التنقّل: غرف المستخدم المباشرة + غرفة فريقه الحالي + العامة. */
    public function total(User $user): int
    {
        if ($this->access->accountBlocker($user) !== null) {
            return 0;
        }

        $threads = $this->visibleThreads($user);
        $states = ChatReadState::query()->where('user_id', $user->getKey())->whereIn('chat_thread_id', $threads->pluck('id'))->pluck('last_read_message_id', 'chat_thread_id');
        [$direct, $rooms] = $threads->partition(fn (ChatThread $t) => $t->isDirect());
        $counts = $this->directCounts($user, $direct);

        // المباشرة باستعلام مجمَّع واحد (قراءتها دائمة لطرفيها)، والفريق/العامة غرفتان محدودتان بحدّ.
        return array_sum($counts) + $rooms->sum(fn (ChatThread $t) => $this->forThread($user, $t, $states->has($t->id) ? $states[$t->id] : null, $states->has($t->id)));
    }

    /** @return Collection<int, ChatThread> غرف لها رسائل ويقرؤها المستخدم الآن. */
    public function visibleThreads(User $user): Collection
    {
        $membership = $this->members->membershipOf($user);

        return ChatThread::query()->whereNotNull('last_message_id')->where(function ($q) use ($user, $membership) {
            $q->where(fn ($d) => $d->where('type', ChatThread::TYPE_DIRECT)->where(fn ($w) => $w->where('direct_user_one_id', $user->getKey())->orWhere('direct_user_two_id', $user->getKey())))
                ->orWhere('type', ChatThread::TYPE_GLOBAL);
            $membership !== null && $q->orWhere(fn ($t) => $t->where('type', ChatThread::TYPE_TEAM)->where('team_id', $membership->team_id));
        })->get();
    }

    /**
     * قائمة المحادثات المباشرة للمستخدم مرتّبة بآخر رسالة، بلا N+1 (استعلامات ثابتة).
     *
     * @return Collection<int, array{thread: ChatThread, other: ?User, last: ?ChatMessage, unread: int}>
     */
    public function directList(User $user): Collection
    {
        $threads = ChatThread::query()->where('type', ChatThread::TYPE_DIRECT)->where(fn ($q) => $q->where('direct_user_one_id', $user->getKey())->orWhere('direct_user_two_id', $user->getKey()))
            ->whereNotNull('last_message_id')->orderByDesc('last_message_at')->orderByDesc('id')->get();

        $others = User::query()->whereIn('id', $threads->map(fn ($t) => $t->otherParticipantId($user->getKey()))->filter())->get(['id', 'name', 'public_id'])->keyBy('id');
        $last = ChatMessage::query()->whereIn('id', $threads->pluck('last_message_id'))->get()->keyBy('id');
        $counts = $this->access->accountBlocker($user) === null ? $this->directCounts($user, $threads) : [];

        return $threads->map(fn (ChatThread $t) => [
            'thread' => $t, 'other' => $others->get($t->otherParticipantId($user->getKey())), 'last' => $last->get($t->last_message_id), 'unread' => $counts[$t->id] ?? 0,
        ]);
    }
}
