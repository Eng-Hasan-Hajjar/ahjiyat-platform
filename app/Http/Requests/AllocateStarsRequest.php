<?php

namespace App\Http\Requests\SeasonalRecognition;

use App\Models\SeasonRecognitionSetting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E44 - توزيع نجوم على كاتب. الحقل المقبول الوحيد: stars (0..10). الكاتب من المسار، والناخب من الجلسة، والميزانية والأهلية والنافذة يحسمها الخادم في الخدمة - لا يُقرأ أيٌّ منها من الجسم.
 */
class AllocateStarsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['stars' => ['required', 'integer', 'between:0,'.SeasonRecognitionSetting::HARD_MAX_STARS]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['stars' => 'عدد النجوم'];
    }
}
