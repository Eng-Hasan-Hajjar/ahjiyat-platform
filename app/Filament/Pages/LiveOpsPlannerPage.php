<?php

namespace App\Filament\Pages;

use App\Services\LiveOps\LiveOpsPlannerService;
use Filament\Pages\Page;

/**
 * E34 - مخطِّط LiveOps للمشرفين (liveops.view): قراءة فقط. جدول الأحداث القادمة/الجارية (من التقويم نفسه)، المهام الفعّالة، المجموعات،
 * وتحذيرات تعارض/إفراط. لا يغيّر شيئًا ولا يخزّن؛ التعديل من مجموعات المهام/المهام/الحملات كما هي. لا بيانات لاعبين.
 */
class LiveOpsPlannerPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'مخطِّط LiveOps';

    protected static ?string $title = 'مخطِّط LiveOps';

    protected static ?string $slug = 'liveops-planner';

    protected static ?int $navigationSort = 66;

    protected static string $view = 'filament.pages.liveops-planner';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('liveops.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(LiveOpsPlannerService::class)->overview();
    }
}
