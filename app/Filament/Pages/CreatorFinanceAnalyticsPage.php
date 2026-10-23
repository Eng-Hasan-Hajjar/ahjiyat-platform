<?php

namespace App\Filament\Pages;

use App\Services\CreatorFinance\CreatorFinanceAnalytics;
use Filament\Pages\Page;

/** E39 - تحليلات مالية المبدعين (creator-finance.view). أرقام مجمَّعة فقط: مطلوبات ودفعات وفترات؛ لا ترتيب مبدعين ولا هوية فردية. */
class CreatorFinanceAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'مالية المبدعين';

    protected static ?string $navigationLabel = 'تحليلات المالية';

    protected static ?string $title = 'تحليلات مالية المبدعين';

    protected static ?string $slug = 'creator-finance-analytics';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.creator-finance-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('creator-finance.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(CreatorFinanceAnalytics::class)->overview();
    }
}
