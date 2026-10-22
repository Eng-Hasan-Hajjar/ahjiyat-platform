<?php

namespace App\Filament\Pages;

use App\Services\Discussions\DiscussionAnalytics;
use Filament\Pages\Page;

/**
 * E36 - تحليلات النقاشات للمشرفين (discussions.view). أرقام مجمَّعة محدودة ومخزَّنة مؤقتًا (discussions.analytics.cache_seconds) بلا أي بيانات شخصية:
 * لا أسماء كاتبين ولا مبلِّغين ولا نصوص. لا تحليلات لمبدعي المحتوى (قرار E36 المقصود).
 */
class DiscussionsAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'تحليلات النقاشات';

    protected static ?string $title = 'تحليلات النقاشات';

    protected static ?string $slug = 'discussions-analytics';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.pages.discussions-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('discussions.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(DiscussionAnalytics::class)->admin();
    }
}
