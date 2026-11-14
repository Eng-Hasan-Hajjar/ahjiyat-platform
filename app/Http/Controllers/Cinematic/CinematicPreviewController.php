<?php

namespace App\Http\Controllers\Cinematic;

use App\Filament\Pages\CinematicBuilderPage;
use App\Filament\Pages\CinematicStoriesPage;
use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Services\Cinematic\CinematicAccess;
use App\Services\Cinematic\CinematicEvidenceLog;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\CinematicReadinessChecker;
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
    public function __construct(protected CinematicAccess $access, protected CinematicPlayService $play, protected CinematicScenePayload $payloads, protected CinematicEvidenceLog $evidence) {}

    public function show(Request $request, string $revision)
    {
        $model = $this->revision($request, $revision);
        $state = $this->play->previewState($model);
        $sceneModels = CinematicStructure::scenes($model)->load(['video', 'poster', 'subtitle', 'audio', 'puzzle']);
        $scenes = $sceneModels->mapWithKeys(fn (CinematicScene $s) => [$s->public_id => $this->payloads->make($s, $request->user(), 'available', true)])->all();

        // CIN-2: أدلة المشاهد للمعاينة (إدارة فقط). الواجهة تُظهر منها ما «اجتازه» المعاين في جلسته فقط؛ لا شيء يُحفظ.
        $evidenceByScene = $sceneModels->mapWithKeys(fn (CinematicScene $s) => [$s->public_id => $this->evidence->entry($s, $request->user(), true)])->filter()->all();

        // مشهد البداية الاختياري (?scene=): يُقبل فقط إن كان من هذه النسخة، وإلا نبدأ من الأول (لا إعادة توجيه ولا كشف لمعلومات).
        $start = (string) $request->query('scene', '');
        $start = array_key_exists($start, $scenes) ? $start : null;

        // CIN-1 IMMERSIVE: وضعان للمعاينة. الافتراضي «إداري» (درج أدوات قابل للطي)، و?mode=player «كما يراها اللاعب» (بلا أي أدوات في الواجهة).
        // الوضعان كلاهما معاينة معزولة: لا تقدّم محفوظ ولا ملكية ولا اقتصاد، وتُقيَّم الإجابات في الخادم بالحل الحقيقي.
        $playerLike = (string) $request->query('mode', '') === 'player';

        $user = $request->user();
        $story = $model->story;
        $urls = [
            'submit' => route('cinematic.preview.submit', [$model->public_id, '__SCENE__']),
            'back' => CinematicStoriesPage::canAccess() ? CinematicStoriesPage::getUrl() : url('/admin'),
            // تبديل الوضع مع الإبقاء على المشهد الحالي (يُبنى هنا من معرّف النسخة، و__SCENE__ يُستبدل في الواجهة بمعرّف مشهد من هذه النسخة فقط).
            'admin_like' => route('cinematic.preview', ['revision' => $model->public_id, 'scene' => '__SCENE__']),
            'player_like' => route('cinematic.preview', ['revision' => $model->public_id, 'scene' => '__SCENE__', 'mode' => 'player']),
        ];

        // العودة إلى محرّر المشهد: تُبنى هنا على الخادم من معرّفات النسخة (لا من مدخل المستخدم) ولا تظهر إلا لمن يستطيع فتح المنشئ.
        if (CinematicBuilderPage::canAccess()) {
            $urls['builder'] = CinematicBuilderPage::getUrl(['story' => $story->public_id, 'revision' => $model->public_id, 'scene' => '__SCENE__']);
        }

        return view('cinematic.play', [
            'story' => $story,
            'preview' => true,
            'playerLike' => $playerLike,
            'config' => [
                'mode' => 'preview',
                'view' => $playerLike ? 'player' : 'admin',
                'title' => $model->title.' — معاينة',
                'state' => $state,
                'scenes' => $scenes,
                'evidence_by_scene' => $evidenceByScene,
                'start_scene' => $start,
                'status' => $model->status,
                'readiness' => $this->readiness($model),
                'urls' => $urls,
            ],
        ]);
    }

    public function submit(Request $request, string $revision, string $scene): JsonResponse
    {
        $model = $this->revision($request, $revision);
        $data = $request->validate(['submission' => ['required', 'array', 'max:24'], 'attempt' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $sceneModel = CinematicScene::query()->where('public_id', $scene)->where('revision_id', $model->id)->firstOrFail();

        try {
            $allKeys = CinematicScene::query()->where('revision_id', $model->id)->pluck('stable_key')->all();
            [$correct, $locked, $message, $reveal, $hint, $detail] = $this->play->evaluateWith($sceneModel, $data['submission'], $allKeys, ['attempt' => (int) ($data['attempt'] ?? 1)]);
        } catch (CinematicException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->httpStatus);
        }

        return response()->json(['result' => ['correct' => $correct, 'locked' => $locked, 'message' => $message, 'reveal' => $reveal, 'hint' => $hint, 'detail' => $detail, 'attempts_left' => null]])->header('Cache-Control', 'no-store');
    }

    /** جاهزية النسخة محسوبة الآن (قراءة فقط)، وبديلها المخزَّن إن تعذّر الحساب. تحوي رسائل ناقصة/تحذيرات فقط، لا حلولًا. */
    protected function readiness(CinematicRevision $model): array
    {
        try {
            $r = app(CinematicReadinessChecker::class)->check($model);
        } catch (\Throwable) {
            $r = (array) $model->readiness;
        }

        return [
            'ok' => (bool) ($r['ok'] ?? false),
            'blockers' => array_values(array_map(fn ($b) => ['code' => (string) ($b['code'] ?? ''), 'message' => (string) ($b['message'] ?? ''), 'scene' => $b['scene'] ?? null], (array) ($r['blockers'] ?? []))),
            'warnings' => array_values(array_map(fn ($b) => ['code' => (string) ($b['code'] ?? ''), 'message' => (string) ($b['message'] ?? ''), 'scene' => $b['scene'] ?? null], (array) ($r['warnings'] ?? []))),
        ];
    }

    protected function revision(Request $request, string $revision): CinematicRevision
    {
        abort_unless($this->access->canPreview($request->user()), 403);

        return CinematicRevision::query()->where('public_id', $revision)->with('story')->firstOrFail();
    }
}
