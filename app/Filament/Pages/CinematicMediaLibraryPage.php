<?php

namespace App\Filament\Pages;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\Media\CinematicMediaService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * CIN-1 - مكتبة وسائط السلاسل السينمائية: قائمة الملفات (فيديو/ملصق/ترجمة/صوت) بنتائج الفحص الحقيقي (النوع/المدة/الأبعاد/الترميز)، وسحب/استعادة.
 * الرفع نفسه يمر بمسار خادمي مخصص (cinematic.admin.media.store) يتحكم بالحدود بنفسه؛ هذه الصفحة تستدعيه بـfetch مع رمز CSRF.
 * السحب لا يحذف الملف ولا يكسر مشهدًا منشورًا (يظهر المشهد «غير متاح» وتبقى النصوص البديلة).
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

        if (! in_array($this->role, CinematicMediaAsset::ROLES, true)) {
            $this->role = 'video';
        }
    }

    public function canUpload(): bool
    {
        return auth()->user()?->can('cinematic-stories.media') ?? false;
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

    /** @return array<string, mixed> */
    public function data(): array
    {
        $role = in_array($this->role, CinematicMediaAsset::ROLES, true) ? $this->role : 'video';
        $assets = CinematicMediaAsset::query()->where('role', $role)->orderByDesc('id')->limit(200)->get();
        $column = ['video' => 'video_asset_id', 'poster' => 'poster_asset_id', 'subtitle' => 'subtitle_asset_id', 'audio' => 'audio_asset_id'][$role];
        $usage = CinematicScene::query()->whereIn($column, $assets->pluck('id'))->selectRaw($column.' as asset_id, count(*) as uses')->groupBy($column)->pluck('uses', 'asset_id');
        $limits = (array) config('cinematic_stories.media.'.$role, []);

        return ['role' => $role, 'assets' => $assets, 'usage' => $usage, 'limits' => $limits, 'canUpload' => $this->canUpload(), 'uploadUrl' => route('cinematic.admin.media.store'), 'roles' => ['video' => 'فيديو', 'poster' => 'ملصق/صورة', 'subtitle' => 'ترجمة WebVTT', 'audio' => 'صوت']];
    }
}
