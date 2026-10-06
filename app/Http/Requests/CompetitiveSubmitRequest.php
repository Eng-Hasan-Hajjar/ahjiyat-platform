<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * إجابة لعب تنافسي فقط: answer وsubmission (order/matches/moves). لا تُقبل ولا تُقرأ أي score/winner/rank/duration/start_token/used_hint
 * من العميل: هذه الطلبات تُصفّى إلى الإجابة وحدها، والباقي يحسبه السيرفر.
 */
class CompetitiveSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('submission') && is_string($this->input('submission'))) {
            $decoded = json_decode((string) $this->input('submission'), true);

            if (is_array($decoded)) {
                $this->merge(['submission' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'answer' => ['required_without:submission', 'nullable', 'string', 'max:255'],
            'submission' => ['sometimes', 'array'],
            'submission.order' => ['sometimes', 'array'],
            'submission.order.*' => ['integer'],
            'submission.matches' => ['sometimes', 'array'],
            'submission.moves' => ['sometimes', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['answer.required_without' => 'اكتب إجابتك أو أكمل اللعبة قبل الإرسال.'];
    }

    /** @return array{answer: string, submission: array} الإجابة وحدها، مهما أرسل العميل. */
    public function answerOnly(): array
    {
        $submission = $this->validated('submission') ?? [];

        return [
            'answer' => (string) ($this->validated('answer') ?? ''),
            'submission' => is_array($submission) ? array_intersect_key($submission, array_flip(['order', 'matches', 'moves'])) : [],
        ];
    }
}
