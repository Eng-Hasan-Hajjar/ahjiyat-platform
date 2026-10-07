<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * نص رسالة دردشة (E21-K5/K6): **النص وحده** يأتي من العميل. المرسل من المصادَقة والغرفة من المسار المخوَّل (sender_id/thread_id/team_id وأي حقل إشراف بالطلب تُتجاهل: validated() لا يمرّرها).
 * الحد الأقصى من config/chat.php؛ القص ورفض الفارغ/المسافات يتمّان بالخدمة (ChatMessageService::normalize).
 */
class ChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.((int) config('chat.message_max_length', 2000) + 200)]];     // هامش للمسافات الطرفية: الحد الدقيق بعد القص بالخدمة
    }
}
