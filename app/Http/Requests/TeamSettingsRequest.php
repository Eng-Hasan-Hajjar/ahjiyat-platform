<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** تعديل إعدادات الفريق (للمالك؛ التفويض الحقيقي بالخدمة والسياسة). نفس الحقول المسموحة فقط، كلها اختيارية. */
class TeamSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'in:public,private'],
            'join_policy' => ['sometimes', 'in:open,request,invite_only'],
            'max_members' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:200'],
        ];
    }
}
