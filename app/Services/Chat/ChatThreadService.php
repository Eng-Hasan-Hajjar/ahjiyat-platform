<?php

namespace App\Services\Chat;

use App\Models\ChatThread;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * الغرف (E21): الإنشاء الكسول الآمن من السباق. غرفة مباشرة واحدة لكل ثنائي (قانوني + UNIQUE)، غرفة رئيسية واحدة لكل فريق، وغرفة عالمية واحدة.
 * البحث (find) لا ينشئ شيئًا (صفحات GET لا تغيّر الحالة)؛ الإنشاء عند أول إرسال فقط.
 */
class ChatThreadService
{
    public function global(): ChatThread
    {
        $slug = config('chat.global.slug', 'global');

        return ChatThread::query()->where('type', ChatThread::TYPE_GLOBAL)->where('slug', $slug)->first()
            ?? $this->create(['type' => ChatThread::TYPE_GLOBAL, 'slug' => $slug], fn () => ChatThread::query()->where('slug', $slug)->firstOrFail());
    }

    public function findForTeam(Team $team): ?ChatThread
    {
        return ChatThread::query()->where('type', ChatThread::TYPE_TEAM)->where('team_id', $team->getKey())->first();
    }

    public function forTeam(Team $team): ChatThread
    {
        return $this->findForTeam($team)
            ?? $this->create(['type' => ChatThread::TYPE_TEAM, 'team_id' => $team->getKey()], fn () => ChatThread::query()->where('team_id', $team->getKey())->firstOrFail());
    }

    public function findDirect(User $a, User $b): ?ChatThread
    {
        return $a->is($b) ? null : ChatThread::query()->where('type', ChatThread::TYPE_DIRECT)->where('direct_key', ChatThread::directKey($a->getKey(), $b->getKey()))->first();
    }

    public function directOrCreate(User $a, User $b): ChatThread
    {
        if ($a->is($b)) {
            throw new ChatException('self');
        }

        [$one, $two] = $a->getKey() < $b->getKey() ? [$a->getKey(), $b->getKey()] : [$b->getKey(), $a->getKey()];
        $key = ChatThread::directKey($one, $two);

        return $this->findDirect($a, $b)
            ?? $this->create(['type' => ChatThread::TYPE_DIRECT, 'direct_user_one_id' => $one, 'direct_user_two_id' => $two, 'direct_key' => $key], fn () => $this->findDirect($a, $b) ?? throw new ChatException('forbidden'));
    }

    /** إنشاء بحماية السباق: الخاسر بالقيد UNIQUE يقرأ الفائز. */
    protected function create(array $attributes, \Closure $fallback): ChatThread
    {
        try {
            $thread = new ChatThread;
            $thread->forceFill($attributes + ['is_active' => true])->save();

            return $thread->refresh();
        } catch (UniqueConstraintViolationException) {
            return $fallback();
        }
    }
}
