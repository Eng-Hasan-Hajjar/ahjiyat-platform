<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorContent;
use Illuminate\Validation\Rule;

class StoreCreatorPuzzleRequest extends CreatorContentRequest
{
    protected function kind(): string
    {
        return (string) $this->input('kind');
    }

    public function rules(): array
    {
        return ['kind' => ['required', 'string', Rule::in(CreatorContent::kinds())]] + parent::rules();
    }

    public function attributes(): array
    {
        return parent::attributes() + ['kind' => 'نوع الأحجية'];
    }

    public function messages(): array
    {
        return ['kind.in' => 'نوع الأحجية غير مدعوم.'];
    }
}
