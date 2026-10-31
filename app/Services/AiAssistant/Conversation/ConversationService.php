<?php

namespace App\Services\AiAssistant\Conversation;

use App\Models\AiConversation;
use App\Models\AiFeedback;
use App\Models\AiMessage;
use App\Models\AiRequestMetric;
use App\Models\User;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E45 - محادثات الأعضاء المحفوظة (الضيف جلسة مؤقتة: انظر GuestSession). كل استعلام محصور بمالك المحادثة؛ لا يُرى أو يُحذف ما يخص غير صاحبه أبدًا.
 *  - الاحتفاظ منزلق: expires_at = آخر رسالة + أيام الاحتفاظ (افتراضي 30)؛ المنتهي يُحذف بأمر الصيانة ولا يُخدَم قبل ذلك.
 *  - جسم الرسالة مشفَّر (cast: encrypted)؛ المصادر روابط داخلية آمنة فقط (تُعاد فحصها عند الحفظ).
 *  - (conversation_id, seq) فريد ⇒ ترتيب حتمي، وسباق الإرسال المتزامن يُعاد مرة واحدة.
 *  - سقف الرسائل للمحادثة الواحدة وسقف عدد المحادثات للمستخدم (الأقدم يُحذف) كي لا يكبر الجدول بلا حد.
 */
final class ConversationService
{
    public function latest(User $user, ?string $publicId = null): ?AiConversation
    {
        $query = AiConversation::query()->where('user_id', $user->getKey())->where('expires_at', '>', now());

        if ($publicId !== null) {
            $query->where('public_id', $publicId);
        }

        return $query->orderByDesc('last_message_at')->orderByDesc('id')->first();
    }

    /** المحادثة الجارية للإلحاق (أو محادثة جديدة إن لم توجد/امتلأت). */
    public function ensure(User $user, ?string $publicId = null): AiConversation
    {
        $current = $this->latest($user, $publicId);

        if ($current !== null && $current->messages_count < TatawwurSettings::maxMessagesPerConversation()) {
            return $current;
        }

        return $this->start($user);
    }

    public function start(User $user): AiConversation
    {
        $conversation = new AiConversation;
        $conversation->forceFill([
            'user_id' => $user->getKey(),
            'messages_count' => 0,
            'last_message_at' => now(),
            'expires_at' => now()->addDays(TatawwurSettings::retentionDays()),
        ])->save();

        $stale = AiConversation::query()->where('user_id', $user->getKey())->orderByDesc('last_message_at')->orderByDesc('id')->pluck('id')->slice(TatawwurSettings::maxConversationsPerUser())->all();

        if ($stale !== []) {
            AiConversation::query()->whereIn('id', $stale)->where('user_id', $user->getKey())->delete();
        }

        return $conversation;
    }

    /** @return list<array{role: string, text: string}> آخر أدوار محدودة بترتيبها الزمني. */
    public function history(AiConversation $conversation): array
    {
        $limit = TatawwurSettings::historyTurns() * 2;

        if ($limit === 0) {
            return [];
        }

        return AiMessage::query()->where('conversation_id', $conversation->getKey())->orderByDesc('seq')->limit($limit)->get(['id', 'role', 'body', 'seq'])
            ->reverse()->values()
            ->map(fn (AiMessage $m) => ['role' => (string) $m->role, 'text' => mb_substr((string) $m->body, 0, 1500)])->all();
    }

