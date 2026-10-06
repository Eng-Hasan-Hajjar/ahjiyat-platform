<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\CompetitiveSubmitRequest;
use App\Models\CompetitiveEvent;
use App\Services\Competitive\CompetitiveEventService;
use App\Services\Competitive\CompetitiveException;
use Illuminate\Http\Request;

/**
 * تسجيل/انسحاب/لعب المنافسات. المستخدم المصادَق هو الطرف دائمًا؛ الحدث من المسار بـslug. لا تُقرأ أي score/winner/rank من الطلب: الإجابة
 * وحدها تُمرَّر (CompetitiveSubmitRequest::answerOnly) والباقي يحسبه السيرفر. رفيع: كل المنطق بالخدمات.
 */
class CompetitionPlayController extends Controller
{
    public function __construct(protected CompetitiveEventService $events) {}

    public function register(Request $request, CompetitiveEvent $event)
    {
        return $this->run($event, fn () => $this->events->register($request->user(), $event), 'تم تسجيلك في المنافسة.');
    }

    public function leave(Request $request, CompetitiveEvent $event)
    {
        return $this->run($event, fn () => $this->events->leave($request->user(), $event), 'تم انسحابك من المنافسة.');
    }

    public function start(Request $request, CompetitiveEvent $event)
    {
        try {
            $this->events->start($request->user(), $event);
        } catch (CompetitiveException $e) {
            return redirect()->route('competitions.show', $event)->with('error', $e->getMessage());
        }

        return redirect()->route('competitions.play', $event);
    }

    public function play(Request $request, CompetitiveEvent $event, GameTypeRegistry $games)
    {
        try {
            $session = $this->events->activeRun($request->user(), $event);
        } catch (CompetitiveException $e) {
            return redirect()->route('competitions.show', $event)->with('error', $e->getMessage());
        }

        $event->loadMissing('puzzle');

        return view('competitions.play', [
            'event' => $event,
            'puzzle' => $event->puzzle,
            'renderer' => $games->rendererFor($event->puzzle),
            'startedMs' => (int) ($session->server_state['started_ms'] ?? 0), // للعرض فقط (عدّاد تجميلي): الحساب الفعلي بالسيرفر
        ]);
    }

    public function submit(CompetitiveSubmitRequest $request, CompetitiveEvent $event)
    {
        try {
            $outcome = $this->events->submit($request->user(), $event, $request->answerOnly());
        } catch (CompetitiveException $e) {
            return redirect()->route('competitions.show', $event)->with('error', $e->getMessage());
        }

        return redirect()->route('competitions.show', $event)
            ->with('success', $outcome->correct ? 'إجابة صحيحة! سُجّلت نتيجتك.' : 'إجابة غير صحيحة. سُجّلت نتيجتك.');
    }

    protected function run(CompetitiveEvent $event, \Closure $action, string $success)
    {
        try {
            $action();
        } catch (CompetitiveException $e) {
            return redirect()->route('competitions.show', $event)->with('error', $e->getMessage());
        }

        return redirect()->route('competitions.show', $event)->with('success', $success);
    }
}
