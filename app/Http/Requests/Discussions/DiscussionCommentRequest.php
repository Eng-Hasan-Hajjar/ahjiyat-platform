<?php

namespace App\Http\Requests\Discussions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E36 - نص تعليق/رد/تعديل. الطلب يحمل **النص وحده**: لا user_id ولا status ولا likes_count ولا parent (الأب من المسار). التحقق الدلالي (الطول بعد التطبيع، لا وسوم، لا روابط…)
 * في DiscussionBody داخل الخدمة؛ هنا سقف خام للحمولة فقط (لا نعالج نصًا بحجم ميجابايت). الأخطاء في حزمة مسماة discussion لتظهر عند نموذج النقاش لا في نماذج الصفحة الأخرى.
 */
class DiscussionCommentRequest extends FormRequest
{
    protected $errorBag = 'discussion';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب نص التعليق.',
            'body.string' => 'نص التعليق غير صالح.',
            'body.max' => 'نص التعليق أطول من المسموح.',
        ];
    }

    public function attributes(): array
    {
        return ['body' => 'نص التعليق'];
    }
}
