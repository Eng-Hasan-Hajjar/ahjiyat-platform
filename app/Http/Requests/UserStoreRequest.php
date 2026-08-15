<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            'email' => 'required|email|unique:users,email',

            'password' => 'required|min:8|confirmed',

            'roles' => 'required|array|min:1',
            'roles.*' => 'exists:roles,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'يجب إدخال اسم المستخدم',

            'email.required' => 'يجب إدخال البريد الإلكتروني',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة',
            'email.unique' => 'هذا البريد مستخدم مسبقاً',

            'password.required' => 'يجب إدخال كلمة المرور',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'كلمة المرور غير متطابقة مع التأكيد',

            'roles.required' => 'يجب اختيار دور واحد على الأقل',
            'roles.min' => 'يجب اختيار دور واحد على الأقل',
        ];
    }
}