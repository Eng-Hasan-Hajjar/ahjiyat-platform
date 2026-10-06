<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\CompetitiveSubmitRequest;
use App\Http\Requests\FriendChallengeStoreRequest;
use App\Models\FriendChallenge;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Competitive\CompetitiveEligibility;
use App\Services\Competitive\CompetitiveException;
use App\Services\Competitive\FriendChallengeService;
use App\Services\Social\PlayerCardLoader;
use Illuminate\Http\Request;

/**
 * تحدّيات الأصدقاء. الطرف الحالي هو المستخدم المصادَق دائمًا؛ الخصم من المسار بـpublic_id (لا user_id من النموذج)، والتحدي بـpublic_id (ULID).
 * غير المشارك بتحدٍّ يرى 404 (لا كشف وجود). لا تُقرأ أي score/winner من الطلب. رفيع: المنطق كله بـFriendChallengeService.
 */
class FriendChallengeController extends Controller
{
    public function __construct(protected FriendChallengeService $challenges, protected CompetitiveEligibility $eligibility) {}

    public function index(Request $request, PlayerCardLoader $cards)
    {
        $me = $request->user();
        $per = (int) config('competitive.history_per_page', 10);
        $now = now();
        $with = ['challenger:id,name,public_id,profile_visibility', 'opponent:id,name,public_id,profile_visibility', 'puzzle:id,title', 'results'];
        $mine = fn () => FriendChallenge::query()->involving($me->id)->with($with)->latest('id');

        $pending = $mine()->where('status', FriendChallenge::STATUS_PENDING)->where('expires_at', '>', $now)->paginate($per, ['*'], 'pending_page');
        $active = $mine()->where('status', FriendChallenge::STATUS_ACCEPTED)->where('expires_at', '>', $now)->paginate($per, ['*'], 'active_page');
        $history = $mine()->where(fn ($q) => $q->whereIn('status', [FriendChallenge::STATUS_COMPLETED, FriendChallenge::STATUS_DECLINED, FriendChallenge::STATUS_CANCELLED, FriendChallenge::STATUS_EXPIRED])
            ->orWhere(fn ($w) => $w->whereIn('status', FriendChallenge::ACTIVE_STATUSES)->where('expires_at', '<=', $now)))->paginate($per, ['*'], 'history_page');

        $cards->attach(collect([$pending, $active, $history])->flatMap(fn ($p) => $p->getCollection())->flatMap(fn ($c) => [$c->challenger, $c->opponent])->unique('id'), $me);

        return view('friends.challenges.index', compact('pending', 'active', 'history'));
    }

    public function create(Request $request, User $user)
    {
        $me = $request->user();

        if (! $this->challenges->canChallenge($me, $user)) {
            return redirect()->route('friends.index')->with('error', 'يمكنك تحدّي أصدقائك فقط.');
        }

        $request->validate(['q' => ['nullable', 'string', 'max:50']]);
        $term = trim((string) $request->query('q', ''));

        $query = $this->eligibility->eligiblePuzzlesQuery()->select('id', 'title', 'difficulty', 'time_limit_seconds')->orderBy('title')->orderBy('id');

        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->whereRaw("LOWER(title) like ? escape '!'", [$like]);
        }

        return view('friends.challenges.create', [
            'opponent' => $user,
            'term' => $term,
            'puzzles' => $query->paginate((int) config('competitive.puzzle_search_per_page', 8))->withQueryString(),
        ]);
    }

    public function store(FriendChallengeStoreRequest $request, User $user)
    {
        $puzzle = Puzzle::query()->find($request->integer('puzzle_id'));

        try {
            $challenge = $puzzle === null
                ? throw new CompetitiveException('هذه الأحجية غير متاحة.')
                : $this->challenges->create($request->user(), $user, $puzzle);
        } catch (CompetitiveException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('friends.challenges.show', $challenge)->with('success', 'أُرسل التحدي.');
    }

    public function show(Request $request, FriendChallenge $challenge, GameTypeRegistry $games, PlayerCardLoader $cards)
    {
        $me = $request->user();
        abort_unless($challenge->involves($me), 404);

        $challenge->load(['challenger', 'opponent', 'puzzle', 'results']);
        $other = $challenge->otherSide($me);
        $cards->attach([$other], $me);

        $status = $challenge->effectiveStatus();
        $mine = $challenge->results->firstWhere('user_id', $me->id);
        $theirs = $status === FriendChallenge::STATUS_COMPLETED ? $challenge->results->firstWhere('user_id', $other->id) : null; // نتيجة الخصم لا تُعرض قبل الاكتمال
        $run = $status === FriendChallenge::STATUS_ACCEPTED && $mine === null ? $this->challenges->activeRun($me, $challenge) : null;

        return view('friends.challenges.show', [
            'challenge' => $challenge, 'me' => $me, 'other' => $other, 'status' => $status, 'mine' => $mine, 'theirs' => $theirs,
            'run' => $run, 'puzzle' => $run ? $challenge->puzzle : null, 'renderer' => $run ? $games->rendererFor($challenge->puzzle) : null,
            'startedMs' => $run ? (int) ($run->server_state['started_ms'] ?? 0) : 0,
            'isChallenger' => $challenge->challenger_id === $me->id,
        ]);
    }

    public function accept(Request $request, FriendChallenge $challenge)
    {
        return $this->act($request, $challenge, fn () => $this->challenges->accept($request->user(), $challenge), 'قبلت التحدي، ويمكنك اللعب الآن.');
    }

    public function decline(Request $request, FriendChallenge $challenge)
    {
        return $this->act($request, $challenge, fn () => $this->challenges->decline($request->user(), $challenge), 'تم رفض التحدي.');
    }

    public function cancel(Request $request, FriendChallenge $challenge)
    {
        return $this->act($request, $challenge, fn () => $this->challenges->cancel($request->user(), $challenge), 'أُلغي التحدي.');
    }

    public function start(Request $request, FriendChallenge $challenge)
    {
        return $this->act($request, $challenge, fn () => $this->challenges->start($request->user(), $challenge), null);
    }

    public function submit(CompetitiveSubmitRequest $request, FriendChallenge $challenge)
    {
        abort_unless($challenge->involves($request->user()), 404);

        try {
            $outcome = $this->challenges->submit($request->user(), $challenge, $request->answerOnly());
        } catch (CompetitiveException $e) {
            return redirect()->route('friends.challenges.show', $challenge)->with('error', $e->getMessage());
        }

        return redirect()->route('friends.challenges.show', $challenge)
            ->with('success', $outcome->correct ? 'إجابة صحيحة! سُجّلت نتيجتك.' : 'إجابة غير صحيحة. سُجّلت نتيجتك.');
    }

    protected function act(Request $request, FriendChallenge $challenge, \Closure $action, ?string $success)
    {
        abort_unless($challenge->involves($request->user()), 404); // غير المشارك: 404

        try {
            $action();
        } catch (CompetitiveException $e) {
            return redirect()->route('friends.challenges.show', $challenge)->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('friends.challenges.show', $challenge);

        return $success === null ? $redirect : $redirect->with('success', $success);
    }
}
