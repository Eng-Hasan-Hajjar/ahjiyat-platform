<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => 'required|string|max:255',

            'email' => 'required|email|unique:users,email,' . $userId,

            'password' => 'nullable|min:8|confirmed',

            'roles' => 'required|array|min:1',
            'roles.*' => 'exists:roles,id',
        ];
    }
}