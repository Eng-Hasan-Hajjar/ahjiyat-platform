<?php

namespace App\Filament\Pages;

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Services\TrustSafety\AppealService;
use App\Services\TrustSafety\ModerationException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * E38 - طابور الاعتراضات. المراجِع يرى نص الاعتراض (مهرَّبًا) والإجراء الأصلي، ويحسم بقبول/رفض مع رسالة ظاهرة للمستخدم وملاحظة داخلية.
 * حواجز تضارب المراجِع والحجز الذرّي والعكس عبر خدمة المجال كلها في AppealService؛ هذه الصفحة لا تتجاوز شيئًا.
 */
class ModerationAppealsPage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'الثقة والسلامة';

    protected static ?string $navigationLabel = 'الاعتراضات';

    protected static ?string $title = 'اعتراضات المستخدمين';

    protected static ?string $slug = 'moderation-appeals';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.moderation-appeals';

    public string $tab = 'active';

    public ?string $open = null;

    public string $decisionMessage = '';

    public string $decisionNote = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('trust-safety.review-appeals') || $user->can('trust-safety.view'));
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

    /** @return array<string,mixed> */
    public function data(): array
    {
        $user = auth()->user();
        $service = app(AppealService::class);
        $opened = $this->open !== null ? ModerationAppeal::query()->with(['action.moderationCase', 'appellant:id,name', 'reviewer:id,name'])->where('public_id', $this->open)->first() : null;

        return [
            'counts' => $service->counts($user),
            'page' => $service->queue($user, ['view' => $this->tab, 'page' => $this->getPage()]),
            'opened' => $opened,
            'labels' => ModerationAction::LABELS,
            'canReview' => $user->can('trust-safety.review-appeals'),
            'me' => $user->id,
        ];
    }

    public function show(string $publicId): void
    {
        $this->open = $publicId;
        $this->decisionMessage = '';
        $this->decisionNote = '';
    }

    protected function appeal(): ?ModerationAppeal
    {
        return $this->open !== null ? ModerationAppeal::query()->where('public_id', $this->open)->first() : null;
    }

    public function claim(): void
    {
        $appeal = $this->appeal();

        if ($appeal === null) {
            return;
        }

        try {
            app(AppealService::class)->claim($appeal, auth()->user());
            Notification::make()->title('حُجز الاعتراض لمراجعتك.')->success()->send();
        } catch (ModerationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function decide(bool $accept): void
    {
        $appeal = $this->appeal();

        if ($appeal === null) {
            return;
        }

        try {
            app(AppealService::class)->decide($appeal, auth()->user(), $accept, $this->decisionMessage, $this->decisionNote !== '' ? $this->decisionNote : null);
            $this->decisionMessage = '';
            $this->decisionNote = '';
            Notification::make()->title($accept ? 'قُبل الاعتراض وعُكس الإجراء.' : 'رُفض الاعتراض.')->success()->send();
        } catch (ModerationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
