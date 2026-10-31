<?php

namespace App\Services\AiAssistant\Conversation;

use App\Models\AiRequestMetric;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Support\Facades\Cache;

/**
 * E45 - ميزانية الرموز اليومية العامة (AI_GLOBAL_DAILY_TOKEN_LIMIT). عدّاد في الكاش بمفتاح اليوم (بتوقيت التطبيق) يُعاد بناؤه من ai_request_metrics عند غيابه،
 * فيصمد لإعادة تشغيل الكاش. تجاوزها ⇒ ردّ «مشغول» من المنسّق بلا استدعاء مزوّد (لا 500).
 */
final class AiBudget
{
    public function used(): int
    {
        $key = $this->key();
        $cached = Cache::get($key);

        if (is_numeric($cached)) {
            return (int) $cached;
        }

        $sum = (int) AiRequestMetric::query()->where('created_at', '>=', now()->startOfDay())->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as t')->value('t');
        Cache::put($key, $sum, now()->addDays(2));

        return $sum;
    }

    public function add(int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $this->used();
        Cache::increment($this->key(), $tokens);
    }

    public function exceeded(): bool
    {
        return $this->used() >= TatawwurSettings::globalDailyTokenLimit();
    }

    private function key(): string
    {
        return 'tatawwur:ai:tokens:'.now()->format('Ymd');
    }
}
