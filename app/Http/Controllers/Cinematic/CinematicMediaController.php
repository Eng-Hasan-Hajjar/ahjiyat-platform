<?php

namespace App\Http\Controllers\Cinematic;

use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicAccess;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use App\Services\Cinematic\Media\CinematicMediaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CIN-1 - البث المحروس للوسائط. لا يوجد أي رابط مباشر للملف: كل وصول عبر رابط **موقَّع** قصير العمر ومربوط بالمستخدم، ثم فحص حقيقي عند كل طلب:
 * تسجيل الدخول، علم التشغيل، ملكية القصة المميزة (E43 للقراءة)، أن النسخة قابلة للقراءة، وأن الملف يخص المشهد المذكور فعلًا.
 * يدعم HTTP Range (استئناف/تقديم) عبر BinaryFileResponse. الاسم المعروض عشوائي والنوع من السجل المتحقَّق لا من الامتداد. لا نقرأ مسارًا من الطلب أبدًا.
 *
 * لا ادّعاء بحماية مطلقة: من يملك حق المشاهدة يمكنه تقنيًا تسجيل الشاشة؛ الهدف منع التنزيل المباشر والمشاركة والوصول بلا ملكية.
 */
class CinematicMediaController extends Controller
{
    public function __construct(protected CinematicAccess $access, protected CinematicMediaLinks $links, protected CinematicMediaService $media) {}

    public function show(Request $request, string $asset)
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless($this->links->bindingMatches($user, (string) $request->query('k', '')), 403);

        $model = CinematicMediaAsset::query()->where('public_id', $asset)->first();
        abort_unless($model !== null && $model->isReady(), 404);

        $preview = (string) $request->query('p') === '1';
        $scene = CinematicScene::query()->where('public_id', (string) $request->query('s'))->first();
        abort_unless($scene !== null && (string) $request->query('t') === CinematicMediaLinks::SCOPE_SCENE, 404);

        // الملف يجب أن يكون من ملفات المشهد نفسه (لا يُخدم أي ملف بمجرد تخمين معرّفه).
        abort_unless(in_array($model->id, array_filter([$scene->video_asset_id, $scene->poster_asset_id, $scene->subtitle_asset_id, $scene->audio_asset_id]), true), 404);

        $revision = $scene->revision()->firstOrFail();
        $story = CinematicStory::query()->findOrFail($revision->story_id);

        if ($preview) {
            abort_unless($this->access->enabled() && $this->access->canPreview($user), 403);
        } else {
            $decision = $this->access->decide($user, $story);
            abort_unless($decision['allowed'], $decision['reason'] === 'premium_locked' ? 403 : 404);
            abort_unless($this->access->canReadRevision($user, $revision), 404);
        }

        return $this->file($model, private: $story->isPremium() || $preview);
    }

    /** غلاف القصة (تسويقي): رابط موقَّع بلا دخول، لغلاف قصة منشورة وغير مؤرشفة وبعلم مفعّل فقط. */
    public function cover(Request $request, string $asset)
    {
        $model = CinematicMediaAsset::query()->where('public_id', $asset)->first();
        abort_unless($this->access->enabled() && $model !== null && $model->isReady() && $model->role === CinematicMediaAsset::ROLE_POSTER, 404);

        $story = CinematicStory::query()->where('cover_asset_id', $model->id)->whereNull('archived_at')->whereHas('liveRevision')->first();
        abort_unless($story !== null, 404);

        return $this->file($model, private: false);
    }

    protected function file(CinematicMediaAsset $asset, bool $private): BinaryFileResponse
    {
        $path = $this->media->absolutePath($asset);
        abort_unless($path !== null, 404);

        $type = $asset->mime === 'text/vtt' ? 'text/vtt; charset=utf-8' : $asset->mime;

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => $private ? 'private, no-store' : 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="cinematic-'.$asset->public_id.'"',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
