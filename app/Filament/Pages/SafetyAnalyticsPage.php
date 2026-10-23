<?php

namespace App\Filament\Pages;

use App\Services\TrustSafety\SafetyAnalytics;
use Filament\Pages\Page;

/**
 * E38 - تحليلات السلامة والاعتراضات (trust-safety.view). أرقام مجمَّعة فقط: لا ترتيب مبلِّغين ولا لوحة متصدرين للمشرفين ولا أداء فردي.
 * المواضيع المتكررة تُعرض بالنوع والرقم وأعداد فقط (لا هوية مبلِّغ).
 */
class SafetyAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'الثقة والسلامة';

    protected static ?string $navigationLabel = 'تحليلات السلامة';

    protected static ?string $title = 'تحليلات السلامة والاعتراضات';

    protected static ?string $slug = 'trust-safety-analytics';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.safety-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trust-safety.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(SafetyAnalytics::class)->admin(auth()->user());
    }
}
