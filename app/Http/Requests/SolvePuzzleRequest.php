<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolvePuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'max:255'],
            'used_hint' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'answer.required' => 'الرجاء إدخال إجابتك.',
        ];
    }
}
