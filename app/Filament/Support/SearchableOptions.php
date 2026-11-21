<?php

namespace App\Filament\Support;

use App\Support\Search\TextSearch;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * قوائم Filament المنسدلة الطويلة أو المتزايدة: بحث على الخادم بدل `->limit(100)->pluck()`.
 *
 * المشكلة التي يحلّها: `options(fn () => X::query()->limit(100)->pluck(...))` + `->searchable()` يبحث داخل أحدث 100 فقط في المتصفح،
 * فيستحيل الوصول إلى السجل رقم 101 مهما كُتب. هنا:
 *  - عند الفتح بلا نص: عدد صغير فقط من السجلات (INITIAL) بترتيب الاستعلام نفسه، لا القائمة كاملة.
 *  - عند الكتابة: بحث على الخادم ضمن **كل** السجلات المسموح بها (نفس الاستعلام/النطاق) قبل LIMIT، بنفس مطابقة محدّد الوسائط (TextSearch).
 *  - القيمة المحفوظة تظهر دائمًا باسمها حتى لو كانت قديمة أو خارج النطاق الحالي (لا تتحول إلى رقم أو تختفي عند التعديل).
 *  - الصلاحيات والفلاتر التابعة تبقى في استعلام النطاق الذي يمرّره المستدعي (يستلم Get لقراءة حقول أخرى في النموذج).
 *  - يعمل مع الاختيار المتعدد (getOptionLabelsUsing) ومع الحقول الإلزامية/الافتراضية كما كانت (لا يغيّر required/default/rules).
 *
 * الاستعمال:
 *   SearchableOptions::make(fn (Get $get) => Puzzle::query()->official()->where('is_active', true), label: 'title')
 *       ->apply(Forms\Components\Select::make('puzzle_id')->label('الأحجية')->required());
 */
final class SearchableOptions
{
    /** عدد الخيارات التي تظهر عند فتح القائمة قبل أي كتابة. */
    public const INITIAL = 20;

    /** أقصى عدد نتائج بحث يعاد في كل طلب. */
    public const RESULTS = 30;

    public const DEBOUNCE_MS = 300;

    /** @var Closure(Get): Builder */
    private Closure $query;

    /** @var string|Closure(Model): string */
    private string|Closure $label;

    /** @var list<string> */
    private array $searchColumns;

    /** @var (Closure(Builder, list<string>): void)|null */
    private ?Closure $searchUsing = null;

    private bool $newestFirst = false;

    /**
     * @param  Closure(Get): Builder  $query  الاستعلام الأساسي المسموح به (نطاق + صلاحيات + اعتماد على حقول أخرى عبر Get).
     * @param  string|Closure(Model): string  $label  اسم العمود، أو دالة تبني النص من النموذج.
     * @param  list<string>|null  $searchColumns  أعمدة البحث؛ الافتراضي عمود العنوان إن كان $label اسم عمود.
     */
    public static function make(Closure $query, string|Closure $label = 'name', ?array $searchColumns = null): self
    {
        $self = new self;
        $self->query = $query;
        $self->label = $label;
        $self->searchColumns = $searchColumns ?? (is_string($label) ? [$label] : []);

        return $self;
    }

    /**
     * محدّد «العنصر المرتبط» المشترك بين العملة وعنصر المتجر: موسم رسمي (بالرمز) أو حملة (بالعنوان) بحسب حقل النوع المجاور.
     * لا يغيّر نوع النطاق ولا القيم المحفوظة؛ يستبدل فقط تحميل كل المواسم/الحملات بالبحث على الخادم.
     */
    public static function seasonOrCampaign(string $typeField = 'scope_type'): self
    {
        return self::make(
            fn (Get $get) => match ($get($typeField)) {
                'season' => \App\Models\Season::query(),
                'campaign' => \App\Models\Campaign::query(),
                default => \App\Models\Campaign::query()->whereRaw('1 = 0'),
            },
            fn (Model $m) => (string) ($m instanceof \App\Models\Season ? $m->code : $m->title),
        )->newestFirst()->searchUsing(fn (Builder $q, array $tokens) => TextSearch::contains($q, $q->getModel() instanceof \App\Models\Season ? 'code' : 'title', $tokens));
    }

