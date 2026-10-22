<?php

namespace App\Http\Requests\Discussions;

use App\Models\DiscussionCommentReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** E36 - بلاغ عن تعليق: سبب من قائمة مغلقة فقط (لا نص حر، فلا يُحقن محتوى مسيء عبر البلاغ). */
class DiscussionReportRequest extends FormRequest
{
    protected $errorBag = 'discussion';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', Rule::in(array_keys(DiscussionCommentReport::REASONS))]];
    }

    public function messages(): array
    {
        return ['reason.required' => 'اختر سبب البلاغ.', 'reason.in' => 'سبب البلاغ غير صالح.'];
    }

    public function attributes(): array
    {
        return ['reason' => 'سبب البلاغ'];
    }
}
