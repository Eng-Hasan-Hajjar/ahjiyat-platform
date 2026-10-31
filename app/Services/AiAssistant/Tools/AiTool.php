<?php

namespace App\Services\AiAssistant\Tools;

use App\Services\AiAssistant\Dto\AiToolResult;

/**
 * E45 - أداة قراءة فقط يستطيع النموذج طلبها. العقد:
 *  - لا تغيّر حالة أبدًا (لا كتابة، لا firstOrCreate، لا أحداث، لا منح)، وتبني مخرجاتها بحقول صريحة (presenter) لا بنموذج Eloquent.
 *  - الوسائط مخطط ضيق: ثلاثة أنواع فقط (string/integer/enum) بحدود طول صريحة، وكل مفتاح غير معلن يُرفض. ممنوع أي وسيط يدل على هوية (user_id/email/…).
 *  - أدوات «حسابي» (personal) تأخذ المستخدم من ToolContext فقط.
 *  - الروابط تبنيها الأداة نفسها (مسارات داخلية نسبية).
 */
abstract class AiTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string, array{type: string, description: string, enum?: list<string>, maxLength?: int, minimum?: int, maximum?: int}> */
    public function properties(): array
    {
        return [];
    }

    /** @return list<string> */
    public function required(): array
    {
        return [];
    }

    /** أداة تخص المستخدم المصادَق نفسه فقط. */
    public function personal(): bool
    {
        return false;
    }

    /** مجال المعرفة لتقارير الفجوات (AiKnowledgeArticle::AREAS). */
    public function area(): string
    {
        return 'general';
    }

    /** @param array<string, mixed> $arguments (مُتحقَّق منها مسبقًا بالسجل) */
    abstract public function run(ToolContext $context, array $arguments): AiToolResult;

    /** @return array{name: string, description: string, parameters: array<string, mixed>} */
    final public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => $this->properties() === [] ? new \stdClass : $this->properties(),
                'required' => $this->required(),
                'additionalProperties' => false,
            ],
        ];
    }

    protected function ok(array $data, array $sources = [], array $untrusted = []): AiToolResult
    {
        return AiToolResult::ok($this->name(), $data, $sources, $untrusted);
    }

    protected function notFound(string $message = 'لم أجد ما يطابق طلبك في المنصة.'): AiToolResult
    {
        return AiToolResult::fail($this->name(), 'not_found', $message);
    }

    protected function unavailable(string $message = 'هذه الميزة غير متاحة حاليًا.'): AiToolResult
    {
        return AiToolResult::fail($this->name(), 'unavailable', $message);
    }
}
