<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

/** E29 - الحقل الوحيد المقبول `rating` (عدد صحيح بالنطاق). أي حقل آخر بالطلب (متوسط، عدّاد، user_id...) يُتجاهَل: لا يصل لأي كتابة. */
class RateCommunityPuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:'.(int) config('engagement.ratings.min', 1).','.(int) config('engagement.ratings.max', 5)]];
    }

    public function attributes(): array
    {
        return ['rating' => 'التقييم'];
    }
}
