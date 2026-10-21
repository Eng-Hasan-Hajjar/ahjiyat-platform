<?php

namespace App\Filament\Pages;

use App\Services\Collections\CollectionAnalytics;
use Filament\Pages\Page;

/**
 * E35 - تحليلات المجموعات والأرفف للمشرفين (collections.view). أرقام مجمَّعة محدودة ومخزَّنة مؤقتًا (collections.analytics.cache_seconds) بلا أي بيانات شخصية:
 * لا أسماء لاعبين ولا هوية حافظين ولا مشاهدات. قائمة «الأعلى حفظًا» عناوين المجموعات العامة غير المخفية فقط وبعدد محدود.
 */
class CollectionsAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'تحليلات المجموعات';

    protected static ?string $title = 'تحليلات المجموعات والأرفف';

    // المسار لا يحوي «/collections» عمدًا: ثابت E29 (AA6-74) يحصر كل مسار بهذا الاسم خلف وسيط auth العادي، أما لوحة Filament فحارسها الخاص + canAccess().
    protected static ?string $slug = 'curation-analytics';

    protected static ?int $navigationSort = 69;

    protected static string $view = 'filament.pages.collections-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('collections.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(CollectionAnalytics::class)->admin();
    }
}
