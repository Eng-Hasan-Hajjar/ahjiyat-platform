<?php

namespace App\Filament\Pages;

use App\Models\CreatorRevenuePeriod;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E39 - فترات الإيراد: إنشاء مسودة بمجمّع صريح، معاينة (قراءة فقط)، إرسال للمراجعة، إقفال بتأكيد صريح، إلغاء بسبب. كل القواعد والصلاحيات في CreatorRevenuePeriodService؛
 * هذه الصفحة تعرض وتستدعي فقط. لا إقفال تلقائي في أي مكان.
 */
class CreatorRevenuePeriodsPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'مالية المبدعين';

    protected static ?string $navigationLabel = 'فترات الإيراد';

    protected static ?string $title = 'فترات إيراد المبدعين';

    protected static ?string $slug = 'creator-revenue-periods';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.creator-revenue-periods';

    public ?string $open = null;

    public bool $showPreview = false;

    public bool $creating = false;

    public string $label = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $pool = '';

    public string $note = '';

    public string $cancelReason = '';

    public bool $confirmFinalize = false;

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
        $user = auth()->user();
        $opened = $this->open !== null ? CreatorRevenuePeriod::query()->where('public_id', $this->open)->first() : null;
        $preview = null;

        if ($opened !== null && $this->showPreview) {
            $preview = app(CreatorRevenuePeriodService::class)->preview($opened);
        }

        return [
            'periods' => CreatorRevenuePeriod::query()->orderByDesc('starts_at')->orderByDesc('id')->paginate((int) config('creator_monetization.list.per_page', 20)),
            'opened' => $opened,
            'preview' => $preview,
            'canManage' => $user->can('creator-finance.manage-periods'),
            'canFinalize' => $user->can('creator-finance.finalize'),
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->showPreview = false;
        $this->creating = false;
        $this->confirmFinalize = false;
        $this->cancelReason = '';
    }

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    public function startCreate(): void
    {
        $this->creating = true;
        $this->open = null;
        $this->label = $this->startsAt = $this->endsAt = $this->pool = $this->note = '';
    }

    public function startEdit(): void
    {
        $period = $this->period();

        if ($period === null || ! $period->isDraft()) {
            return;
        }

        $this->creating = true;
        $this->label = $period->label;
        $this->startsAt = $period->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $period->ends_at->format('Y-m-d\TH:i');
        $this->pool = \App\Services\CreatorFinance\CreatorMoney::toMajorText((int) $period->pool_minor, (string) $period->currency);
        $this->note = (string) $period->note;
    }

    public function save(): void
    {
        $this->run(function () {
            $service = app(CreatorRevenuePeriodService::class);
            $input = ['label' => $this->label, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt, 'pool' => $this->pool, 'note' => $this->note];
            $period = $this->open !== null ? $this->period() : null;

            if ($period !== null && $period->isDraft() && $this->creating) {
                $period = $service->update($period, auth()->user(), $input);
            } else {
                $period = $service->create(auth()->user(), $input);
            }

            $this->open = $period->public_id;
            $this->creating = false;
        }, 'حُفظت الفترة كمسودة.');
    }

    public function submitReview(): void
    {
        $this->run(fn () => app(CreatorRevenuePeriodService::class)->submitForReview($this->mustPeriod(), auth()->user()), 'أُرسلت الفترة للمراجعة.');
    }

    public function sendBack(): void
    {
        $this->run(fn () => app(CreatorRevenuePeriodService::class)->sendBack($this->mustPeriod(), auth()->user()), 'أُعيدت الفترة إلى المسودة.');
    }

    public function cancelPeriod(): void
    {
        $this->run(function () {
            app(CreatorRevenuePeriodService::class)->cancel($this->mustPeriod(), auth()->user(), $this->cancelReason);
            $this->cancelReason = '';
        }, 'أُلغيت الفترة.');
    }

    public function finalizePeriod(): void
    {
        if (! $this->confirmFinalize) {
            Notification::make()->title('أكّد الإقفال أولًا: لا رجعة بعده.')->danger()->send();

            return;
        }

        $this->run(function () {
            app(CreatorRevenuePeriodService::class)->finalize($this->mustPeriod(), auth()->user());
            $this->confirmFinalize = false;
            $this->showPreview = false;
        }, 'أُقفلت الفترة ووُزّع المجمّع.');
    }

    protected function period(): ?CreatorRevenuePeriod
    {
        return $this->open !== null ? CreatorRevenuePeriod::query()->where('public_id', $this->open)->first() : null;
    }

    protected function mustPeriod(): CreatorRevenuePeriod
    {
        return $this->period() ?? throw new CreatorFinanceException('اختر فترة أولًا.');
    }

    protected function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (CreatorFinanceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
