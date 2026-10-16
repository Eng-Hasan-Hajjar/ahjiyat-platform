<?php

namespace App\Http\Requests\Creators;

use App\Models\CommunityPuzzleReport;
use App\Rules\NoMarkup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportCommunityPuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(array_keys(CommunityPuzzleReport::REASONS))],
            'details' => ['nullable', 'string', 'max:'.config('creators.limits.report_details_max'), new NoMarkup],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'سبب البلاغ', 'details' => 'التفاصيل'];
    }
}
