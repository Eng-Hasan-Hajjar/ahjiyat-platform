<?php

namespace App\Filament\Pages;

use App\Models\ModerationCase;
use App\Services\TrustSafety\CaseService;
use App\Services\TrustSafety\InboxService;
use App\Services\TrustSafety\ModerationException;
use App\Services\TrustSafety\PolicyCatalog;
use App\Services\TrustSafety\ReportSources;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E38 - صندوق الثقة والسلامة الموحَّد (قراءة فوق بلاغات E21/E28/E35/E36 + إشارات النظام). لا نسخ ولا مزامنة؛ فتح حالة من المحدَّد فقط.
 * المحدَّد مراجع نصية «type:id» تُحقَّق في الخدمة بقائمة بيضاء (لا فئة ولا جدول من العميل). إشارة النظام تُعرض بوسمها دائمًا.
 * لا ترتيب مبلِّغين ولا شهرة/سمعة: عدد البلاغات معلومة فقط.
 */
class TrustSafetyInboxPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'الثقة والسلامة';

    protected static ?string $navigationLabel = 'صندوق البلاغات الموحّد';

    protected static ?string $title = 'صندوق البلاغات الموحّد';

    protected static ?string $slug = 'trust-safety-inbox';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.trust-safety-inbox';

    public string $status = 'open';

    public string $source = '';

    public string $category = '';

    public string $priority = '';

    public string $assigned = '';

    public string $hasCase = '';

    public string $from = '';

    public string $to = '';

    public string $sort = 'oldest';

    public bool $grouped = false;

    /** @var list<string> مراجع type:id للبلاغات المحدَّدة (تُحقَّق في الخدمة) */
    public array $selected = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trust-safety.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $name): void
    {
        if ($name !== 'selected') {
            $this->resetPage();
        }
    }

    /** @return array<string,mixed> */
    protected function filters(): array
    {
        return [
            'status' => $this->status, 'source' => $this->source !== '' ? [$this->source] : [], 'category' => $this->category !== '' ? [$this->category] : [],
            'priority' => $this->priority !== '' ? $this->priority : null, 'assigned' => $this->assigned !== '' ? $this->assigned : null,
            'has_case' => $this->hasCase !== '' ? $this->hasCase : null, 'from' => $this->from, 'to' => $this->to, 'sort' => $this->sort,
            'group' => $this->grouped ? 'subject' : null, 'page' => $this->getPage(),
        ];
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $service = app(InboxService::class);

        return [
            'summary' => $service->summary($user),
            'grouped' => $this->grouped,
            'page' => $this->grouped ? $service->paginateGrouped($user, $this->filters()) : $service->paginate($user, $this->filters()),
            'sources' => collect(ReportSources::all())->map(fn ($d) => $d['label'])->all(),
            'categories' => PolicyCatalog::categories(),
            'priorities' => ModerationCase::PRIORITIES,
            'canOpen' => $user->can('trust-safety.manage-cases'),
        ];
    }

    public function openCase(): void
    {
        try {
            $case = app(CaseService::class)->open(auth()->user(), array_values(array_unique($this->selected)));
        } catch (ModerationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->selected = [];
        Notification::make()->title('فُتحت الحالة '.$case->case_number)->success()->send();
        $this->redirect(ModerationCasePage::getUrl(['record' => $case->public_id]));
    }
}
