<?php

namespace App\Services\AiAssistant\Conversation;

use App\Models\AiRequestMetric;
use Throwable;

/**
 * E45 - تسجيل مقاييس تشغيلية **خالية من المحتوى**: الجمهور (member|guest) والنتيجة المغلقة والرموز وزمن الاستجابة فقط. لا نص سؤال ولا ردّ ولا هوية مستخدم.
 * فشل التسجيل لا يكسر الردّ أبدًا. لا تسجيل في سجلات التطبيق (Log) لأي محتوى.
 */
final class AiMetrics
{
    public function __construct(private readonly AiBudget $budget) {}

    public function record(AssistantAnswer $answer, string $audience): void
    {
        try {
            AiRequestMetric::query()->forceCreate([
                'audience' => $audience === 'member' ? 'member' : 'guest',
                'provider' => $answer->provider,
                'model' => $answer->model !== null ? mb_substr($answer->model, 0, 64) : null,
                'outcome' => in_array($answer->outcome, AiRequestMetric::OUTCOMES, true) ? $answer->outcome : 'error',
                'reason' => $answer->reason !== null ? mb_substr($answer->reason, 0, 24) : null,
                'area' => $answer->area !== null ? mb_substr($answer->area, 0, 24) : null,
                'grounded' => $answer->grounded,
                'streamed' => $answer->streamed,
                'tool_calls' => min(255, $answer->toolCalls),
                'input_tokens' => $answer->usage->inputTokens,
                'output_tokens' => $answer->usage->outputTokens,
                'latency_ms' => min(4294967295, max(0, $answer->latencyMs)),
                'error_category' => $answer->errorCategory,
                'created_at' => now(),
            ]);

            $this->budget->add($answer->usage->total());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
