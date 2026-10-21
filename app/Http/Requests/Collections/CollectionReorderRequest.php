<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E35 - ترتيب عناصر مجموعة: إما قائمة معرّفات (order[]، جزئية مسموحة) أو خطوة واحدة (work + direction=up|down). كلها معرّفات ULID عامة؛ ملكية العناصر تُتحقق في الخدمة تحت القفل.
 */
class CollectionReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order' => ['nullable', 'array', 'min:1', 'max:'.(int) config('engagement.collections.max_items')],
            'order.*' => ['string', 'size:26'],
            'work' => ['required_without:order', 'nullable', 'string', 'size:26'],
            'direction' => ['required_with:work', 'nullable', 'in:up,down'],
        ];
    }
}
