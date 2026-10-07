<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** بلاغ عن رسالة (E21-E2): تصنيف من قائمة مغلقة وتفاصيل اختيارية. لا مُبلِّغ ولا رسالة ولا حالة من العميل. */
class ChatReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['category' => ['required', 'string', Rule::in(config('chat.report_categories', []))], 'details' => ['nullable', 'string', 'max:'.(int) config('chat.report_details_max', 500)]];
    }
}
