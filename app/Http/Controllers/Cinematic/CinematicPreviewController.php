<?php

namespace App\Http\Controllers\Cinematic;

use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\CinematicAccess;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\CinematicScenePayload;
use App\Services\Cinematic\CinematicStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CIN-1 - المعاينة الإشرافية (صلاحية cinematic-stories.preview): أي نسخة (مسودة/مراجعة/منشورة) بكل مشاهدها متاحة، **بلا أي تقدّم محفوظ ولا ملكية ولا اقتصاد**.
 * التفاعلات تُقيَّم على الخادم بالحل الحقيقي (الشروط requires_scenes تُعدّ متحققة في المعاينة) لكن لا تُكتب أي حالة. الوسائط الناقصة تظهر حالتها بدل عنصر مؤقت مخترع.
 */
class CinematicPreviewController extends Controller
{
    public function __construct(protected CinematicAccess $access, protected CinematicPlayService $play, protected CinematicScenePayload $payloads) {}

    public function show(Request $request, string $revision)
    {
        $model = $this->revision($request, $revision);
        $state = $this->play->previewState($model);
        $scenes = CinematicStructure::scenes($model)->load(['video', 'poster', 'subtitle', 'audio', 'puzzle'])
            ->mapWithKeys(fn (CinematicScene $s) => [$s->public_id => $this->payloads->make($s, $request->user(), 'available', true)])->all();

        return view('cinematic.play', [
            'story' => $model->story,
            'preview' => true,
            'config' => [
                'mode' => 'preview',
                'title' => $model->title.' — معاينة',
                'state' => $state,
                'scenes' => $scenes,
                'status' => $model->status,
                'readiness' => $model->readiness,
                'urls' => ['submit' => route('cinematic.preview.submit', [$model->public_id, '__SCENE__']), 'back' => url('/admin')],
            ],
        ]);
    }

    public function submit(Request $request, string $revision, string $scene): JsonResponse
    {
        $model = $this->revision($request, $revision);
        $data = $request->validate(['submission' => ['required', 'array', 'max:24']]);
        $sceneModel = CinematicScene::query()->where('public_id', $scene)->where('revision_id', $model->id)->firstOrFail();

        try {
            $allKeys = CinematicScene::query()->where('revision_id', $model->id)->pluck('stable_key')->all();
            [$correct, $locked, $message, $reveal] = $this->play->evaluateWith($sceneModel, $data['submission'], $allKeys);
        } catch (CinematicException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->httpStatus);
        }

        return response()->json(['result' => ['correct' => $correct, 'locked' => $locked, 'message' => $message, 'reveal' => $reveal, 'attempts_left' => null]])->header('Cache-Control', 'no-store');
    }

    protected function revision(Request $request, string $revision): CinematicRevision
    {
        abort_unless($this->access->canPreview($request->user()), 403);

        return CinematicRevision::query()->where('public_id', $revision)->with('story')->firstOrFail();
    }
}
