<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevealPuzzleSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // التفويض الفعلي (ملكية الجلسة) يتم بالـController عبر Policy مخصصة
        return true;
    }

    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}