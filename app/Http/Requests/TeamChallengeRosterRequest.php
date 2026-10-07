<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** اختيار روستر (للقبول أو تعديل روستر المتحدّي قبل القفل): معرّفات لاعبين عامة فقط. الأهلية والحدّان تتحقق منهما الخدمة من قاعدة البيانات. */
class TeamChallengeRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'roster' => ['required', 'array', 'max:12'],
            'roster.*' => ['string', 'max:30'],
        ];
    }
}
