<?php

namespace App\Http\Controllers\Cinematic;

use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicAccess;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\CinematicScenePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CIN-1 - واجهة JSON للمشغّل. كل قرار تقدّم على الخادم (CinematicPlayService). لا يصل العميل ما يُحدد التقدّم: يرسل فقط
 * «أي مشهد» و«أي إجابة»، ويقرر الخادم الفتح والإتمام. الوصول (علم/دخول/ملكية مميزة/نسخة قابلة للقراءة) يُفحص في كل طلب.
 */
class CinematicPlayController extends Controller
{
    use ResolvesCinematicStory;

    public function __construct(protected CinematicAccess $access, protected CinematicPlayService $play, protected CinematicScenePayload $payloads) {}

    public function state(Request $request, string $story): JsonResponse
    {
        return $this->guard($request, $story, function (CinematicStory $story, ?CinematicProgress $progress) use ($request) {
            $revision = $progress ? CinematicRevision::query()->findOrFail($progress->revision_id) : $this->access->liveRevision($story);
            $state = $progress ? $this->play->state($progress) : $this->play->freshState($revision);

            $sceneId = $request->query('scene');
            $target = collect($state['scenes'])->firstWhere('id', is_string($sceneId) ? $sceneId : $state['current']);

            return ['state' => $state, 'scene' => $target && $target['status'] !== 'locked' ? $this->payload((string) $target['id'], $request, $target['status']) : null];
        });
    }

    public function start(Request $request, string $story, string $scene): JsonResponse
    {
        return $this->guard($request, $story, function (CinematicStory $story) use ($request, $scene) {
            $progress = $this->play->begin($request->user(), $story);
            $model = $this->scene($scene);
            $state = $this->play->start($progress, $model);
            $status = collect($state['scenes'])->firstWhere('id', $model->public_id)['status'] ?? 'locked';

            return ['state' => $state, 'scene' => $this->payload($model->public_id, $request, $status)];
        });
    }

    public function finish(Request $request, string $story, string $scene): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'string', 'in:watched,read,skipped']]);

        return $this->guard($request, $story, function (CinematicStory $story) use ($request, $scene, $data) {
            $progress = $this->play->begin($request->user(), $story);
            $state = $this->play->finish($progress, $this->scene($scene), $data['mode']);

            return ['state' => $state, 'scene' => $this->next($state, $request)];
        });
    }

    public function submit(Request $request, string $story, string $scene): JsonResponse
    {
        $data = $request->validate(['submission' => ['required', 'array', 'max:24']]);

        if (strlen((string) json_encode($data['submission'])) > 8192) {
            return response()->json(['message' => 'حجم الإجابة كبير جدًا.', 'reason' => 'too_large'], 422);
        }

        return $this->guard($request, $story, function (CinematicStory $story) use ($request, $scene, $data) {
            $progress = $this->play->begin($request->user(), $story);
            $out = $this->play->submit($progress, $this->scene($scene), $data['submission']);

            return ['state' => collect($out)->except('result')->all(), 'result' => $out['result'], 'scene' => ($out['result']['correct'] ?? false) ? $this->next($out, $request) : null];
        });
    }

    public function upgrade(Request $request, string $story): JsonResponse
    {
        return $this->guard($request, $story, function (CinematicStory $story) use ($request) {
            $progress = $this->play->upgrade($request->user(), $story);
            $state = $this->play->state($progress);

            return ['state' => $state, 'scene' => $this->next($state, $request)];
        });
    }

    // ───────────────────────── داخلي ─────────────────────────

    /**
     * @param  callable(CinematicStory, ?CinematicProgress): array<string, mixed>  $action
     */
    protected function guard(Request $request, string $story, callable $action): JsonResponse
    {
        try {
            $story = $this->story($story);
            $user = $request->user();
            $decision = $this->access->decide($user, $story);

            if (! $decision['allowed']) {
                return response()->json(['message' => $this->denyMessage($decision['reason']), 'reason' => $decision['reason']], $decision['reason'] === 'login' ? 401 : ($decision['reason'] === 'premium_locked' ? 403 : 404));
            }

            $progress = $this->play->progressFor($user, $story);
            $revision = $progress ? CinematicRevision::query()->findOrFail($progress->revision_id) : $this->access->liveRevision($story);

            if ($revision === null || ! $this->access->canReadRevision($user, $revision)) {
                return response()->json(['message' => 'هذه السلسلة غير متاحة حاليًا.', 'reason' => 'unavailable'], 404);
            }

            return response()->json($action($story, $progress))->header('Cache-Control', 'no-store');
        } catch (CinematicException $e) {
            return $this->failure($e);
        }
    }

    protected function scene(string $publicId): CinematicScene
    {
        return CinematicScene::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    protected function next(array $state, Request $request): ?array
    {
        if (empty($state['current'])) {
            return null;
        }

        $status = collect($state['scenes'])->firstWhere('id', $state['current'])['status'] ?? 'locked';

        return $status === 'locked' ? null : $this->payload((string) $state['current'], $request, $status);
    }

    /** @return array<string, mixed> */
    protected function payload(string $sceneId, Request $request, string $status): array
    {
        $scene = CinematicScene::query()->with(['video', 'poster', 'subtitle', 'audio', 'puzzle'])->where('public_id', $sceneId)->firstOrFail();

        return $this->payloads->make($scene, $request->user(), $status);
    }

    protected function denyMessage(string $reason): string
    {
        return match ($reason) {
            'login' => 'سجّل الدخول لمتابعة السلسلة.',
            'premium_locked' => 'هذه سلسلة مميزة؛ يلزم امتلاك الوصول إليها.',
            default => 'هذه السلسلة غير متاحة حاليًا.',
        };
    }
}
