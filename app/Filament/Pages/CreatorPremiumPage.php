<?php

namespace App\Filament\Pages;

use App\Models\CreatorDirectSale;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Services\CreatorPremium\CreatorDirectSaleService;
use App\Services\CreatorPremium\CreatorPremiumAnalytics;
use App\Services\CreatorPremium\CreatorPremiumException;
use App\Services\CreatorPremium\CreatorPremiumReconciler;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use App\Services\CreatorPremium\CreatorSeriesOfferService;
use App\Services\Commerce\CommerceException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/**
 * E43 - إدارة السوق المميز للمبدعين: نظرة عامة لكل عملة، عروض البيع (إيقاف/استئناف إداري)، مشتريات السلاسل (تحقق بعد مراجعة، استرداد، إغلاق مراجعة)،
 * المبيعات المباشرة (إعادة محاولة العكس / تحميل المنصة للاسترداد غير القابل للعكس)، والمطابقة (قراءة فقط عند الطلب).
 *
 * كل القواعد والصلاحيات داخل الخدمات (creator-premium.view|manage|refund)؛ الصفحة غلاف عرض فقط. لا يظهر هنا أي رقم بطاقة أو وجهة تحويل أو حمولة مزوّد خام.
 * كل قائمة مُرقَّمة، ولا يُحمَّل شيء لتبويب غير مفتوح. المطابقة لا تعمل إلا بضغطة صريحة (استعلامات فحص أثقل) ولا تصلح شيئًا آليًا.
 */
class CreatorPremiumPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'مالية المبدعين';

    protected static ?string $navigationLabel = 'السلاسل المميزة';

    protected static ?string $title = 'السلاسل المميزة (سوق المبدعين)';

    protected static ?string $slug = 'creator-premium';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.creator-premium';

    public const TABS = ['overview' => 'نظرة عامة', 'offers' => 'العروض', 'purchases' => 'المشتريات', 'sales' => 'المبيعات', 'reconcile' => 'المطابقة'];

    public const PURCHASE_FILTERS = ['review' => 'مراجعة مطلوبة', 'awaiting' => 'بانتظار الدفع', 'verified' => 'مؤكَّدة', 'refunded' => 'مستردة', 'all' => 'الكل'];

    public const SALE_FILTERS = ['needs_review' => 'يحتاج مراجعة مالية', 'credited' => 'مُسجَّلة', 'refunded' => 'مستردة', 'written_off' => 'تتحمّلها المنصة', 'all' => 'الكل'];

    public string $tab = 'overview';

    public string $purchaseFilter = 'review';

    public string $saleFilter = 'needs_review';

    public ?string $open = null;

    public ?string $openSale = null;

    public string $note = '';

    public string $refundReason = 'user_request';

    public bool $confirmClaimed = false;

    /** @var array<string, mixed>|null */
    public ?array $reconciliation = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('creator-premium.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['tab', 'purchaseFilter', 'saleFilter'], true)) {
            $this->resetPage();
            $this->open = $this->openSale = null;
            $this->note = '';
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $d = [
            'enabled' => (bool) config('creator_premium.enabled'),
            'tabs' => self::TABS,
            'purchaseFilters' => self::PURCHASE_FILTERS,
            'saleFilters' => self::SALE_FILTERS,
            'reasons' => (array) config('creator_premium.refund.reasons', []),
            'canManage' => $user->can('creator-premium.manage'),
            'canRefund' => $user->can('creator-premium.refund'),
            'summary' => [],
            'page' => null,
            'opened' => null,
            'openedSale' => null,
            'reviewReady' => false,
            'unmatchedRefundable' => false,
        ];

        match ($this->tab) {
            'overview' => $d['summary'] = app(CreatorPremiumAnalytics::class)->forAdmin(),
            'offers' => $d['page'] = $this->offers(),
            'purchases' => $d = array_merge($d, $this->purchases()),
            'sales' => $d = array_merge($d, $this->sales()),
            default => null,
        };

        return $d;
    }

    protected function perPage(): int
    {
        return max(1, (int) config('creator_premium.limits.admin_per_page', 25));
    }

    protected function offers()
    {
        return DB::table('creator_series_sale_offers as o')
            ->join('creator_series as s', 's.id', '=', 'o.creator_series_id')
            ->join('creator_profiles as p', 'p.id', '=', 'o.creator_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->orderByRaw("CASE o.status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderByDesc('o.id')
            ->select(['o.public_id', 'o.status', 'o.price_minor', 'o.currency', 'o.pause_reason', 'o.first_activated_at', 'o.retired_at', 's.public_id as series_public_id', 's.published_title', 'u.name as creator_name'])
            ->paginate($this->perPage());
    }

    /** @return array<string, mixed> */
    protected function purchases(): array
    {
        $query = DigitalPurchase::query()->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)->with('user:id,name,email');

        match ($this->purchaseFilter) {
            'review' => $query->whereNotNull('review_flag')->whereNull('review_resolved_at')->orderBy('review_flagged_at')->orderBy('id'),
            'awaiting' => $query->where('status', DigitalPurchase::STATUS_AWAITING)->orderByDesc('id'),
            'verified' => $query->where('status', DigitalPurchase::STATUS_VERIFIED)->orderByDesc('verified_at')->orderByDesc('id'),
            'refunded' => $query->where('status', DigitalPurchase::STATUS_REFUNDED)->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $opened = $this->open !== null
            ? DigitalPurchase::query()->with(['user:id,name,email', 'events.actor:id,name'])->where('public_id', $this->open)->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)->first()
            : null;

        return [
            'page' => $query->paginate($this->perPage()),
            'opened' => $opened,
            'openedSale' => $opened !== null ? CreatorDirectSale::query()->where('digital_purchase_id', $opened->getKey())->first() : null,
            'reviewReady' => $opened !== null && $opened->status === DigitalPurchase::STATUS_AWAITING && $opened->isHosted() && $opened->needsReview()
                && in_array($opened->review_flag, CreatorPremiumRefundService::VERIFIABLE_FLAGS, true),
            'unmatchedRefundable' => $opened !== null && $opened->isHosted() && $opened->needsReview()
                && ! in_array($opened->status, [DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED], true)
                && $opened->providerState() === \App\Services\Commerce\CommerceProviderState::Paid && $opened->provider_refund_reference === null,
        ];
    }

    /** @return array<string, mixed> */
    protected function sales(): array
    {
        $query = CreatorDirectSale::query()->select(['id', 'public_id', 'series_title', 'creator_display_name', 'gross_minor', 'creator_share_minor', 'platform_share_minor', 'currency', 'status', 'sold_at', 'refunded_at', 'review_reason', 'review_note']);

        match ($this->saleFilter) {
            'needs_review' => $query->where('status', CreatorDirectSale::STATUS_NEEDS_REVIEW)->orderBy('id'),
            'credited' => $query->where('status', CreatorDirectSale::STATUS_CREDITED)->orderByDesc('id'),
            'refunded' => $query->where('status', CreatorDirectSale::STATUS_REFUNDED)->orderByDesc('id'),
            'written_off' => $query->where('status', CreatorDirectSale::STATUS_WRITTEN_OFF)->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        return [
            'page' => $query->paginate($this->perPage()),
            'openedSale' => $this->openSale !== null ? CreatorDirectSale::query()->where('public_id', $this->openSale)->first() : null,
        ];
    }

    // ───────────────────────── إجراءات ─────────────────────────

    public function showPurchase(string $publicId): void
    {
        $this->open = $publicId;
        $this->note = '';
        $this->refundReason = 'user_request';
        $this->confirmClaimed = false;
    }

    public function showSale(string $publicId): void
    {
        $this->openSale = $publicId;
        $this->note = '';
    }

    public function pauseOffer(string $offerPublicId): void
    {
        $this->run(function () use ($offerPublicId) {
            app(CreatorSeriesOfferService::class)->pauseByAdmin(auth()->user(), $offerPublicId, $this->note);
            $this->note = '';
        }, 'أُوقف بيع السلسلة. يبقى محتواها مقفولًا لغير المالكين.');
    }

    public function resumeOffer(string $offerPublicId): void
    {
        $this->run(function () use ($offerPublicId) {
            app(CreatorSeriesOfferService::class)->resumeByAdmin(auth()->user(), $offerPublicId, $this->note);
            $this->note = '';
        }, 'استُؤنف بيع السلسلة.');
    }

    public function verifyReviewed(): void
    {
        $this->run(function () {
            app(CreatorPremiumRefundService::class)->verifyReviewed(auth()->user(), $this->mustOpen(), $this->note);
            $this->note = '';
        }, 'تأكّد الدفع ومُنح الوصول وسُجّلت حصة المبدع (إن اجتازت الشروط).');
    }

    public function refund(): void
    {
        $this->run(function () {
            app(CreatorPremiumRefundService::class)->refund(auth()->user(), $this->mustOpen(), $this->refundReason, $this->note, $this->confirmClaimed);
            $this->note = '';
            $this->confirmClaimed = false;
        }, 'نُفِّذ الاسترداد (أو سُجّل) وسُحب الوصول وعُكست حصة المبدع إن أمكن.');
    }

    public function refundUnmatched(): void
    {
        $this->run(function () {
            app(CreatorPremiumRefundService::class)->refundUnmatched(auth()->user(), $this->mustOpen(), $this->note);
            $this->note = '';
        }, 'نُفِّذ استرداد الدفعة عبر المزوّد وأُغلقت المراجعة.');
    }

    public function dismissReview(): void
    {
        $this->run(function () {
            app(CreatorPremiumRefundService::class)->dismissReview(auth()->user(), $this->mustOpen(), $this->note);
            $this->note = '';
        }, 'أُغلقت المراجعة بقرار موثَّق.');
    }

    public function retryReversal(): void
    {
        $this->run(function () {
            app(CreatorDirectSaleService::class)->retryReversal(auth()->user(), $this->mustSale());
        }, 'عُكست حصة المبدع من الاسترداد.');
    }

    public function writeOff(): void
    {
        $this->run(function () {
            app(CreatorDirectSaleService::class)->writeOff(auth()->user(), $this->mustSale(), $this->note);
            $this->note = '';
        }, 'سُجّل الاسترداد على المنصة (لا تُسحب حصة المبدع).');
    }

    /** المطابقة قراءة فقط ولا تعمل إلا بضغطة صريحة. */
    public function reconcile(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reconciliation = app(CreatorPremiumReconciler::class)->run();
    }

    protected function mustOpen(): string
    {
        return $this->open ?? throw new CreatorPremiumException('missing', 'اختر طلبًا أولًا.');
    }

    protected function mustSale(): string
    {
        return $this->openSale ?? throw new CreatorPremiumException('missing', 'اختر بيعًا أولًا.');
    }

    protected function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (CreatorPremiumException|CommerceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function offerStatusLabel(string $status): string
    {
        return CreatorSeriesSaleOffer::STATUSES[$status] ?? $status;
    }
}
