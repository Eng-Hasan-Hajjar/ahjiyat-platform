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
        // الألعاب الجديدة (Sequence وما بعدها) ترسل حقل submission كنص JSON
        // من input مخفي - نفكّه هون لمصفوفة عادية قبل التحقق. الأنواع
        // الكلاسيكية لا ترسل هذا الحقل إطلاقاً فيبقى answer وحده كافي.
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
            'submission.order' => ['sometimes', 'array'],
            'submission.order.*' => ['integer'],
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