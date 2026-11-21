<?php

namespace App\Filament\Pages;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Services\AhjiyatPass\PassAnalyticsService;
use App\Services\AhjiyatPass\PassReconciler;
use App\Support\Search\ModelPicker;
use Filament\Pages\Page;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

/**
 * E40 - تحليلات المسار (تجميعية فقط: لا قائمة أفراد مرتّبة بالإنفاق ولا بيانات شخصية) + فحص سلامة للقراءة (PassReconciler بلا إصلاح).
 */
class AhjiyatPassAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'التحليلات';

    protected static ?string $title = 'تحليلات مسار الأحجيات';

    protected static ?string $slug = 'ahjiyat-pass-analytics';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.ahjiyat-pass-analytics';

    #[Url(as: 'pass')]
    public ?string $passId = null;

    public bool $checked = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('passes.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** مزوّد قائمة المسارات المقفلة (المنشورة) لمحدّد المسار؛ المعرّف العلني (ULID) هو القيمة. */
    protected function passPicker(): ModelPicker
    {
        return ModelPicker::make(
            fn () => AhjiyatPass::query()->whereNotNull('locked_at'),
            fn (AhjiyatPass $p) => $p->name.' ('.$p->starts_at?->format('Y-m-d').')',
        )->idColumn('public_id')->searchIn(['name'])->orderBy('starts_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * بحث المسارات المنشورة على الخادم (كل المسارات لا أحدث 50 فقط). صلاحية الصفحة نفسها (passes.view).
     *
     * @param  array<string, mixed>  $context
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    #[Renderless]
    public function searchPasses(string $term = '', int $offset = 0, string $current = '', array $context = []): array
    {
        abort_unless(static::canAccess(), 403);

        return $this->passPicker()->search($term, $offset);
    }

    public function runCheck(): void
    {
        $this->checked = true;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        // E48: المحدّد يُبحث على الخادم. المسار المختار يُقرأ مباشرة (كان يُبحث عنه داخل أحدث 50 فقط، فمسار أقدم برابط ?pass= كان يظهر كأن لا مسارات).
        $picker = $this->passPicker();
        $selected = $this->passId !== null && $this->passId !== ''
            ? $picker->find($this->passId)
            : AhjiyatPass::query()->whereNotNull('locked_at')->orderByDesc('starts_at')->orderByDesc('id')->first();
        $stats = $selected !== null ? app(PassAnalyticsService::class)->forPass($selected) : null;
        $missionNames = [];

        if ($stats !== null && $stats['missions'] !== []) {
            $missionNames = AhjiyatPassMission::query()->whereIn('ahjiyat_pass_missions.id', array_keys($stats['missions']))
                ->join('quest_definitions', 'quest_definitions.id', '=', 'ahjiyat_pass_missions.quest_definition_id')
                ->pluck('quest_definitions.name', 'ahjiyat_pass_missions.id')->all();
        }

        return [
            'selected' => $selected,
            'selectedPicked' => $selected !== null ? $picker->describe($selected) : null,
            'stats' => $stats,
            'missionNames' => $missionNames,
            'report' => $this->checked ? app(PassReconciler::class)->run(false) : null,
        ];
    }
}
