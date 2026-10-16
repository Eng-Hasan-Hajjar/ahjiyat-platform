<?php

namespace App\Http\Requests\Creators;

use App\Rules\NoMarkup;
use Illuminate\Foundation\Http\FormRequest;

class CreatorApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $l = config('creators.limits');

        return ['bio' => ['required', 'string', 'min:'.$l['bio_min'], 'max:'.$l['bio_max'], new NoMarkup]];
    }

    public function attributes(): array
    {
        return ['bio' => 'النبذة'];
    }
}
