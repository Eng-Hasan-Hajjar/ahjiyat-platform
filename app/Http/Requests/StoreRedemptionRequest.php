<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gems_amount' => [
                'required',
                'integer',
                'min:'.config('gems.min_redemption'),
                'max:'.($this->user()->wallet?->available_balance ?? 0),
            ],
            'reward_description' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'gems_amount.min' => 'الحد الأدنى للاستبدال هو :min جوهرة.',
            'gems_amount.max' => 'المبلغ المطلوب أكبر من رصيدك المتاح.',
        ];
    }
}
