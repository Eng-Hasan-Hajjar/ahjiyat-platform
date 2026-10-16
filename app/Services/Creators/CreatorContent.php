<?php

namespace App\Services\Creators;

use App\Models\CreatorPuzzleRevision;
use App\Models\PuzzleCategory;
use App\Rules\NoMarkup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * E28 - مخطط حمولة التأليف لكل نوع مسموح (قائمة السماح بـconfig/creators.php): القواعد + التطبيع. مصدر واحد تستعمله نماذج الطلبات (الإدخال) وإعادة التحقق عند الإرسال/الاعتماد (المخزَّن).
 * المبدع يتحكم بالعنوان/التصنيف/الصعوبة/النص/التلميح/الاختيارات/الجواب/الصورة وإعدادات اللعبة المهيكلة فقط؛ renderer/validation_type/score_mode يشتقّها المحرك (GameTypeRegistry) بمادّة CreatorPuzzleMaterializer.
 */
class CreatorContent
{
    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys((array) config('creators.kinds'));
    }

    public static function kindConfig(string $kind): ?array
    {
        return config("creators.kinds.{$kind}");
    }

    /** قواعد الحقول المشتركة + حقول النوع. $stored=true: بلا حقل رفع الصورة (إعادة تحقق من حمولة محفوظة). */
    public function rules(string $kind, bool $stored = false): array
    {
        $l = (array) config('creators.limits');
        $media = (array) config('creators.media');

        $rules = [
            'title' => ['required', 'string', 'min:'.$l['title_min'], 'max:'.$l['title_max'], new NoMarkup],
            'puzzle_category_id' => ['required', 'integer', Rule::exists('puzzle_categories', 'id')->where('is_active', true)],
            'difficulty' => ['required', Rule::in(config('creators.difficulties'))],
            'prompt' => ['required', 'string', 'min:'.$l['prompt_min'], 'max:'.$l['prompt_max'], new NoMarkup],
            'hint' => ['nullable', 'string', 'max:'.$l['hint_max'], new NoMarkup],
        ];

        $rules += match ($kind) {
            'text' => ['answer' => $this->answerRules()],
            'image' => ['answer' => $this->answerRules()],
            'multiple_choice' => [
                'answer' => $this->answerRules(),
                'choices' => ['required', 'array', 'min:'.$l['choices_min'], 'max:'.$l['choices_max']],
                'choices.*' => ['required', 'string', 'max:'.$l['choice_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            'sequence' => [
                'items' => ['required', 'array', 'min:'.$l['sequence_min'], 'max:'.$l['sequence_max']],
                'items.*' => ['required', 'string', 'max:'.$l['sequence_item_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            'memory' => [
                'faces' => ['required', 'array', 'min:'.$l['memory_min'], 'max:'.$l['memory_max']],
                'faces.*' => ['required', 'string', 'max:'.$l['memory_face_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            default => [],
        };

        if (! $stored && (self::kindConfig($kind)['media'] ?? false)) {
            $rules['image'] = [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:'.implode(',', array_keys($media['mimes'])), 'max:'.$media['max_kb'],
                'dimensions:min_width='.$media['min_dimension'].',min_height='.$media['min_dimension'].',max_width='.$media['max_dimension'].',max_height='.$media['max_dimension'],
            ];
        }

        return $rules;
    }

    protected function answerRules(): array
    {
        return ['required', 'string', 'max:'.config('creators.limits.answer_max'), new NoMarkup];
    }

    /** حمولة التأليف المطبَّعة (تُشفَّر بالنسخة) من مدخلات مُتحقَّق منها. */
    public function normalize(string $kind, array $input): array
    {
        $content = ['prompt' => $this->clean($input['prompt'] ?? ''), 'hint' => $this->nullable($input['hint'] ?? null)];

        switch ($kind) {
            case 'text':
            case 'image':
                $content['answer'] = $this->clean($input['answer'] ?? '');
                break;
            case 'multiple_choice':
                $content['choices'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['choices'] ?? [])));
                $content['answer'] = $this->clean($input['answer'] ?? '');
                break;
            case 'sequence':
                $content['items'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['items'] ?? [])));
                break;
            case 'memory':
                $content['faces'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['faces'] ?? [])));
                break;
        }

        return $content;
    }

    /** يحوّل نص «سطر لكل عنصر» إلى قائمة (يُستعمل قبل التحقق). */
    public static function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value), fn ($v) => $v !== '' && $v !== null));
        }

        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), fn ($v) => $v !== ''));
    }

    /**
     * جاهزية الإرسال/الاعتماد: إعادة تحقق كاملة من الحمولة **المخزَّنة** (دفاع ثانٍ: لا نثق بأن الحفظ السابق مرّ بالقواعد نفسها) +
     * شرط خاص: نوع الصورة يتطلب صورة موجودة فعلًا + الجواب ضمن الاختيارات.
     *
     * @throws CreatorException
     */
    public function assertReady(CreatorPuzzleRevision $revision): void
    {
        $kind = (string) $revision->kind;

        if (! in_array($kind, self::kinds(), true)) {
            throw new CreatorException('نوع الأحجية غير مدعوم.');
        }

        $content = (array) $revision->content;
        $data = [
            'title' => $revision->title,
            'puzzle_category_id' => $revision->puzzle_category_id,
            'difficulty' => $revision->difficulty,
        ] + $content;

        $validator = Validator::make($data, $this->rules($kind, stored: true));

        if ($validator->fails()) {
            throw new CreatorException((string) $validator->errors()->first());
        }

        if ($kind === 'multiple_choice' && ! in_array((string) ($content['answer'] ?? ''), (array) ($content['choices'] ?? []), true)) {
            throw new CreatorException('الجواب الصحيح يجب أن يكون أحد الاختيارات.');
        }

        if ((self::kindConfig($kind)['media'] ?? false)) {
            if (blank($revision->image_path) || ! Storage::disk((string) config('creators.media.disk'))->exists((string) $revision->image_path)) {
                throw new CreatorException('أضف صورة للأحجية قبل الإرسال.');
            }
        } elseif (filled($revision->image_path)) {
            throw new CreatorException('هذا النوع لا يدعم الصور.');
        }

        if (PuzzleCategory::query()->whereKey($revision->puzzle_category_id)->where('is_active', true)->doesntExist()) {
            throw new CreatorException('التصنيف المختار غير متاح.');
        }
    }

    protected function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    protected function nullable(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value === '' ? null : $value;
    }
}
