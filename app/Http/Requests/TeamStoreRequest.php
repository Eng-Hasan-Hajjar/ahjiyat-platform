<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** إنشاء فريق: الحقول المسموحة فقط (name, description, visibility, join_policy, max_members). لا owner_id ولا role ولا team_id من العميل: validated() لا يمرّرها. */
class TeamStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', 'in:public,private'],
            'join_policy' => ['nullable', 'in:open,request,invite_only'],
            'max_members' => ['nullable', 'integer', 'min:2', 'max:200'],
        ];
    }
}
