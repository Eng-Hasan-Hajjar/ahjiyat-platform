<?php

namespace App\Services\AiAssistant;

use App\Models\User;

/**
 * E45 - مصدر واحد لإعدادات «تطوّر AI». كل قيمة رقمية تُقصّ إلى سقف صلب هنا: قيمة بيئة خاطئة أو مبالغ فيها لا تفتح بابًا كلفةً أو أمانًا.
 * قراءة فقط؛ لا تُرجع مفتاح المزوّد أبدًا (isProviderConfigured يعيد منطقيًا فقط).
 */
final class TatawwurSettings
{
    public const AUDIENCE_MEMBER = 'member';

    public const AUDIENCE_GUEST = 'guest';

    public static function enabled(): bool
    {
        return (bool) config('tatawwur_ai.enabled', false);
    }

    public static function providerEnabled(): bool
    {
        return (bool) config('tatawwur_ai.provider_enabled', false);
    }

    public static function guestsEnabled(): bool
    {
        return (bool) config('tatawwur_ai.guests_enabled', false);
    }

    public static function streamingEnabled(): bool
    {
        return (bool) config('tatawwur_ai.streaming_enabled', false);
    }

    public static function providerName(): string
    {
        return strtolower(trim((string) config('tatawwur_ai.provider', 'openai')));
    }

    /** اسم النموذج المهيّأ، أو null. لا قيمة افتراضية بالكود. */
    public static function model(): ?string
    {
        $model = trim((string) config('tatawwur_ai.model', ''));

        return $model !== '' && preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $model) === 1 ? $model : null;
    }

    public static function maxInputChars(): int
    {
        return self::clamp('max_input_chars', 600, 50, 2000);
    }

    public static function maxOutputTokens(): int
    {
        return self::clamp('max_output_tokens', 900, 64, 2500);
    }

    public static function maxToolCalls(): int
    {
        return self::clamp('max_tool_calls', 5, 0, 8);
    }

    public static function contextChunks(): int
    {
        return self::clamp('context_chunks', 6, 4, 8);
    }

    public static function historyTurns(): int
    {
        return self::clamp('history_turns', 6, 0, 12);
    }

    public static function requestTimeout(): int
    {
        return self::clamp('request_timeout_seconds', 25, 3, 60);
    }

    public static function connectTimeout(): int
    {
        return self::clamp('connect_timeout_seconds', 5, 1, 15);
    }

    public static function retries(): int
    {
        return self::clamp('retries', 1, 0, 2);
    }

    public static function perMinute(string $audience): int
    {
        return $audience === self::AUDIENCE_MEMBER ? self::clamp('user_per_minute', 10, 1, 60) : self::clamp('guest_per_minute', 5, 1, 30);
    }

    public static function perDay(string $audience): int
    {
        return $audience === self::AUDIENCE_MEMBER ? self::clamp('user_per_day', 120, 1, 2000) : self::clamp('guest_per_day', 25, 1, 500);
    }

    public static function burst(): int
    {
        return self::clamp('burst_per_ten_seconds', 3, 1, 10);
    }

    public static function globalDailyTokenLimit(): int
    {
        return self::clamp('global_daily_token_limit', 2000000, 1000, 100000000);
    }

    public static function retentionDays(): int
    {
        return max(1, min(365, (int) config('tatawwur_ai.retention.conversation_days', 30)));
    }

    public static function metricsRetentionDays(): int
    {
        return max(7, min(730, (int) config('tatawwur_ai.retention.metrics_days', 90)));
    }

    public static function feedbackRetentionDays(): int
    {
        return max(7, min(730, (int) config('tatawwur_ai.retention.feedback_days', 180)));
    }

    public static function maxConversationsPerUser(): int
    {
        return max(1, min(100, (int) config('tatawwur_ai.retention.max_conversations_per_user', 20)));
    }

    public static function maxMessagesPerConversation(): int
    {
        return max(4, min(500, (int) config('tatawwur_ai.retention.max_messages_per_conversation', 60)));
    }

    public static function guestSessionTurns(): int
    {
        return max(0, min(12, (int) config('tatawwur_ai.retention.guest_session_turns', 6)));
    }

    public static function minRetrievalScore(): float
    {
        return max(0.5, min(50.0, (float) config('tatawwur_ai.retrieval.min_score', 3.0)));
    }

    public static function maxChunkChars(): int
    {
        return max(200, min(1500, (int) config('tatawwur_ai.retrieval.max_chunk_chars', 700)));
    }

    /** العضو = موثَّق البريد وغير مجمَّد. غيره (ضيف أو بريد غير موثَّق) يُعامَل كضيف: أدوات عامة فقط وبلا حفظ. */
    public static function audienceFor(?User $user): string
    {
        return $user !== null && $user->email_verified_at !== null && ! $user->is_frozen ? self::AUDIENCE_MEMBER : self::AUDIENCE_GUEST;
    }

    /** هل يُعرض الزر العائم لهذا المشاهد؟ الحساب المجمَّد لا يراه. الضيف يراه فقط إن فُعّل الضيوف. */
    public static function widgetVisibleFor(?User $user): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if ($user !== null && $user->is_frozen) {
            return false;
        }

        return self::audienceFor($user) === self::AUDIENCE_MEMBER || self::guestsEnabled();
    }

    /** مزوّد خارجي جاهز؟ (مفعّل + اسم نموذج + مفتاح للمزوّد الحقيقي). لا يكشف المفتاح. */
    public static function providerReady(): bool
    {
        return self::providerEnabled() && app(Providers\AiProviderRegistry::class)->resolve()?->isConfigured() === true;
    }

    private static function clamp(string $key, int $default, int $min, int $max): int
    {
        $value = config('tatawwur_ai.limits.'.$key);

        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }
}
