<?php

namespace App\Http\Requests\Collections;

use App\Models\UserCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** E35 - تغيير ظهور مجموعة. الجعل «عامة» يشترط إقرار المالك بأنها ستظهر في البحث والاكتشاف باسمه (تحذير خصوصية مفروض بالخادم لا بالواجهة وحدها). */
class CollectionVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visibility' => ['required', 'string', Rule::in(UserCollection::VISIBILITIES)],
            'acknowledge' => ['nullable', 'accepted_if:visibility,'.UserCollection::VISIBILITY_PUBLIC],
        ];
    }

    public function attributes(): array
    {
        return ['visibility' => 'ظهور المجموعة', 'acknowledge' => 'الإقرار'];
    }

    public function messages(): array
    {
        return ['acknowledge.accepted_if' => 'أقِرّ بأن المجموعة العامة ستظهر باسمك في البحث والاكتشاف لتتمكن من نشرها.'];
    }
}
