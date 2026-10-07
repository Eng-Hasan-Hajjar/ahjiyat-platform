<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** سبب إجراء إشرافي (إخفاء/استعادة) إلزامي (E21-D10). الصلاحية تُفحص بخدمة الإشراف لا بالطلب. */
class ChatModerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:1', 'max:'.(int) config('chat.moderation_reason_max', 200)]];
    }
}
