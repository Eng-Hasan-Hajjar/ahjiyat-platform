<?php

namespace App\Filament\Pages;

use App\Models\DigitalPurchase;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassPremiumService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E40 - طابور مشتريات المسار المميز (الدفع اليدوي): التحقق (الطريق الوحيد لفتح المميز) أو الرفض أو تسجيل الاسترداد، وتنزيل الإثبات الخاص.
 * القواعد والصلاحيات كلها في PassPremiumService (passes.verify-purchases / passes.refund-purchases / passes.view-purchases). الصفحة مُرقَّمة (لا تحميل كل الصفوف).
 * لا حقل بطاقة أو وجهة تحويل هنا؛ الاسترداد المالي الفعلي خارجي ويُسجَّل هنا فقط.
 */
class AhjiyatPassPurchasesPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'مشتريات المميز';

    protected static ?string $title = 'مشتريات المسار المميز';

    protected static ?string $slug = 'ahjiyat-pass-purchases';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.ahjiyat-pass-purchases';

    public string $tab = 'submitted';

    public ?string $open = null;

    public string $reviewNote = '';

    public string $rejectMessage = '';

    public string $refundReason = 'user_request';

    public string $refundNote = '';

    public bool $confirmClaimed = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('passes.view-purchases') ?? false;
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
        $query = DigitalPurchase::query()->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->with('user:id,name,email');

        match ($this->tab) {
            'submitted' => $query->where('status', DigitalPurchase::STATUS_SUBMITTED)->orderBy('submitted_at')->orderBy('id'),
            'awaiting' => $query->where('status', DigitalPurchase::STATUS_AWAITING)->orderByDesc('id'),
            'verified' => $query->where('status', DigitalPurchase::STATUS_VERIFIED)->orderByDesc('verified_at')->orderByDesc('id'),
            'closed' => $query->whereIn('status', [DigitalPurchase::STATUS_REJECTED, DigitalPurchase::STATUS_CANCELLED, DigitalPurchase::STATUS_EXPIRED, DigitalPurchase::STATUS_REFUNDED])->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $opened = $this->open !== null ? DigitalPurchase::query()->with(['user:id,name,email', 'events.actor:id,name'])->where('public_id', $this->open)->first() : null;
        $premium = app(PassPremiumService::class);

        return [
            'page' => $query->paginate((int) config('ahjiyat_pass.admin.purchases_per_page', 25)),
            'opened' => $opened,
            'duplicates' => $opened !== null ? $premium->duplicateProofCount($opened) : 0,
            'reasons' => PassPremiumService::REFUND_REASONS,
            'canVerify' => $user->can('passes.verify-purchases'),
            'canRefund' => $user->can('passes.refund-purchases'),
            'tabs' => ['submitted' => 'قيد التحقق', 'awaiting' => 'بانتظار الدفع', 'verified' => 'مؤكَّدة', 'closed' => 'مغلقة', 'all' => 'الكل'],
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->reviewNote = $this->rejectMessage = $this->refundNote = '';
        $this->refundReason = 'user_request';
        $this->confirmClaimed = false;
    }

    public function verify(): void
    {
        $this->run(function () {
            app(PassPremiumService::class)->verify(auth()->user(), $this->mustPurchase(), $this->reviewNote !== '' ? $this->reviewNote : null);
            $this->reviewNote = '';
        }, 'تأكّد الدفع وفُتح المسار المميز للمشتري.');
    }

    public function reject(): void
    {
        $this->run(function () {
            app(PassPremiumService::class)->reject(auth()->user(), $this->mustPurchase(), $this->rejectMessage, $this->reviewNote !== '' ? $this->reviewNote : null);
            $this->rejectMessage = $this->reviewNote = '';
        }, 'رُفض الطلب وأُشعر المشتري.');
    }

    public function refund(): void
    {
        $this->run(function () {
            app(PassPremiumService::class)->refund(auth()->user(), $this->mustPurchase(), $this->refundReason, $this->refundNote, $this->confirmClaimed);
            $this->refundNote = '';
            $this->confirmClaimed = false;
        }, 'سُجّل الاسترداد وسُحب المميز. لا تُسحب العناصر المستلَمة آليًا.');
    }

    public function downloadProof()
    {
        try {
            return app(PassPremiumService::class)->proofDownload(auth()->user(), $this->mustPurchase());
        } catch (PassException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    protected function mustPurchase(): DigitalPurchase
    {
        return ($this->open !== null ? DigitalPurchase::query()->where('public_id', $this->open)->first() : null) ?? throw new PassException('missing', 'اختر طلبًا أولًا.');
    }

    protected function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (PassException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
