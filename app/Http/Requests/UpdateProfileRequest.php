<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            // E26: تغيير البريد = بوابة استعادة الحساب، فيتطلب كلمة المرور الحالية (جلسة مسروقة وحدها لا تكفي للاستيلاء على الحساب). الاسم وحده لا يتطلبها.
            'current_password' => [Rule::requiredIf(fn () => $this->emailIsChanging()), 'nullable', 'string', 'current_password:web'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'أدخل كلمة المرور الحالية لتغيير البريد الإلكتروني.',
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
        ];
    }

    public function emailIsChanging(): bool
    {
        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()->email);
    }

    /** الحقول المسموح تعبئتها بالنموذج فقط (current_password للتحقق لا للتخزين). */
    public function profileData(): array
    {
        return $this->safe()->only(['name', 'email']);
    }
}
