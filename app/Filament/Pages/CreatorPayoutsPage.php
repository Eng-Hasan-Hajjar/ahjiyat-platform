<?php

namespace App\Filament\Pages;

use App\Models\CreatorPayout;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorPayoutService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E39 - طابور الدفعات اليدوية: بدء مراجعة، اعتماد، رفض (برسالة ظاهرة للمبدع)، وتسجيل صرف بمرجع خارجي فريد. القواعد (تعليق/تجميد/فصل المهام/عدم معالجة حسابك)
 * كلها في CreatorPayoutService. لا حقل وجهة تحويل ولا بيانات بنكية هنا إطلاقًا.
 */
class CreatorPayoutsPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مالية المبدعين';

    protected static ?string $navigationLabel = 'طلبات الدفع';

    protected static ?string $title = 'طلبات دفع المبدعين';

    protected static ?string $slug = 'creator-payouts';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.creator-payouts';

    public string $tab = 'open';

    public ?string $open = null;

    public string $reviewNote = '';

    public string $rejectMessage = '';

    public string $reference = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('creator-finance.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $name): void
    {
        if ($name === 'tab') {
            $this->resetPage();
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $query = CreatorPayout::query()->with(['creatorProfile:id,slug', 'user:id,name']);

        match ($this->tab) {
            'open' => $query->whereIn('status', [CreatorPayout::STATUS_REQUESTED, CreatorPayout::STATUS_UNDER_REVIEW])->orderBy('requested_at')->orderBy('id'),
            'approved' => $query->where('status', CreatorPayout::STATUS_APPROVED)->orderBy('approved_at')->orderBy('id'),
            'paid' => $query->where('status', CreatorPayout::STATUS_PAID)->orderByDesc('paid_at')->orderByDesc('id'),
            'closed' => $query->whereIn('status', [CreatorPayout::STATUS_REJECTED, CreatorPayout::STATUS_CANCELLED])->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $opened = $this->open !== null ? CreatorPayout::query()->with(['account', 'creatorProfile:id,slug', 'user:id,name'])->where('public_id', $this->open)->first() : null;

        return [
            'page' => $query->paginate((int) config('creator_monetization.list.per_page', 20)),
            'opened' => $opened,
            'methods' => (array) config('creator_monetization.payout.methods'),
            'canReview' => $user->can('creator-finance.review-payouts'),
            'canSettle' => $user->can('creator-finance.settle-payouts'),
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->reviewNote = $this->rejectMessage = $this->reference = '';
    }

    public function startReview(): void
    {
        $this->run(fn () => app(CreatorPayoutService::class)->startReview($this->mustPayout(), auth()->user()), 'بدأت مراجعة الطلب.');
    }

    public function approve(): void
    {
        $this->run(function () {
            app(CreatorPayoutService::class)->approve($this->mustPayout(), auth()->user(), $this->reviewNote !== '' ? $this->reviewNote : null);
            $this->reviewNote = '';
        }, 'اعتُمد الطلب.');
    }

    public function reject(): void
    {
        $this->run(function () {
            app(CreatorPayoutService::class)->reject($this->mustPayout(), auth()->user(), $this->rejectMessage, $this->reviewNote !== '' ? $this->reviewNote : null);
            $this->rejectMessage = $this->reviewNote = '';
        }, 'رُفض الطلب وأُعيد المبلغ إلى الرصيد المتاح.');
    }

    public function settle(): void
    {
        $this->run(function () {
            app(CreatorPayoutService::class)->settle($this->mustPayout(), auth()->user(), $this->reference);
            $this->reference = '';
        }, 'سُجّل الصرف.');
    }

    protected function mustPayout(): CreatorPayout
    {
        return ($this->open !== null ? CreatorPayout::query()->where('public_id', $this->open)->first() : null) ?? throw new CreatorFinanceException('اختر طلبًا أولًا.');
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
