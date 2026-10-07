<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** إنشاء تحدّي فريق: الخصم بمعرّفه العام (slug)، الأحجية، وروستر بمعرّفات لاعبين عامة. لا فريق متحدٍّ ولا فائز ولا درجة ولا حالة من العميل: validated() لا يمرّرها. */
class TeamChallengeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'opponent' => ['required', 'string', 'max:80'],
            'puzzle_id' => ['required', 'integer'],
            'roster' => ['required', 'array', 'max:12'],
            'roster.*' => ['string', 'max:30'],
        ];
    }
}
