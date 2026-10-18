<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/** E32 - رفع/استبدال الإثبات. الملف مطلوب هنا؛ الفحص الحقيقي لمحتواه في PaymentProofService. */
class ProofUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['proof' => ['required', 'file', 'max:'.(int) config('payments.proof.max_kb', 5120)]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['proof' => 'الإثبات'];
    }
}
