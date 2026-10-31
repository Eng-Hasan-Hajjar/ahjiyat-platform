<?php

namespace App\Http\Requests\TatawwurAi;

use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Foundation\Http\FormRequest;

/** E45 - طلب سؤال لتطوّر AI. السقف الصلب لطول السؤال من الإعداد؛ لا حقول أخرى تُقبل (النموذج والتعليمات والأدوات خادمية بالكامل). */
class AskTatawwurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');

        if (is_string($message)) {
            $this->merge(['message' => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:'.TatawwurSettings::maxInputChars()],
            'conversation' => ['nullable', 'string', 'size:26', 'alpha_num'],
            'stream' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.required' => 'اكتب سؤالك أولًا.',
            'message.string' => 'صيغة السؤال غير صالحة.',
            'message.max' => 'سؤالك طويل جدًا. اختصره في بضع جمل.',
        ];
    }
}
