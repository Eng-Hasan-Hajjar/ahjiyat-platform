<?php

namespace App\Support\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * مزوّد بيانات عام للمكوّن المشترك <x-searchable-select> (Blade + Livewire): بحث على الخادم + ترقيم «تحميل المزيد» + وصف الاختيار الحالي.
 *
 * يطابق عقد دالة الخادم في المكوّن: ['items' => [...], 'total' => int, 'next' => ?int]، وكل عنصر:
 *   ['id','name','parts'=>[],'dist'=>[],'retired'=>bool,'current'=>bool,'note'=>?string].
 *
 * القواعد:
 *  - البحث ضمن **كل** السجلات المسموح بها (استعلام النطاق الذي يمرّره المستدعي، ومنه الصلاحيات) قبل LIMIT.
 *  - الأسماء المكررة تُميَّز بنص إضافي (dist) يحسبه المستدعي، ولا يُكشف أي عمود آخر.
 *  - معرّف العنصر عمود المفتاح الافتراضي أو عمود يحدده المستدعي (مثل public_id).
 *  - الصلاحية يفحصها المستدعي (دالة Livewire) قبل الاستدعاء.
 */
final class ModelPicker
{
    public const PAGE_SIZE = 10;

    /** @var Closure(): Builder */
    private Closure $query;

    /** @var Closure(Model): string */
    private Closure $label;

    /** @var list<string> */
    private array $searchColumns = [];

    /** @var (Closure(Builder, list<string>): void)|null */
    private ?Closure $searchUsing = null;

    private string $idColumn = '';

    /** @var list<array{0: string, 1: string}> */
    private array $orderBy = [];

    private ?string $duplicateBy = null;

    /** @var (Closure(Model): string)|null */
    private ?Closure $distinguish = null;

    /** @var (Closure(Model): list<string>)|null */
    private ?Closure $parts = null;

    /**
     * @param  Closure(): Builder  $query  الاستعلام المسموح به (نطاق + صلاحيات).
     * @param  Closure(Model): string  $label  نص الاسم المعروض.
     */
    public static function make(Closure $query, Closure $label): self
    {
        $self = new self;
        $self->query = $query;
        $self->label = $label;

        return $self;
    }

    /** @param  list<string>  $columns أعمدة البحث (تكفي مطابقة أيٍّ منها لكل كلمة). */
    public function searchIn(array $columns): self
    {
        $this->searchColumns = $columns;

        return $this;
    }

    /** @param  Closure(Builder, list<string>): void  $callback بحث مخصّص (علاقات مثلًا)؛ يستلم كلمات البحث (AND). */
    public function searchUsing(Closure $callback): self
    {
        $this->searchUsing = $callback;

        return $this;
    }

    /** عمود المعرّف المرسَل للمكوّن (الافتراضي مفتاح النموذج). */
    public function idColumn(string $column): self
    {
        $this->idColumn = $column;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[] = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    /**
     * تمييز الأسماء المكررة: يُحسب التكرار على عمود $column ضمن النطاق نفسه، ويُعرض نص $text(Model) على كل نسخة مكررة.
     *
     * @param  Closure(Model): string  $text
     */
    public function distinguishDuplicates(string $column, Closure $text): self
    {
        $this->duplicateBy = $column;
        $this->distinguish = $text;

        return $this;
    }

    /** @param  Closure(Model): list<string>  $parts سطر الخصائص تحت الاسم. */
    public function parts(Closure $parts): self
    {
        $this->parts = $parts;

        return $this;
    }

    public function exists(): bool
    {
        return ($this->query)()->exists();
    }

    /** يجلب عنصرًا واحدًا ضمن النطاق المسموح به (null إن لم يكن ضمنه). */
    public function find(string|int $id): ?Model
    {
        $query = ($this->query)();

        return $this->idColumn !== '' ? $query->where($this->idColumn, $id)->first() : $query->whereKey($id)->first();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    public function search(string $term = '', int $offset = 0): array
    {
        $offset = max(0, min($offset, 100000));
        $tokens = TextSearch::tokens($term);
        $query = ($this->query)();

        if ($tokens !== []) {
            if ($this->searchUsing !== null) {
                ($this->searchUsing)($query, $tokens);
            } elseif ($this->searchColumns !== []) {
                TextSearch::contains($query, $this->searchColumns, $tokens);
            }
        }

        $total = (clone $query)->count();
        $page = clone $query;

        foreach ($this->orderBy ?: [[$page->getModel()->getKeyName(), 'desc']] as [$column, $direction]) {
            $page->orderBy($page->getModel()->qualifyColumn($column), $direction);
        }

        $rows = $page->orderBy($page->getModel()->getQualifiedKeyName())->offset($offset)->limit(self::PAGE_SIZE)->get();
        $dups = $this->duplicates($rows->all());
        $items = $rows->map(fn (Model $m) => $this->describe($m, false, $dups))->all();
        $consumed = $offset + count($items);

        return ['items' => $items, 'total' => $total, 'next' => $consumed < $total ? $consumed : null];
    }

    /**
     * وصف عنصر واحد للمكوّن (الاختيار الحالي المعروض قبل أي بحث).
     *
     * @param  array<string, true>|null  $dups
     * @return array<string, mixed>
     */
    public function describe(Model $model, bool $current = true, ?array $dups = null): array
    {
        $dups ??= $this->duplicates([$model]);
        $isDup = $this->duplicateBy !== null && isset($dups[(string) $model->getAttribute($this->duplicateBy)]);

        return [
            'id' => (string) ($this->idColumn !== '' ? $model->getAttribute($this->idColumn) : $model->getKey()),
            'name' => ($this->label)($model),
            'parts' => $this->parts !== null ? array_values(array_filter(($this->parts)($model), fn ($p) => $p !== null && $p !== '')) : [],
            'dist' => $isDup && $this->distinguish !== null ? [($this->distinguish)($model)] : [],
            'retired' => false,
            'current' => $current,
            'note' => null,
        ];
    }

    /**
     * @param  list<Model>  $models
     * @return array<string, true> قيم عمود التكرار التي تتكرر ضمن النطاق.
     */
    private function duplicates(array $models): array
    {
        if ($this->duplicateBy === null || $models === []) {
            return [];
        }

        $values = array_values(array_unique(array_filter(array_map(fn (Model $m) => $m->getAttribute($this->duplicateBy), $models), fn ($v) => $v !== null && $v !== '')));

        if ($values === []) {
            return [];
        }

        return ($this->query)()->whereIn($this->duplicateBy, $values)->groupBy($this->duplicateBy)->havingRaw('count(*) > 1')
            ->pluck($this->duplicateBy)->mapWithKeys(fn ($v) => [(string) $v => true])->all();
    }
}
