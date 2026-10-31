<?php

namespace App\Services\AiAssistant\Conversation;

use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Contracts\Session\Session;

/**
 * E45 - محادثة الضيف: **جلسة مؤقتة فقط** (لا صفوف قاعدة بيانات ولا هوية دائمة للضيف). تُحفظ آخر أدوار محدودة (guest_session_turns) في الجلسة
 * وتنتهي بانتهاء الجلسة. نصوص المساعد المحفوظة هي النصوص الآمنة المُنقّاة وحدها.
 */
final class GuestSession
{
    private const KEY = 'tatawwur_ai.guest';

    /** @return list<array{role: string, text: string}> */
    public function history(Session $session): array
    {
        $items = $session->get(self::KEY, []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($i) => is_array($i) && in_array($i['role'] ?? null, ['user', 'assistant'], true) && is_string($i['text'] ?? null)
                ? ['role' => (string) $i['role'], 'text' => mb_substr((string) $i['text'], 0, 1500)]
                : null,
            $items,
        )));
    }

    public function push(Session $session, string $role, string $text): void
    {
        $limit = TatawwurSettings::guestSessionTurns() * 2;

        if ($limit === 0) {
            return;
        }

        $items = $this->history($session);
        $items[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'text' => mb_substr($text, 0, 1500)];
        $session->put(self::KEY, array_slice($items, -$limit));
    }

    public function clear(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
