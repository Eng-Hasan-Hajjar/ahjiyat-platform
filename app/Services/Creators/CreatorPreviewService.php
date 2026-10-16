<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;

/**
 * E28 - معاينة المبدع/المراجع: **المحرك والعارض والمدقّق نفسها** على أحجية غير محفوظة تُبنى من النسخة (CreatorPuzzleMaterializer::build) بلا أي كتابة.
 * لا محاولات ولا جواهر ولا XP ولا مهام ولا تقدّم ولا تسجيل لعب: النتيجة تُعرض فقط. الرابط غير عام (مالك/مراجع) ولا يُفهرَس.
 */
class CreatorPreviewService
{
    public function __construct(
        protected CreatorPuzzleMaterializer $materializer,
        protected GameTypeRegistry $games,
    ) {}

    /** @return array{puzzle: Puzzle, renderer: string} */
    public function view(CreatorPuzzleRevision $revision): array
    {
        $puzzle = $this->materializer->build($revision);

        return ['puzzle' => $puzzle, 'renderer' => $this->games->rendererFor($puzzle)];
    }

    public function check(CreatorPuzzleRevision $revision, string $answer, array $submission, ?int $userId): PreviewResult
    {
        $puzzle = $this->materializer->build($revision);
        $payload = $submission !== [] ? $submission : ['answer' => $answer];

        return new PreviewResult($this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => $userId]])->correct);
    }
}
