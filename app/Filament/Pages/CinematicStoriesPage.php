<?php

namespace App\Filament\Pages;

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicStory;
use App\Models\CreatorSeries;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * CIN-1 - قائمة القصص السينمائية: إنشاء قصة (قصيرة/طويلة، مجانية/مميزة)، فتح المنشئ، أرشفة/إلغاء أرشفة.
 * عرض وتفويض فقط: كل القواعد داخل CinematicAuthoringService. لا يوجد هنا أي إجراء يمنح لاعبًا شيئًا. الصفحة مخفية والعلم مغلق.
 */
class CinematicStoriesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'السلاسل السينمائية';

    protected static ?string $title = 'السلاسل السينمائية';

    protected static ?string $slug = 'cinematic-stories';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.cinematic-stories';

    public bool $showCreate = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public static function canAccess(): bool
    {
        return (bool) config('cinematic_stories.enabled') && (auth()->user()?->can('cinematic-stories.view') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->form = ['title' => '', 'slug' => '', 'summary' => '', 'label_kind' => 'long', 'access_mode' => 'free', 'creator_series_id' => ''];
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('cinematic-stories.manage') ?? false;
    }

    public function create(CinematicAuthoringService $authoring): void
    {
        abort_unless($this->canManage(), 403);

        try {
            $story = $authoring->createStory($this->form, auth()->user());
        } catch (CinematicException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('أُنشئت القصة بمسودة أولى.')->send();
        $this->redirect(CinematicBuilderPage::getUrl(['story' => $story->public_id]));
    }

    public function toggleArchive(string $publicId, CinematicAuthoringService $authoring): void
    {
        abort_unless(auth()->user()?->can('cinematic-stories.publish'), 403);
        $story = CinematicStory::query()->where('public_id', $publicId)->firstOrFail();
        $authoring->setArchived($story, $story->archived_at === null);
        Notification::make()->success()->title($story->archived_at ? 'أُرشفت القصة وأُخفيت عن اللاعبين (لم يُحذف شيء).' : 'أُعيدت القصة من الأرشيف.')->send();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $stories = CinematicStory::query()->with('liveRevision')->orderByDesc('id')->limit(200)->get()->map(function (CinematicStory $s): array {
            $open = CinematicRevision::query()->where('story_id', $s->id)->whereIn('status', CinematicRevision::EDITABLE)->first();

            return [
                'story' => $s,
                'live' => $s->liveRevision?->revision_number,
                'draft' => $open?->revision_number,
                'draft_status' => $open?->status,
                'url' => CinematicBuilderPage::getUrl(['story' => $s->public_id]),
            ];
        });

        return [
            'rows' => $stories,
            'canManage' => $this->canManage(),
            'canPublish' => auth()->user()?->can('cinematic-stories.publish') ?? false,
            'series' => CreatorSeries::query()->orderByDesc('id')->limit(200)->get(['id', 'public_id', 'published_title']),
            'mediaUrl' => CinematicMediaLibraryPage::getUrl(),
        ];
    }
}
