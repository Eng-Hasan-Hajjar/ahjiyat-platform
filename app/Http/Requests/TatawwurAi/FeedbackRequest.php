<?php

namespace App\Http\Requests\TatawwurAi;

use App\Models\AiFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** E45 - تقييم ردّ مساعد: معرّف الرسالة + تقييم مغلق + سبب مغلق اختياري فقط. لا نص حر. */
class FeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'size:26', 'alpha_num'],
            'rating' => ['required', Rule::in(AiFeedback::RATINGS)],
            'reason' => ['nullable', Rule::in(AiFeedback::REASONS)],
        ];
    }
}
