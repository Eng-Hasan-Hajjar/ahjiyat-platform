<?php

namespace App\Services\AiAssistant\Dto;

/**
 * E45 - طلب توليد لمزوّد الذكاء (محايد للمزوّد). مدخلاته تُبنى كلها خادميًا: التعليمات ثابتة غير قابلة للتبديل من أي نص مستخدم أو معرفة.
 *
 * input عناصر مرتبة من الأنواع:
 *   ['type'=>'message','role'=>'user'|'assistant','text'=>string]
 *   ['type'=>'provider_items','items'=>array]     عناصر المزوّد الخام من الدور السابق (دعوات الأدوات…)؛ تعاد كما هي
 *   ['type'=>'tool_result','call_id'=>string,'name'=>string,'output'=>string]
 * tools: تعريفات الأدوات المسموحة لهذا الجمهور فقط [{name,description,parameters}]؛ فارغة = لا أدوات.
 */
final class AiRequest
{
    /**
     * @param  list<array<string, mixed>>  $input
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $instructions,
        public readonly array $input,
        public readonly array $tools,
        public readonly string $model,
        public readonly int $maxOutputTokens,
    ) {}

    /** @param list<array<string, mixed>> $input */
    public function withInput(array $input, bool $allowTools = true): self
    {
        return new self($this->requestId, $this->instructions, $input, $allowTools ? $this->tools : [], $this->model, $this->maxOutputTokens);
    }
}
