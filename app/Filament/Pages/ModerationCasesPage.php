<?php

namespace App\Filament\Pages;

use App\Models\ModerationCase;
use App\Services\TrustSafety\CaseService;
use App\Services\TrustSafety\ModerationException;
use App\Services\TrustSafety\PolicyCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/** E38 - طابور العمل: حالاتي / غير المعيَّنة / العاجلة / المتأخرة / المفتوحة / المحسومة. الأهداف الزمنية اقتراحات تشغيل داخلية فقط. */
class ModerationCasesPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'الثقة والسلامة';

    protected static ?string $navigationLabel = 'حالات الإشراف';

    protected static ?string $title = 'حالات الإشراف';

    protected static ?string $slug = 'moderation-cases';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.moderation-cases';

    public string $tab = 'open';

    public string $priority = '';

    public string $category = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trust-safety.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $service = app(CaseService::class);

        return [
            'counts' => $service->queueCounts($user),
            'page' => $service->queue($user, ['view' => $this->tab, 'priority' => $this->priority, 'category' => $this->category, 'page' => $this->getPage()]),
            'categories' => PolicyCatalog::categories(),
            'priorities' => ModerationCase::PRIORITIES,
            'canClaim' => $user->can('trust-safety.manage-cases'),
        ];
    }

    public function claim(string $publicId): void
    {
        $case = ModerationCase::query()->where('public_id', $publicId)->first();

        if ($case === null) {
            return;
        }

        try {
            app(CaseService::class)->claim($case, auth()->user());
            Notification::make()->title('حُجزت الحالة لك.')->success()->send();
        } catch (ModerationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
