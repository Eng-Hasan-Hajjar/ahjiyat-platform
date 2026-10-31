<?php

namespace App\Services\AiAssistant\Providers;

use App\Services\AiAssistant\TatawwurSettings;

/**
 * E45 - اختيار المزوّد بالإعداد (AI_PROVIDER). المجهول ⇒ null (لا مزوّد: الاسترجاع المحلي فقط). المزوّد الاختباري «fake» مرفوض خارج local/testing بالكود،
 * فلا يمكن اختياره في الإنتاج بخطأ إعداد. مزوّد جديد مستقبلاً = صنف ينفّذ AiProvider + سطر هنا.
 */
final class AiProviderRegistry
{
    public function resolve(): ?AiProvider
    {
        return match (TatawwurSettings::providerName()) {
            'openai' => app(OpenAiResponsesProvider::class),
            'fake' => app()->environment(['local', 'testing']) ? app(FakeAiProvider::class) : null,
            default => null,
        };
    }
}
