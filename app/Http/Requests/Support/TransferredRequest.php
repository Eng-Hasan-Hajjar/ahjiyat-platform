<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/** E32 - «حوّلت المبلغ»: ملاحظة المحوِّل + إثبات اختياري. الفحص الحقيقي لمحتوى الملف (النوع الفعلي/الحجم/الأبعاد) في PaymentProofService؛ هنا حدود خشنة فقط. */
class TransferredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payer_note' => ['nullable', 'string', 'max:'.(int) config('payments.checkout.note_max', 255)],
            'proof' => ['nullable', 'file', 'max:'.(int) config('payments.proof.max_kb', 5120)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['payer_note' => 'الملاحظة', 'proof' => 'الإثبات'];
    }
}
