<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E32 - إنشاء طلب دعم. الحقول المقبولة فقط: المبلغ/العملة/الخصوصية/الاسم/الطريقة/مفتاح عدم التكرار. أي حقل آخر (status/accounting/tier/user_id/program_id…) يُتجاهل كليًا:
 * الخادم لا يقرأه ولا يطبّقه (المتحكم يمرّر الحقول المعتمدة صراحةً، لا $request->all()). البرنامج يأتي من المسار (slug) لا من الجسم.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'],
            'visibility' => ['required', 'in:public,anonymous'],
            'display_name' => ['nullable', 'string', 'max:'.(int) config('payments.checkout.display_name_max', 80)],
            'provider' => ['nullable', 'string', 'max:32'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['amount' => 'المبلغ', 'currency' => 'العملة', 'visibility' => 'الظهور', 'display_name' => 'الاسم المعروض', 'provider' => 'طريقة الدفع'];
    }
}