    /** @param list<array{label: string, url: string}> $sources */
    public function append(AiConversation $conversation, string $role, string $text, array $sources = [], ?string $outcome = null): AiMessage
    {
        $role = $role === AiMessage::ROLE_ASSISTANT ? AiMessage::ROLE_ASSISTANT : AiMessage::ROLE_USER;
        $safe = [];

        foreach ($sources as $source) {
            $clean = AiLinkPolicy::source((string) ($source['label'] ?? ''), $source['url'] ?? null);

            if ($clean !== null) {
                $safe[] = $clean;
            }
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($conversation, $role, $text, $safe, $outcome): AiMessage {
                    $seq = (int) DB::table('ai_messages')->where('conversation_id', $conversation->getKey())->max('seq') + 1;

                    $message = new AiMessage;
                    $message->forceFill([
                        'conversation_id' => $conversation->getKey(),
                        'seq' => $seq,
                        'role' => $role,
                        'body' => $text,
                        'sources' => $safe === [] ? null : array_slice($safe, 0, 6),
                        'outcome' => $role === AiMessage::ROLE_ASSISTANT ? $outcome : null,
                    ])->save();

                    AiConversation::query()->whereKey($conversation->getKey())->update([
                        'messages_count' => $seq,
                        'last_message_at' => now(),
                        'expires_at' => now()->addDays(TatawwurSettings::retentionDays()),
                    ]);

                    return $message;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 1) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('unreachable');
    }

    /**
     * سجل المحادثة الأحدث للعرض عند فتح اللوحة.
     *
     * @return array{conversation_id: ?string, messages: list<array<string, mixed>>}
     */
    public function transcript(User $user): array
    {
        $conversation = $this->latest($user);

        if ($conversation === null) {
            return ['conversation_id' => null, 'messages' => []];
        }

        $messages = AiMessage::query()->where('conversation_id', $conversation->getKey())->orderBy('seq')->limit(TatawwurSettings::maxMessagesPerConversation())->get();
        $ratings = AiFeedback::query()->where('user_id', $user->getKey())->whereIn('message_id', $messages->pluck('id'))->pluck('rating', 'message_id');

        return [
            'conversation_id' => $conversation->public_id,
            'messages' => $messages->map(fn (AiMessage $m) => [
                'id' => $m->public_id,
                'role' => $m->role,
                'text' => (string) $m->body,
                'sources' => is_array($m->sources) ? $m->sources : [],
                'outcome' => $m->outcome,
                'rating' => $m->role === AiMessage::ROLE_ASSISTANT ? ($ratings[$m->id] ?? null) : null,
                'at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** يحذف كل محادثات المستخدم (الرسائل تتتابع حذفًا)، ويفكّ ارتباط تقييماته به فتبقى مجهولة الهوية. @return int عدد المحادثات المحذوفة */
    public function clearAll(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $ids = AiConversation::query()->where('user_id', $user->getKey())->pluck('id')->all();

            AiFeedback::query()->where('user_id', $user->getKey())->update(['user_id' => null]);

            return $ids === [] ? 0 : AiConversation::query()->whereIn('id', $ids)->where('user_id', $user->getKey())->delete();
        });
    }

    /** يحذف محادثة واحدة يملكها المستخدم. */
    public function delete(User $user, string $publicId): bool
    {
        return DB::transaction(function () use ($user, $publicId): bool {
            $conversation = AiConversation::query()->where('user_id', $user->getKey())->where('public_id', $publicId)->first();

            if ($conversation === null) {
                return false;
            }

            AiFeedback::query()->where('user_id', $user->getKey())->whereIn('message_id', AiMessage::query()->where('conversation_id', $conversation->getKey())->select('id'))->update(['user_id' => null]);
            $conversation->delete();

            return true;
        });
    }

    /**
     * أمر الصيانة: يحذف المحادثات المنتهية، والمقاييس والتقييمات الأقدم من مدة احتفاظها.
     *
     * @return array{conversations: int, metrics: int, feedback: int}
     */
    public function prune(): array
    {
        $conversations = AiConversation::query()->where('expires_at', '<=', now())->delete();
        $metrics = AiRequestMetric::query()->where('created_at', '<', now()->subDays(TatawwurSettings::metricsRetentionDays()))->delete();
        $feedback = AiFeedback::query()->where('created_at', '<', now()->subDays(TatawwurSettings::feedbackRetentionDays()))->delete();

        return ['conversations' => (int) $conversations, 'metrics' => (int) $metrics, 'feedback' => (int) $feedback];
    }
}
