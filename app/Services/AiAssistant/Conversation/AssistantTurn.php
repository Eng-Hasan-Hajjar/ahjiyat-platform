<?php

namespace App\Services\AiAssistant\Conversation;

use App\Models\User;

/** E45 - مدخل دور واحد للمنسّق: نص المستخدم + الجمهور + المستخدم (للأعضاء فقط) + تاريخ محدود (أدوار مغلقة). لا يحمل أي بيانات طلب خام. */
final class AssistantTurn
{
    /** @param list<array{role: string, text: string}> $history */
    public function __construct(
        public readonly string $message,
        public readonly string $audience,
        public readonly ?User $user = null,
        public readonly array $history = [],
        public readonly bool $stream = false,
    ) {}

    public function previousUserMessage(): ?string
    {
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if (($this->history[$i]['role'] ?? '') === 'user') {
                return (string) $this->history[$i]['text'];
            }
        }

        return null;
    }
}
