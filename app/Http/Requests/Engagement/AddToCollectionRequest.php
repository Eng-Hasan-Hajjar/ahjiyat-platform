<?php

namespace App\Http\Requests\Engagement;

use App\Services\Engagement\CollectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** E29 - إضافة أحجية لمجموعة: إما `collection` (معرّف ULID لمجموعة قائمة) أو `new_title` لإنشاء مجموعة جديدة. واحد منهما فقط. */
class AddToCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collection' => ['nullable', 'string', 'size:26', 'alpha_num'],
            // قواعد عنوان المجموعة نفسها (مصدر واحد) لكن اختيارية هنا: الحقل إما يُملأ أو يُترك لاختيار مجموعة قائمة.
            'new_title' => collect(CollectionService::rules()['title'])->reject(fn ($rule) => $rule === 'required')->prepend('nullable')->values()->all(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasCollection = filled($this->input('collection'));
            $hasNew = filled($this->input('new_title'));

            if ($hasCollection === $hasNew) {
                $v->errors()->add('collection', 'اختر مجموعة قائمة أو اكتب عنوانًا لمجموعة جديدة (أحدهما فقط).');
            }
        });
    }

    public function attributes(): array
    {
        return ['collection' => 'المجموعة', 'new_title' => 'عنوان المجموعة الجديدة'];
    }
}
