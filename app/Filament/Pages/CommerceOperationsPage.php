<?php

namespace App\Filament\Pages;

use App\Services\Commerce\CommerceAnalyticsService;
use App\Services\Commerce\CommerceHealthService;
use App\Services\Commerce\CommerceReconciler;
use Filament\Pages\Page;

/**
 * E41 - عمليات الدفع الإلكتروني (للقراءة فقط): صحة المزوّدين، تحليلات تجميعية بالعملة، أحداث مزوّد غير مرتبطة بطلب، وفحص مطابقة بلا كتابة.
 *  - **لا أسرار**: لا مفتاح ولا سر توقيع ولا token، ولا جزء منها؛ «مُهيَّأ ✅ / ناقص ❌» فقط.
 *  - **لا تعديل** لأي شراء من هنا (الإجراءات في صفحة مشتريات المميز عبر خدمات صريحة).
 *  - لا تحويل عملات ولا مجموع مختلط ولا ترتيب «أكبر منفقين». الصلاحية: passes.view-purchases (لا تضخم صلاحيات).
 */
class CommerceOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'عمليات الدفع';

    protected static ?string $title = 'عمليات الدفع الإلكتروني';

    protected static ?string $slug = 'commerce-operations';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.commerce-operations';

    public bool $checked = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('passes.view-purchases') ?? false;
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
        abort_unless(static::canAccess(), 403);

        $health = app(CommerceHealthService::class);

        return [
            'providers' => $health->providers(),
            'unmatched' => $health->unmatchedEvents(),
            'stats' => app(CommerceAnalyticsService::class)->summary(),
            'report' => $this->checked ? app(CommerceReconciler::class)->run(['limit' => 500]) : null,
        ];
    }
}
