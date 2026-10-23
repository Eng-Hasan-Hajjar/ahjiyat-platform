<?php

namespace App\Filament\Pages;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\CreatorFinance\CreatorMonetizationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E39 - حسابات أرباح المبدعين: تفعيل/إيقاف التسييل، وضع/رفع تعليق الدفعات (برابط حالة E38 اختياري)، تعديلات إدارية موثَّقة، وعرض الدفتر. لا يظهر هنا
 * أي رقم حساب بنكي أو وجهة. كل القواعد والصلاحيات داخل الخدمات؛ حساب المسؤول نفسه لا يُعالَج حتى لو سمحت الواجهة.
 */
class CreatorEarningAccountsPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'مالية المبدعين';

    protected static ?string $navigationLabel = 'حسابات الأرباح';

    protected static ?string $title = 'حسابات أرباح المبدعين';

    protected static ?string $slug = 'creator-earning-accounts';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.creator-earning-accounts';

    public string $search = '';

    public string $filter = 'all';

    public ?string $open = null;

    public string $enableSlug = '';

    public string $note = '';

    public string $holdReason = 'investigation';

    public string $holdCase = '';

    public string $adjustDirection = 'credit';

    public string $adjustAmount = '';

    public string $adjustReason = 'correction';

    public string $requestKey = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('creator-finance.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->requestKey = bin2hex(random_bytes(12));
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'filter'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $query = CreatorEarningAccount::query()->with(['creatorProfile:id,slug', 'user:id,name'])->orderBy('id');

        if ($this->search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';
            $query->whereHas('creatorProfile', fn ($q) => $q->where('slug', 'like', $like));
        }

        match ($this->filter) {
            'enabled' => $query->where('monetization_status', CreatorEarningAccount::STATUS_ENABLED),
            'paused' => $query->where('monetization_status', CreatorEarningAccount::STATUS_PAUSED),
            'hold' => $query->whereNotNull('hold_reason_code'),
            default => null,
        };

        $opened = $this->open !== null ? CreatorEarningAccount::query()->with(['creatorProfile:id,slug', 'user:id,name', 'holdCase:id,case_number'])->where('public_id', $this->open)->first() : null;

        return [
            'page' => $query->paginate((int) config('creator_monetization.list.per_page', 20)),
            'opened' => $opened,
            'entries' => $opened?->entries()->orderByDesc('id')->limit(50)->get(),
            'ledger' => $opened ? app(CreatorLedgerService::class)->ledgerBalances((int) $opened->id) : null,
            'holdReasons' => (array) config('creator_monetization.holds.reasons'),
            'adjustReasons' => (array) config('creator_monetization.adjustments.reasons'),
            'can' => [
                'monetization' => $user->can('creator-finance.manage-monetization'),
                'holds' => $user->can('creator-finance.manage-holds'),
                'adjust' => $user->can('creator-finance.adjust'),
            ],
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->note = $this->adjustAmount = $this->holdCase = '';
        $this->requestKey = bin2hex(random_bytes(12));
    }

    public function enable(): void
    {
        $this->run(function () {
            $profile = CreatorProfile::query()->where('slug', trim($this->enableSlug))->first() ?? throw new CreatorFinanceException('لا مبدع بهذا الرابط المختصر.');
            $account = app(CreatorMonetizationService::class)->enable($profile, auth()->user(), $this->note !== '' ? $this->note : null);
            $this->open = $account->public_id;
            $this->enableSlug = '';
        }, 'فُعّل التسييل للمبدع.');
    }

    public function pause(): void
    {
        $this->run(fn () => app(CreatorMonetizationService::class)->pause($this->mustAccount(), auth()->user(), $this->note), 'أُوقف التسييل مؤقتًا.');
    }

    public function resume(): void
    {
        $this->run(function () {
            $account = $this->mustAccount();
            app(CreatorMonetizationService::class)->enable($account->creatorProfile ?? CreatorProfile::query()->findOrFail($account->creator_profile_id), auth()->user(), $this->note !== '' ? $this->note : null);
        }, 'استُؤنف التسييل.');
    }

    public function placeHold(): void
    {
        $this->run(function () {
            app(CreatorMonetizationService::class)->placeHold($this->mustAccount(), auth()->user(), $this->holdReason, $this->note, $this->holdCase !== '' ? $this->holdCase : null);
            $this->note = $this->holdCase = '';
        }, 'وُضع تعليق على الدفعات.');
    }

    public function releaseHold(): void
    {
        $this->run(function () {
            app(CreatorMonetizationService::class)->releaseHold($this->mustAccount(), auth()->user(), $this->note);
            $this->note = '';
        }, 'رُفع تعليق الدفعات.');
    }

    public function adjust(): void
    {
        $this->run(function () {
            app(CreatorLedgerService::class)->adjust($this->mustAccount(), auth()->user(), $this->adjustDirection, $this->adjustAmount, $this->adjustReason, $this->note, $this->requestKey);
            $this->adjustAmount = $this->note = '';
            $this->requestKey = bin2hex(random_bytes(12));
        }, 'سُجّل التعديل في الدفتر.');
    }

    protected function mustAccount(): CreatorEarningAccount
    {
        return ($this->open !== null ? CreatorEarningAccount::query()->with('creatorProfile')->where('public_id', $this->open)->first() : null) ?? throw new CreatorFinanceException('اختر حسابًا أولًا.');
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
