<?php

namespace App\Filament\Pages;

use App\Models\GemIntegrityFinding;
use App\Models\GemIntegrityRun;
use App\Services\Economy\Integrity\RedemptionCommitments;
use App\Services\Economy\Integrity\WalletAuditCase;
use Filament\Pages\Page;

/**
 * E46 - تفاصيل «سلامة الجواهر» (قراءة فقط) لمن يملك economy.transactions.view (صلاحية سجل المعاملات القائمة؛ لا صلاحية جديدة).
 *
 * - الحماية خادمية: canAccess() لإخفاء القائمة + abort_unless في mount() لحجب الرابط المباشر (403) حتى لمن يملك admin.access فقط.
 * - تعرض نتيجة **آخر فحص موثوق محفوظ** وحالاته غير المطابقة المخزَّنة؛ لا تشغّل أي فحص ولا تعدّل رصيدًا، ولا يوجد هنا أي زر إصلاح أو تعديل.
 * - الترقيم يدوي (صفحة 25) بدل Paginator لتفادي تعديل عروض الترقيم العامة؛ التصفية بالحكم فقط، بقيم مسموحة صراحةً.
 * - الالتزامات: ما أمكن إثباته من redemption_requests مقابل الدفتر (RedemptionCommitments)، والميزانية «غير معرَّفة» بلا أي رقم تخميني.
 */
class GemIntegrityPage extends Page
{
    public const PER_PAGE = 25;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'الاقتصاد';

    protected static ?string $navigationLabel = 'سلامة الجواهر';

    protected static ?string $title = 'سلامة الجواهر';

    protected static ?string $slug = 'gem-integrity';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.gem-integrity';

    /** all | proven_difference | undetermined */
    public string $verdict = 'all';

    public int $pageNumber = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('economy.transactions.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updatedVerdict(): void
    {
        $this->pageNumber = 1;
    }

    public function nextPage(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->pageNumber = min($this->lastPage(), $this->pageNumber + 1);
    }

    public function previousPage(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->pageNumber = max(1, $this->pageNumber - 1);
    }

    public function getRun(): ?GemIntegrityRun
    {
        return once(fn () => GemIntegrityRun::lastTrusted());
    }

    /** آخر محاولة أيًّا كانت حالتها: لإظهار أن المحاولة الأخيرة فشلت بعد آخر فحص موثوق (دون عرض نص الخطأ الداخلي). */
    public function getLastAttemptFailed(): bool
    {
        return once(function () {
            $last = GemIntegrityRun::query()->orderByDesc('id')->first(['id', 'status']);
            $trusted = $this->getRun();

            return $last !== null && $last->status === GemIntegrityRun::STATUS_FAILED && ($trusted === null || $last->id > $trusted->id);
        });
    }

    /** @return array<string, string> قيم التصفية المسموحة => التسمية */
    public function verdictOptions(): array
    {
        return [
            'all' => 'كل الحالات غير المطابقة',
            WalletAuditCase::VERDICT_PROVEN => WalletAuditCase::verdictLabel(WalletAuditCase::VERDICT_PROVEN),
            WalletAuditCase::VERDICT_UNDETERMINED => WalletAuditCase::verdictLabel(WalletAuditCase::VERDICT_UNDETERMINED),
        ];
    }

    protected function findingsQuery()
    {
        $run = $this->getRun();

        return GemIntegrityFinding::query()
            ->where('run_id', $run?->id ?? 0)
            ->when(
                array_key_exists($this->verdict, $this->verdictOptions()) && $this->verdict !== 'all',
                fn ($q) => $q->where('verdict', $this->verdict),
            )
            ->orderBy('id');
    }

    public function total(): int
    {
        return once(fn () => (int) $this->findingsQuery()->count());
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total() / self::PER_PAGE));
    }

    /** @return \Illuminate\Support\Collection<int, GemIntegrityFinding> */
    public function findings()
    {
        $page = max(1, min($this->pageNumber, $this->lastPage()));

        return $this->findingsQuery()
            ->with(['user:id,name', 'currency:id,name,code'])
            ->forPage($page, self::PER_PAGE)
            ->get();
    }

    /** @return array<string, mixed> ما أمكن إثباته من التزامات الاستبدال (الميزانية دائمًا null). */
    public function commitments(): array
    {
        return once(fn () => app(RedemptionCommitments::class)->summary());
    }

    public function canOpenUsers(): bool
    {
        return auth()->user()?->can('users.view') ?? false;
    }

    public function reasonLabel(string $code): string
    {
        return WalletAuditCase::reasonLabel($code);
    }

    public function verdictLabel(string $verdict): string
    {
        return WalletAuditCase::verdictLabel($verdict);
    }
}
