<?php

namespace App\Filament\Pages;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use App\Services\Cinematic\Media\CinematicMediaService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Livewire\Attributes\Url;

/**
 * CIN-1 - مكتبة وسائط السلاسل السينمائية: قائمة الملفات (فيديو/ملصق/ترجمة/صوت) بنتائج الفحص الحقيقي (النوع/المدة/الأبعاد/الترميز)، وسحب/استعادة.
 * الرفع نفسه يمر بمسار خادمي مخصص (cinematic.admin.media.store) يتحكم بالحدود بنفسه؛ هذه الصفحة تستدعيه عبر XHR (تقدّم رفع حقيقي) مع رمز CSRF.
 * السحب لا يحذف الملف ولا يكسر مشهدًا منشورًا (يظهر المشهد «غير متاح» وتبقى النصوص البديلة).
 *
 * CIN-1 UX:
 *  - سياق المشهد (?story=&revision=&scene=): عند فتح المكتبة من محرّر مشهد نعرض زر «العودة إلى المشهد». الرابط يُبنى هنا على الخادم من سجلات حقيقية
 *    (المشهد يجب أن يوجد وينتمي للنسخة والقصة المذكورتين) ولا يقبل أي عنوان من المستخدم، فلا إعادة توجيه مفتوحة.
 *  - كل ملف يعرض المشاهد التي تستعمله (مع رابط للمحرّر والمعاينة)، ومعاينة إدارية عبر رابط موقَّع مربوط بالمستخدم (لا مسار تخزين مباشر).
 *  - الربط بمشهد يتم من محرّر المشهد (قسم الوسائط) كإشارة إلى الملف المرفوع؛ لا يُنسخ الملف عند الربط.
 */
class CinematicMediaLibraryPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'وسائط السلاسل السينمائية';

    protected static ?string $title = 'وسائط السلاسل السينمائية';

    protected static ?string $slug = 'cinematic-media';

    protected static ?int $navigationSort = 31;

    protected static string $view = 'filament.pages.cinematic-media-library';

    #[Url(as: 'role')]
    public string $role = 'video';

    #[Url(as: 'story')]
    public ?string $story = null;

    #[Url(as: 'revision')]
    public ?string $revision = null;

    #[Url(as: 'scene')]
    public ?string $scene = null;

    #[Url(as: 'q', history: false)]
    public string $search = '';

    public static function canAccess(): bool
    {
        return (bool) config('cinematic_stories.enabled') && (auth()->user()?->can('cinematic-stories.view') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** مساحة عمل كاملة العرض: جدول الخصائص + المشاهد المستخدمة يحتاجان مساحة. */
    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->role, CinematicMediaAsset::ROLES, true)) {
            $this->role = 'video';
        }
    }

    public function canUpload(): bool
    {
        return auth()->user()?->can('cinematic-stories.media') ?? false;
    }

    /** المعاينة الإدارية لملف المكتبة: رابط موقَّع مربوط بالمستخدم، لمن يملك صلاحية الوسائط أو المعاينة فقط (يطابق فحص المسار). */
    public function canPreviewFiles(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('cinematic-stories.media') || $user->can('cinematic-stories.preview'));
    }

    public function libraryUrl(CinematicMediaAsset $asset): ?string
    {
        $user = auth()->user();

        if (! $this->canPreviewFiles() || $user === null || ! $asset->isReady()) {
            return null;
        }

        return app(CinematicMediaLinks::class)->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $user);
    }

    public function retire(string $publicId, CinematicMediaService $media): void
    {
        abort_unless($this->canUpload(), 403);
        $media->retire(CinematicMediaAsset::query()->where('public_id', $publicId)->firstOrFail());
        Notification::make()->success()->title('سُحب الملف من الاستعمال (لم يُحذف).')->send();
    }

    public function restore(string $publicId, CinematicMediaService $media): void
    {
        abort_unless($this->canUpload(), 403);
        $media->restore(CinematicMediaAsset::query()->where('public_id', $publicId)->firstOrFail());
        Notification::make()->success()->title('أُعيد الملف إلى الاستعمال.')->send();
    }

    /**
     * سياق المشهد الذي فُتحت منه المكتبة، أو null. لا يُصدَّق مدخل المستخدم: المشهد يُقرأ من القاعدة ويجب أن يطابق النسخة والقصة المذكورتين.
     *
     * @return array<string, mixed>|null
     */
    public function sceneContext(): ?array
    {
        if ($this->scene === null || $this->scene === '') {
            return null;
        }

        $scene = CinematicScene::query()->where('public_id', $this->scene)->with(['revision.story', 'stage', 'chapter'])->first();

        if ($scene === null || $scene->revision === null || $scene->revision->story === null) {
            return null;
        }

        if (($this->revision !== null && $this->revision !== '' && $scene->revision->public_id !== $this->revision)
            || ($this->story !== null && $this->story !== '' && $scene->revision->story->public_id !== $this->story)) {
            return null;
        }

        $role = $this->role;
        $current = ['video' => 'video', 'poster' => 'poster', 'subtitle' => 'subtitle', 'audio' => 'audio'][$role] ?? 'video';

        return [
            'title' => $scene->title,
            'key' => $scene->stable_key,
            'type' => $scene->type,
            'stage' => $scene->stage?->title,
            'chapter' => $scene->chapter?->title,
            'storyTitle' => $scene->revision->story->title,
            'role' => $current,
            'query' => ['story' => $scene->revision->story->public_id, 'revision' => $scene->revision->public_id, 'scene' => $scene->public_id],
            'builderUrl' => CinematicBuilderPage::canAccess()
                ? CinematicBuilderPage::getUrl(['story' => $scene->revision->story->public_id, 'revision' => $scene->revision->public_id, 'scene' => $scene->public_id])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $role = in_array($this->role, CinematicMediaAsset::ROLES, true) ? $this->role : 'video';
        $term = trim($this->search);
        $query = CinematicMediaAsset::query()->where('role', $role);

        if ($term !== '') {
            // INSTR (MySQL وSQLite): بحث بلا أحرف بدل (% و_ شائعة في أسماء الملفات مثل PLM_S01_V01).
            $query->whereRaw('instr(lower(original_name), ?) > 0', [mb_strtolower(mb_substr($term, 0, 80))]);
        }

        $assets = $query->orderByDesc('id')->limit(200)->get();
        $column = ['video' => 'video_asset_id', 'poster' => 'poster_asset_id', 'subtitle' => 'subtitle_asset_id', 'audio' => 'audio_asset_id'][$role];

        // المشاهد التي تستعمل كل ملف (للحذر من ربط ملف واحد بمشهدين، ولمعرفة أين يُستعمل قبل سحبه).
        $uses = CinematicScene::query()
            ->whereIn($column, $assets->pluck('id'))
            ->with(['revision.story', 'stage', 'chapter'])
            ->orderBy('id')
            ->get(['id', 'public_id', 'stable_key', 'title', 'type', 'revision_id', 'stage_id', 'chapter_id', $column])
            ->groupBy($column);

        $canBuild = CinematicBuilderPage::canAccess();
        $usage = [];

        foreach ($uses as $assetId => $scenes) {
            $usage[$assetId] = $scenes->map(fn (CinematicScene $s) => [
                'title' => $s->title,
                'key' => $s->stable_key,
                'story' => $s->revision?->story?->title,
                'status' => $s->revision?->status,
                'stage' => $s->stage?->title,
                'chapter' => $s->chapter?->title,
                'editUrl' => $canBuild && $s->revision?->story
                    ? CinematicBuilderPage::getUrl(['story' => $s->revision->story->public_id, 'revision' => $s->revision->public_id, 'scene' => $s->public_id])
                    : null,
                'previewUrl' => $this->canPreviewFiles() && auth()->user()?->can('cinematic-stories.preview') && $s->revision
                    ? route('cinematic.preview', ['revision' => $s->revision->public_id, 'scene' => $s->public_id])
                    : null,
            ])->values()->all();
        }

        $limits = (array) config('cinematic_stories.media.'.$role, []);

        return [
            'role' => $role,
            'assets' => $assets,
            'usage' => $usage,
            'limits' => $limits,
            'canUpload' => $this->canUpload(),
            'canPreviewFiles' => $this->canPreviewFiles(),
            'uploadUrl' => route('cinematic.admin.media.store'),
            'context' => $this->sceneContext(),
            'roles' => ['video' => 'فيديو', 'poster' => 'ملصق/صورة', 'subtitle' => 'ترجمة WebVTT', 'audio' => 'صوت'],
        ];
    }
}
