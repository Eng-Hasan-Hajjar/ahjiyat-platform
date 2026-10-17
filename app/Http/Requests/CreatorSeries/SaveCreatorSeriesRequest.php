<?php

namespace App\Http\Requests\CreatorSeries;

use App\Rules\NoMarkup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E30 - إنشاء/تعديل مسودة سلسلة. نصوص عادية فقط (NoMarkup)، عناصر = معرّفات ULID عامة مرتّبة (الموضع = الترتيب بالمصفوفة، 1..n)،
 * وغلاف اختياري JPEG/PNG/WEBP. أي حقل سلطة/اقتصاد/ظهور بالطلب (config creator_series.forbidden_fields) يُرفض صراحة لا يُتجاهل.
 * الملكية والظهور والتكرار والحدود يعيد فرضها CreatorSeriesService من القاعدة (هذا الطلب أول طبقة فقط).
 */
class SaveCreatorSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $l = config('creator_series.limits');
        $media = config('creators.media');

        return [
            'title' => ['required', 'string', 'min:'.$l['title_min'], 'max:'.$l['title_max'], new NoMarkup],
            'description' => ['nullable', 'string', 'max:'.$l['description_max'], new NoMarkup],
            'items' => ['required', 'array', 'min:'.$l['min_items'], 'max:'.$l['max_items']],
            'items.*' => ['required', 'string', 'ulid', 'distinct'],
            'cover' => [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:'.implode(',', array_keys($media['mimes'])), 'max:'.$media['max_kb'],
                'dimensions:min_width='.$media['min_dimension'].',min_height='.$media['min_dimension'].',max_width='.$media['max_dimension'].',max_height='.$media['max_dimension'],
            ],
            'remove_cover' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) config('creator_series.forbidden_fields') as $field) {
                if ($this->exists($field)) {
                    $v->errors()->add($field, 'لا يمكنك تحديد هذا الحقل؛ يحدّده النظام.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return ['title' => 'عنوان السلسلة', 'description' => 'الوصف', 'items' => 'الأحجيات', 'items.*' => 'الأحجية', 'cover' => 'الغلاف'];
    }

    public function messages(): array
    {
        $l = config('creator_series.limits');

        return [
            'items.required' => "أضف {$l['min_items']} أحجيات على الأقل إلى السلسلة.",
            'items.min' => "أضف {$l['min_items']} أحجيات على الأقل إلى السلسلة.",
            'items.max' => "الحد الأقصى {$l['max_items']} أحجية في السلسلة الواحدة.",
            'items.*.distinct' => 'لا يمكن تكرار الأحجية نفسها داخل السلسلة.',
            'items.*.ulid' => 'معرّف الأحجية غير صالح.',
        ];
    }
}
