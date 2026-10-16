<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorContent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E28 - أساس طلبات التأليف. (1) قواعد النوع من CreatorContent (مصدر واحد مع إعادة التحقق عند الإرسال/الاعتماد).
 * (2) حقول السلطة الممنوعة (مكافآت/يومية/حملات/منافسات/renderer/validation_type/score_mode/game_type...) وجودها بالطلب يُرفض صراحة لا يُتجاهل.
 * (3) القوائم تصل نصًا بسطر لكل عنصر وتُحوَّل لمصفوفات قبل التحقق.
 */
abstract class CreatorContentRequest extends FormRequest
{
    abstract protected function kind(): string;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['choices', 'items', 'faces'] as $key) {
            if ($this->exists($key)) {
                $merge[$key] = CreatorContent::lines($this->input($key));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return app(CreatorContent::class)->rules($this->kind());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) config('creators.forbidden_fields') as $field) {
                if ($this->exists($field)) {
                    $v->errors()->add($field, 'لا يمكنك تحديد هذا الحقل؛ يحدّده النظام.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'title' => 'العنوان', 'puzzle_category_id' => 'التصنيف', 'difficulty' => 'الصعوبة', 'prompt' => 'نص الأحجية', 'hint' => 'التلميح',
            'answer' => 'الجواب', 'choices' => 'الاختيارات', 'choices.*' => 'الاختيار', 'items' => 'العناصر', 'items.*' => 'العنصر', 'faces' => 'البطاقات', 'faces.*' => 'البطاقة', 'image' => 'الصورة',
        ];
    }
}
