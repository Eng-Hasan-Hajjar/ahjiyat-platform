<?php

namespace App\Http\Requests\Collections;

use App\Models\UserCollectionReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** E35 - بلاغ عن مجموعة: سبب من قائمة مغلقة فقط (لا نص حر). */
class CollectionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', Rule::in(array_keys(UserCollectionReport::REASONS))]];
    }

    public function attributes(): array
    {
        return ['reason' => 'سبب البلاغ'];
    }
}
