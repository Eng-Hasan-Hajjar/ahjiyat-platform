<?php

namespace App\Filament\Pages;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Services\AhjiyatPass\PassAnalyticsService;
use App\Services\AhjiyatPass\PassReconciler;
use Filament\Pages\Page;
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

    public function runCheck(): void
    {
        $this->checked = true;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $passes = AhjiyatPass::query()->whereNotNull('locked_at')->orderByDesc('starts_at')->limit(50)->get(['id', 'public_id', 'name', 'starts_at', 'ends_at']);
        $selected = $this->passId !== null ? $passes->firstWhere('public_id', $this->passId) : $passes->first();
        $stats = $selected !== null ? app(PassAnalyticsService::class)->forPass($selected) : null;
        $missionNames = [];

        if ($stats !== null && $stats['missions'] !== []) {
            $missionNames = AhjiyatPassMission::query()->whereIn('ahjiyat_pass_missions.id', array_keys($stats['missions']))
                ->join('quest_definitions', 'quest_definitions.id', '=', 'ahjiyat_pass_missions.quest_definition_id')
                ->pluck('quest_definitions.name', 'ahjiyat_pass_missions.id')->all();
        }

        return [
            'passes' => $passes,
            'selected' => $selected,
            'stats' => $stats,
            'missionNames' => $missionNames,
            'report' => $this->checked ? app(PassReconciler::class)->run(false) : null,
        ];
    }
}