    /** الأحدث أولًا (id تنازليًا) بدل الترتيب الأبجدي. */
    public function newestFirst(): self
    {
        $this->newestFirst = true;

        return $this;
    }

    /**
     * بحث مخصّص حين لا يكفي عمود مباشر (مثل البحث في عنوان علاقة).
     * الدالة تضيف شروطها على الاستعلام وتستلم كلمات البحث (AND لكل كلمة).
     *
     * @param  Closure(Builder, list<string>): void  $callback
     */
    public function searchUsing(Closure $callback): self
    {
        $this->searchUsing = $callback;

        return $this;
    }

    /** يطبّق البحث على الخادم على حقل Select (وبقية سلاسل الحقل كما هي: required, default, multiple, live, ...). */
    public function apply(Select $select): Select
    {
        return $select
            ->searchable()
            ->native(false)
            ->searchDebounce(self::DEBOUNCE_MS)
            ->searchPrompt('اكتب للبحث…')
            ->searchingMessage('جارٍ البحث…')
            ->noSearchResultsMessage('لا توجد نتائج تطابق بحثك.')
            ->optionsLimit(self::RESULTS)
            ->options(fn (Get $get): array => $this->initial($get))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->results($search, $get))
            ->getOptionLabelUsing(fn ($value, Get $get): ?string => $this->labelFor($value, $get))
            ->getOptionLabelsUsing(fn (array $values, Get $get): array => $this->labelsFor($values, $get));
    }

    /** @return array<int|string, string> */
    public function initial(Get $get): array
    {
        return $this->pluck($this->ordered(($this->query)($get))->limit(self::INITIAL)->get());
    }

    /** @return array<int|string, string> */
    public function results(string $search, Get $get): array
    {
        $tokens = TextSearch::tokens($search);
        $query = ($this->query)($get);

        if ($tokens !== []) {
            if ($this->searchUsing !== null) {
                ($this->searchUsing)($query, $tokens);
            } elseif ($this->searchColumns !== []) {
                TextSearch::contains($query, $this->searchColumns, $tokens);
            }
        }

        return $this->pluck($this->ordered($query)->limit(self::RESULTS)->get());
    }

    /** اسم القيمة المحفوظة: بلا نطاق ولا حدّ، فيظهر الخيار القديم/المسحوب باسمه بدل الرقم. */
    public function labelFor(mixed $value, Get $get): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $base = ($this->query)($get)->getModel()->newQuery();
        $model = $base->whereKey($value)->first();

        return $model !== null ? $this->text($model) : '#'.$value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int|string, string>
     */
    public function labelsFor(array $values, Get $get): array
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));

        if ($values === []) {
            return [];
        }

        $found = $this->pluck(($this->query)($get)->getModel()->newQuery()->whereKey($values)->get());
        $labels = [];

        foreach ($values as $value) {
            $labels[$value] = $found[$value] ?? '#'.$value;
        }

        return $labels;
    }

    private function ordered(Builder $query): Builder
    {
        if ($this->newestFirst) {
            return $query->orderByDesc($query->getModel()->getQualifiedKeyName());
        }

        if (is_string($this->label)) {
            return $query->orderBy($this->label)->orderBy($query->getModel()->getQualifiedKeyName());
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * @param  iterable<Model>  $models
     * @return array<int|string, string>
     */
    private function pluck(iterable $models): array
    {
        $out = [];

        foreach ($models as $model) {
            $out[$model->getKey()] = $this->text($model);
        }

        return $out;
    }

    private function text(Model $model): string
    {
        $text = is_string($this->label) ? (string) $model->getAttribute($this->label) : (string) ($this->label)($model);

        return $text !== '' ? $text : '#'.$model->getKey();
    }
}
