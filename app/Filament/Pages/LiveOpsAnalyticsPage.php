<?php

namespace App\Filament\Pages;

use App\Services\LiveOps\RetentionAnalytics;
use Filament\Pages\Page;

/**
 * E34 - تحليلات الاحتفاظ للمشرفين (liveops.view). مجاميع فقط على اللعب الرسمي الصحيح (لا الدخول)؛ لا صفوف لاعبين ولا أسماء.
 */
class LiveOpsAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'تحليلات الاحتفاظ';

    protected static ?string $title = 'تحليلات الاحتفاظ والمهام';

    protected static ?string $slug = 'liveops-analytics';

    protected static ?int $navigationSort = 67;

    protected static string $view = 'filament.pages.liveops-analytics';

    public int $days = 30;

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
        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;

        return ['overview' => app(RetentionAnalytics::class)->overview($days), 'days' => $days];
    }
}
