<?php

namespace App\Filament\Pages;

use App\Models\ModerationCase;
use App\Models\User;
use App\Services\TrustSafety\CaseService;
use App\Services\TrustSafety\EnforcementService;
use App\Services\TrustSafety\ModerationException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * E38 - صفحة حالة إشراف واحدة (للمشرفين فقط): المصادر، الموضوع، الأدلة والملاحظات، الإجراءات والاعتراضات عليها، والتسلسل الزمني.
 * المعرّف العلني (ULID) وحده في الرابط، والحالة تُعاد قراءتها من القاعدة في كل طلب (لا نموذج في خصائص Livewire العامة).
 * كل إجراء يمرّ بخدمة تفرض صلاحياتها وحواجز التضارب؛ هذه الصفحة لا تتجاوز شيئًا ولا تكتب مباشرة. النصوص الحرة نص عادي مهرَّب عند العرض.
 */
class ModerationCasePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'الثقة والسلامة';

    protected static ?string $title = 'حالة إشراف';

    protected static ?string $slug = 'moderation-cases/{record}';

    protected static string $view = 'filament.pages.moderation-case';

    public string $record = '';

    public string $noteText = '';

    public string $statusValue = '';

    public string $priorityValue = '';

    public string $statusNote = '';

    public string $actionKey = '';

    public string $actionReason = '';

    public string $actionPublicNote = '';

    public bool $actionConfirm = false;

    public string $resolution = '';

    public string $resolveNote = '';

    public string $closeNote = '';

    public string $reopenNote = '';

    public string $assigneeId = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trust-safety.view') ?? false;
    }

    public function mount(string $record): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = $record;
        $this->caseOrFail();
    }

    public function getHeading(): string
    {
        return 'حالة '.(ModerationCase::query()->where('public_id', $this->record)->value('case_number') ?? '');
    }

    /** الحالة تُعاد قراءتها من القاعدة بالمعرّف العلني في كل طلب؛ معرّف عبث به العميل ← 404 (لا 500 ولا تسريب). */
    protected function caseOrFail(): ModerationCase
    {
        $case = is_string($this->record) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $this->record) === 1
            ? ModerationCase::query()->where('public_id', $this->record)->first()
            : null;

        abort_if($case === null, 404);

        return $case;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $case = $this->caseOrFail();
        $user = auth()->user();
        $service = app(CaseService::class);
        $assignable = $user->can('trust-safety.supervise')
            ? User::query()->permission('trust-safety.manage-cases')->orderBy('name')->limit(100)->get(['id', 'name'])->pluck('name', 'id')->all()
            : [];

        return $service->detail($case) + [
            'actionsAvailable' => $case->isActive() ? app(EnforcementService::class)->available($case, $user) : [],
            'isMine' => (int) $case->assigned_to === (int) $user->id,
            'canManage' => $user->can('trust-safety.manage-cases'),
            'canSupervise' => $user->can('trust-safety.supervise'),
            'assignable' => $assignable,
            'me' => $user->id,
        ];
    }

    protected function attempt(callable $do, string $success): void
    {
        try {
            $do($this->caseOrFail(), auth()->user());
            Notification::make()->title($success)->success()->send();
        } catch (ModerationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function claim(): void
    {
        $this->attempt(fn ($c, $u) => app(CaseService::class)->claim($c, $u), 'حُجزت الحالة لك.');
    }

    public function release(): void
    {
        $this->attempt(fn ($c, $u) => app(CaseService::class)->release($c, $u), 'تخلّيت عن الحالة.');
    }

    public function assign(): void
    {
        $this->attempt(function ($c, $u) {
            $assignee = $this->assigneeId !== '' ? User::query()->find((int) $this->assigneeId) : null;
            app(CaseService::class)->assign($c, $u, $assignee);
            $this->assigneeId = '';
        }, 'حُدِّث تعيين الحالة.');
    }

    public function addNote(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->addNote($c, $u, $this->noteText);
            $this->noteText = '';
        }, 'أُضيفت الملاحظة.');
    }

    public function changeStatus(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->changeStatus($c, $u, $this->statusValue, $this->statusNote !== '' ? $this->statusNote : null);
            $this->statusNote = '';
        }, 'تغيّرت حالة الحالة.');
    }

    public function changePriority(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->setPriority($c, $u, $this->priorityValue, $this->statusNote !== '' ? $this->statusNote : null);
            $this->statusNote = '';
        }, 'تغيّرت الأولوية.');
    }

    public function applyAction(): void
    {
        $this->attempt(function ($c, $u) {
            app(EnforcementService::class)->apply($c, $u, $this->actionKey, [
                'reason' => $this->actionReason, 'public_note' => $this->actionPublicNote, 'confirm' => $this->actionConfirm,
            ]);
            $this->reset(['actionKey', 'actionReason', 'actionPublicNote', 'actionConfirm']);
        }, 'نُفِّذ الإجراء وسُجِّل.');
    }

    public function resolve(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->resolve($c, $u, $this->resolution, $this->resolveNote !== '' ? $this->resolveNote : null);
            $this->reset(['resolution', 'resolveNote']);
        }, 'حُسمت الحالة وزُوِّنت البلاغات.');
    }

    public function closeCase(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->close($c, $u, $this->closeNote);
            $this->closeNote = '';
        }, 'أُغلقت الحالة.');
    }

    public function reopen(): void
    {
        $this->attempt(function ($c, $u) {
            app(CaseService::class)->reopen($c, $u, $this->reopenNote);
            $this->reopenNote = '';
        }, 'أُعيد فتح الحالة.');
    }

    public function detachSource(int $sourceId): void
    {
        $this->attempt(fn ($c, $u) => app(CaseService::class)->detach($c, $u, $sourceId), 'فُصل المصدر عن الحالة.');
    }
}
