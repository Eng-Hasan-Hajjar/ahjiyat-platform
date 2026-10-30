<?php

namespace App\Http\Requests\SeasonalRecognition;

use App\Models\SeasonSupporterPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E44 - طلب بطاقة داعم. المقبول فقط: tier (هوية الباقة)، الخصوصية، الاسم المعروض، ومفتاح عدم التكرار. **السعر والعملة والنقاط والنمط لا تُقرأ من العميل أبدًا**:
 * الخادم يقرؤها من الباقة ويجمّدها في لقطة البطاقة. أي حقل آخر يُتجاهل كليًا.
 */
class RequestSupporterCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tier' => ['required', 'string', Rule::in(SeasonSupporterPackage::TIERS)],
            'visibility' => ['required', 'in:public,anonymous'],
            'display_name' => ['nullable', 'string', 'max:'.(int) config('payments.checkout.display_name_max', 80)],
            'request_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['tier' => 'البطاقة', 'visibility' => 'الظهور', 'display_name' => 'الاسم المعروض'];
    }
}
