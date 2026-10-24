<?php

namespace App\Filament\Pages;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassReadinessChecker;
use App\Services\AhjiyatPass\PassRules;
use App\Services\AhjiyatPass\PassTheme;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E40 - مسارات الأحجيات: القائمة، إنشاء مسودة، ودورة الحياة (جدولة/إلغاء جدولة/إنهاء/أرشفة/حذف مسودة). كل القواعد والصلاحيات داخل الخدمات
 * (PassBuilderService/PassLifecycleService)؛ الصفحة عرض وتفويض فقط. لا يوجد إجراء يمنح لاعبًا نقاطًا أو مميزًا أو مكافأة.
 */
class AhjiyatPassesPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'المسارات';

    protected static ?string $title = 'مسارات الأحجيات';

    protected static ?string $slug = 'ahjiyat-passes';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.ahjiyat-passes';

    public string $tab = 'all';

    public ?string $open = null;

    public bool $showCreate = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $endReason = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('passes.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetForm();
    }

    public function updated(string $name): void
    {
        if ($name === 'tab') {
            $this->resetPage();
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'name' => '', 'subtitle' => '', 'internal_key' => '',
            'starts_at' => now()->addDay()->startOfDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDay()->startOfDay()->addDays((int) config('ahjiyat_pass.lifecycle.default_duration_days', 30))->format('Y-m-d\TH:i'),
            'claim_grace_days' => (int) config('ahjiyat_pass.lifecycle.claim_grace_days', 5),
            'premium_enabled' => true, 'premium_price' => '', 'currency' => (string) (PassRules::currencies()[0] ?? 'USD'),
            'accent' => 'amber', 'background' => 'aurora', 'tagline' => '',
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $query = AhjiyatPass::query();
        $now = now();

        match ($this->tab) {
            'draft' => $query->where('status', AhjiyatPass::STATUS_DRAFT),
            'live' => $query->whereNotNull('locked_at')->whereIn('status', [AhjiyatPass::STATUS_SCHEDULED, AhjiyatPass::STATUS_ACTIVE])->where('ends_at', '>', $now),
            'ended' => $query->whereNotNull('locked_at')->where(fn ($q) => $q->where('ends_at', '<=', $now)->orWhereIn('status', [AhjiyatPass::STATUS_ENDED, AhjiyatPass::STATUS_ARCHIVED])),
            default => $query,
        };

        $page = $query->orderByDesc('starts_at')->orderByDesc('id')->paginate(15);
        $ids = $page->pluck('id');

        $players = UserPassProgress::query()->whereIn('ahjiyat_pass_id', $ids)->selectRaw('ahjiyat_pass_id, COUNT(*) AS n, SUM(CASE WHEN premium_unlocked_at IS NULL THEN 0 ELSE 1 END) AS premium')->groupBy('ahjiyat_pass_id')->get()->keyBy('ahjiyat_pass_id');
        $opened = $this->open !== null ? AhjiyatPass::query()->where('public_id', $this->open)->first() : null;

        return [
            'page' => $page,
            'players' => $players,
            'opened' => $opened,
            'problems' => $opened !== null && $opened->locked_at === null ? app(PassReadinessChecker::class)->problems($opened) : [],
            'pending' => $opened !== null ? DigitalPurchase::query()->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->where('product_id', $opened->id)->whereIn('status', DigitalPurchase::OPEN_STATUSES)->count() : 0,
            'currencies' => PassRules::currencies(),
            'accents' => PassTheme::accentLabels(),
            'backgrounds' => PassTheme::backgroundLabels(),
            'canManage' => auth()->user()->can('passes.manage'),
            'canPublish' => auth()->user()->can('passes.publish'),
            'tabs' => ['all' => 'الكل', 'draft' => 'مسودات', 'live' => 'مجدولة/فعّالة', 'ended' => 'منتهية'],
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->endReason = '';
        $this->showCreate = false;
    }

    public function create(): void
    {
        $this->run(function () {
            $f = $this->form;
            $pass = app(PassBuilderService::class)->createDraft(auth()->user(), [
                'name' => $f['name'] ?? '', 'subtitle' => $f['subtitle'] ?? null, 'internal_key' => $f['internal_key'] ?? null,
                'starts_at' => $f['starts_at'] ?? null, 'ends_at' => $f['ends_at'] ?? null, 'claim_grace_days' => $f['claim_grace_days'] ?? null,
                'premium_enabled' => (bool) ($f['premium_enabled'] ?? false), 'premium_price' => $f['premium_price'] ?? null, 'currency' => $f['currency'] ?? null,
                'theme' => ['accent' => $f['accent'] ?? null, 'background' => $f['background'] ?? null, 'tagline' => $f['tagline'] ?? null],
            ]);
            $this->open = $pass->public_id;
            $this->showCreate = false;
            $this->resetForm();
        }, 'أُنشئت المسودة. افتح المنشئ لإضافة الشرائح والمكافآت والمهام.');
    }

    public function schedule(): void
    {
        $this->run(fn () => app(PassLifecycleService::class)->schedule(auth()->user(), $this->mustPass()), 'نُشر المسار (جُدول). الشرائح والمكافآت والمهام والسعر مقفلة الآن.');
    }

    public function unschedule(): void
    {
        $this->run(fn () => app(PassLifecycleService::class)->unschedule(auth()->user(), $this->mustPass()), 'أُلغيت الجدولة وعاد المسار مسودة.');
    }

    public function end(): void
    {
        $this->run(function () {
            app(PassLifecycleService::class)->end(auth()->user(), $this->mustPass(), $this->endReason !== '' ? $this->endReason : null);
            $this->endReason = '';
        }, 'أُنهي المسار: لا نقاط جديدة وتبدأ مهلة الاستلام.');
    }

    public function archive(): void
    {
        $this->run(fn () => app(PassLifecycleService::class)->archive(auth()->user(), $this->mustPass()), 'أُرشف المسار.');
    }

    public function deleteDraft(): void
    {
        $this->run(function () {
            app(PassLifecycleService::class)->deleteDraft(auth()->user(), $this->mustPass());
            $this->open = null;
        }, 'حُذفت المسودة.');
    }

    protected function mustPass(): AhjiyatPass
    {
        return ($this->open !== null ? AhjiyatPass::query()->where('public_id', $this->open)->first() : null) ?? throw new PassException('missing', 'اختر مسارًا أولًا.');
    }

    protected function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (PassException $e) {
            Notification::make()->title($e->getMessage())->body($e->details === [] ? null : implode("\n", $e->details))->danger()->send();
        }
    }
}
