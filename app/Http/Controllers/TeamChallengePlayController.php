<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompetitiveSubmitRequest;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Services\Competitive\CompetitiveException;
use App\Services\Teams\TeamChallengePlayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * لعب لاعب الروستر (E20-B14): البدء والإرسال فقط. المشارك يُحدَّد من **مقعده المقفل** بقاعدة البيانات (لا فريق ولا درجة ولا فائز من الطلب)، وCompetitiveSubmitRequest يمرّر الإجابة وحدها
 * (answer/submission.order|matches|moves). غير المشارك 404. الدرجة والمدة والصحة يحسبها الخادم عبر CompetitiveRunService.
 */
class TeamChallengePlayController extends Controller
{
    public function __construct(protected TeamChallengePlayService $play) {}

    public function start(Request $request, TeamChallenge $challenge): RedirectResponse
    {
        abort_unless($this->play->participantFor($request->user(), $challenge) !== null, 404);

        try {
            $this->play->start($request->user(), $challenge);
        } catch (CompetitiveException $e) {
            return redirect()->route('teams.challenges.show', $challenge)->with('error', $e->getMessage());
        }

        return redirect()->route('teams.challenges.show', $challenge);
    }

    public function submit(CompetitiveSubmitRequest $request, TeamChallenge $challenge): RedirectResponse
    {
        abort_unless($this->play->participantFor($request->user(), $challenge) !== null, 404);

        try {
            $outcome = $this->play->submit($request->user(), $challenge, $request->answerOnly());
        } catch (CompetitiveException $e) {
            return redirect()->route('teams.challenges.show', $challenge)->with('error', $e->getMessage());
        }

        return redirect()->route('teams.challenges.show', $challenge)->with('success', $outcome->correct ? 'إجابة صحيحة! سُجّلت نتيجتك لفريقك.' : 'إجابة غير صحيحة. سُجّلت نتيجتك.');
    }
}
