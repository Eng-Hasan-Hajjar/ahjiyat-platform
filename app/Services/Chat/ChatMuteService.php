<?php

namespace App\Services\Chat;

use App\Models\ChatMute;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * كتم الدردشة العامة (E21-D8..D11): مؤقّت بمدد مغلقة (10 د، ساعة، 24 س، 7 أيام) وبسبب إلزامي، يرفعه المشرف أو تنتهي مدته. مستقل عن تجميد الحساب: المكتوم يقرأ ولا يرسل.
 * بصلاحية chat.moderate (لا أسماء أدوار) ومدقَّق (OperationalAuditService). لا يُكتم المشرفون ولا النفس.
 */
class ChatMuteService
{
    public function __construct(protected OperationalAuditService $audit) {}

    public function activeFor(User $user): ?ChatMute
    {
        return ChatMute::query()->where('user_id', $user->getKey())->active()->orderByDesc('expires_at')->first();
    }

    public function mute(User $actor, User $target, string $durationKey, string $reason): ChatMute
    {
        abort_unless($actor->can('chat.moderate'), 403);

        $minutes = config('chat.mute_durations.'.$durationKey);
        $reason = trim($reason);

        if ($minutes === null) {
            throw new ChatException('invalid_duration');
        }

        if ($reason === '' || mb_strlen($reason) > (int) config('chat.moderation_reason_max', 200)) {
            throw new ChatException('reason_required');
        }

        if ($actor->is($target) || $target->can('chat.moderate')) {
            throw new ChatException('bad_target');
        }

        return DB::transaction(function () use ($actor, $target, $minutes, $durationKey, $reason) {
            ChatMute::query()->where('user_id', $target->getKey())->active()->update(['lifted_at' => now(), 'lifted_by_id' => $actor->getKey()]);

            $mute = new ChatMute;
            $mute->forceFill(['user_id' => $target->getKey(), 'reason' => $reason, 'created_by_id' => $actor->getKey(), 'expires_at' => now()->addMinutes((int) $minutes)])->save();
            $this->audit->log('chat_user_muted', $target, ['duration' => $durationKey, 'expires_at' => $mute->expires_at->toIso8601String(), 'reason' => $reason], $actor);

            return $mute;
        });
    }

    /** يرفع كل كتم نشِط. @return int عدد ما رُفع */
    public function unmute(User $actor, User $target): int
    {
        abort_unless($actor->can('chat.moderate'), 403);

        return DB::transaction(function () use ($actor, $target) {
            $lifted = ChatMute::query()->where('user_id', $target->getKey())->active()->update(['lifted_at' => now(), 'lifted_by_id' => $actor->getKey()]);
            $lifted > 0 && $this->audit->log('chat_user_unmuted', $target, ['lifted' => $lifted], $actor);

            return $lifted;
        });
    }
}
