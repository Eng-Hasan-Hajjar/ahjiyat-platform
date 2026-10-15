<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolvePuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('submission') && is_string($this->input('submission'))) {
            $decoded = json_decode((string) $this->input('submission'), true);

            if (is_array($decoded)) {
                $this->merge(['submission' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'answer' => ['required_without:submission', 'nullable', 'string', 'max:255'],
            'submission' => ['sometimes', 'array'],
            // E26: ترتيب الترتيب رموز معتمة نصية (SequenceTokens) لا أعداد؛ وحدود على أحجام المصفوفات كي لا يُرسل العميل حمولات ضخمة.
            'submission.order' => ['sometimes', 'array', 'max:100'],
            'submission.order.*' => ['string', 'max:64'],
            'submission.matches' => ['sometimes', 'array', 'max:500'],
            'submission.moves' => ['sometimes', 'integer'],
            'submission.elapsed_seconds' => ['sometimes', 'integer'],
            'start_token' => ['sometimes', 'string', 'max:2000'],
            'used_hint' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'answer.required_without' => 'الرجاء إدخال إجابتك.',
        ];
    }
}