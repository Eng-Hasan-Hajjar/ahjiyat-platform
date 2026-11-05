<?php

namespace App\Http\Controllers\Cinematic;

use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicAccess;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * CIN-1 - صفحات القراءة العامة: قائمة القصص المنشورة، صفحة القصة، وصفحة المشغّل. **لا GET يكتب شيئًا**: التقدّم يُنشأ عند أول إجراء POST.
 * القصة بلا نسخة منشورة (أو مؤرشفة) = 404 للجميع. القصة المميزة تعرض غلافها وملخصها فقط لغير المالك، دون أي مشهد أو وسيط.
 */
class CinematicStoryController extends Controller
{
    use ResolvesCinematicStory;

    public function __construct(protected CinematicAccess $access, protected CinematicPlayService $play, protected CinematicMediaLinks $links) {}

    public function index(Request $request)
    {
        $stories = CinematicStory::query()
            ->whereNull('archived_at')
            ->whereHas('liveRevision')
            ->with(['liveRevision', 'cover'])
            ->orderBy('title')
            ->get()
            ->map(function (CinematicStory $story) use ($request) {
                $progress = $request->user() ? $this->play->progressFor($request->user(), $story) : null;
                $state = $progress ? $this->play->state($progress)['progress'] : null;

                return [
                    'story' => $story,
                    'cover' => $this->coverUrl($story),
                    'percent' => (int) ($state['percent'] ?? 0),
                    'started' => $progress !== null,
                    'completed' => $progress?->status === 'completed',
                ];
            });

        return view('cinematic.index', ['items' => $stories]);
    }

    public function show(Request $request, string $story)
    {
        $story = $this->story($story);
        $live = $this->access->liveRevision($story);

        abort_if($story->archived_at !== null || $live === null, 404);

        $user = $request->user();
        $decision = $this->access->decide($user, $story);
        $progress = $user ? $this->play->progressFor($user, $story) : null;
        $stages = $decision['allowed'] || ! $story->isPremium()
            ? CinematicStage::query()->where('revision_id', $live->id)->orderBy('position')->get(['title', 'summary'])
            : collect();

        return view('cinematic.show', [
            'story' => $story,
            'revision' => $live,
            'decision' => $decision,
            'progress' => $progress,
            'percent' => $progress ? (int) ($this->play->state($progress)['progress']['percent'] ?? 0) : 0,
            'stages' => $stages,
            'cover' => $this->coverUrl($story),
            'series' => $story->isPremium() ? $story->series : null,
        ]);
    }

    public function play(Request $request, string $story)
    {
        $story = $this->story($story);
        $user = $request->user();
        $decision = $this->access->decide($user, $story);

        if (! $decision['allowed']) {
            return redirect()->route('cinematic.show', $story->slug)->with('cinematic_notice', $decision['reason']);
        }

        $progress = $this->play->progressFor($user, $story);
        $revision = $progress ? CinematicRevision::query()->findOrFail($progress->revision_id) : $this->access->liveRevision($story);

        abort_unless($revision !== null && $this->access->canReadRevision($user, $revision), 404);

        $state = $progress ? $this->play->state($progress) : $this->play->freshState($revision);

        return view('cinematic.play', [
            'story' => $story,
            'preview' => false,
            'config' => [
                'mode' => 'play',
                'title' => $story->title,
                'state' => $state,
                'urls' => [
                    'state' => route('cinematic.state', $story->slug),
                    'start' => route('cinematic.scene.start', [$story->slug, '__SCENE__']),
                    'finish' => route('cinematic.scene.finish', [$story->slug, '__SCENE__']),
                    'submit' => route('cinematic.scene.submit', [$story->slug, '__SCENE__']),
                    'upgrade' => route('cinematic.upgrade', $story->slug),
                    'back' => route('cinematic.show', $story->slug),
                ],
            ],
        ]);
    }

    protected function coverUrl(CinematicStory $story): ?string
    {
        $cover = $story->cover;

        if ($cover === null || ! $cover->isReady()) {
            return null;
        }

        return URL::temporarySignedRoute('cinematic.cover', now()->addSeconds((int) config('cinematic_stories.media.link_ttl_seconds', 900)), ['asset' => $cover->public_id]);
    }
}
