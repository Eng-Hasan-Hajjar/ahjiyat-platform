<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E28 - لعب أحجيات المجتمع. **لا اقتصاد ولا تقدّم بالبناء**: هذه الخدمة لا تلمس PuzzleAttemptService ولا المحافظ ولا XP ولا المهام ولا السلسلة ولا الإنجازات ولا التأهيل ولا المنافسات،
 * ولا تكتب إلى puzzle_attempts؛ تكتب فقط إلى community_puzzle_attempts (حالة الحل/المحاولات + تحليلات المبدع + الرائج).
 * التحقق من الجواب = المدقّق الرسمي نفسه (GameTypeRegistry::validatorFor) فلا مدقّق ثانٍ. المحاولات لكل نسخة قانونية (puzzle_id): النسخة الجديدة المعتمدة لا تُعيد كتابة تاريخ القديمة.
 * التلميح مجاني (لا خصم جواهر). المبدع لا يلعب أحجيته (يعاينها)، فلا يضخّم أرقامه.
 */
class CommunityPlayService
{
    public function __construct(protected GameTypeRegistry $games) {}

    /** @return array{used:int, solved:bool, max:int, left:int} */
    public function state(?User $user, Puzzle $puzzle): array
    {
        $max = (int) $puzzle->max_attempts;

        if ($user === null) {
            return ['used' => 0, 'solved' => false, 'max' => $max, 'left' => $max];
        }

        $row = CommunityPuzzleAttempt::query()
            ->where('user_id', $user->id)->where('puzzle_id', $puzzle->id)
            ->selectRaw('COUNT(*) as used, COALESCE(MAX(CASE WHEN is_correct THEN 1 ELSE 0 END), 0) as solved')
            ->first();

        $used = (int) ($row->used ?? 0);

        return ['used' => $used, 'solved' => (bool) ($row->solved ?? false), 'max' => $max, 'left' => max(0, $max - $used)];
    }

    /** @return array{correct: bool, attempts_left: int} */
    public function attempt(User $user, CreatorPuzzle $work, string $answer, array $submission = []): array
    {
        $puzzle = $this->playablePuzzle($work);

        if ($work->creator->user_id === $user->id) {
            throw new CreatorException('هذه أحجيتك؛ استخدم المعاينة من استوديو المبدعين.');
        }

        return DB::transaction(function () use ($user, $work, $puzzle, $answer, $submission) {
            $rows = CommunityPuzzleAttempt::query()
                ->where('user_id', $user->id)->where('puzzle_id', $puzzle->id)
                ->lockForUpdate()->get(['id', 'is_correct']);

            if ($rows->contains('is_correct', true)) {
                throw new CreatorException('سبق أن حللت هذه الأحجية.');
            }

            if ($rows->count() >= $puzzle->max_attempts) {
                throw new CreatorException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            $payload = $submission !== [] ? $submission : ['answer' => $answer];
            $correct = $this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => $user->id]])->correct;

            $firstForWork = ! CommunityPuzzleAttempt::query()->where('creator_puzzle_id', $work->id)->where('user_id', $user->id)->exists();

            $attempt = new CommunityPuzzleAttempt;
            $attempt->forceFill([
                'user_id' => $user->id,
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $puzzle->id,
                'attempt_number' => $rows->count() + 1,
                'is_correct' => $correct,
            ])->save();

            if ($firstForWork) {
                CreatorPuzzle::query()->whereKey($work->id)->increment('plays_count'); // لاعب مميَّز واحد لكل عمل؛ تحديث ذرّي بلا أحداث نموذج
            }

            return ['correct' => $correct, 'attempts_left' => max(0, (int) $puzzle->max_attempts - ($rows->count() + 1))];
        });
    }

    /** التلميح مجاني لأحجيات المجتمع (لا محفظة ولا عملة). */
    public function hint(CreatorPuzzle $work): string
    {
        $puzzle = $this->playablePuzzle($work);

        if (blank($puzzle->hint)) {
            throw new CreatorException('لا يوجد تلميح لهذه الأحجية.');
        }

        return (string) $puzzle->hint;
    }

    protected function playablePuzzle(CreatorPuzzle $work): Puzzle
    {
        $puzzle = $work->is_visible ? $work->publishedPuzzle : null;

        if ($puzzle === null || ! $puzzle->isCommunity()) {
            throw new CreatorException('هذه الأحجية غير متاحة حاليًا.');
        }

        $work->loadMissing('creator');

        return $puzzle;
    }
}
