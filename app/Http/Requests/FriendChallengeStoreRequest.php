<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** اختيار الأحجية الهدف فقط. الصداقة والأهلية والتفرّد تُفحص بالخدمة؛ الخصم من المسار لا من النموذج. */
class FriendChallengeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['puzzle_id' => ['required', 'integer', 'min:1']];
    }
}
