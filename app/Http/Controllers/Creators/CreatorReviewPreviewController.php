<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\CreatorPuzzleRevision;
use App\Services\Creators\CreatorPreviewService;
use Illuminate\Http\RedirectResponse;

/**
 * E28 - معاينة المراجع لنسخة مرسلة (creators.review بالمسار). المحرك والعارض نفسهما + لوحة حلّ للمراجع فقط (G3). لا تخزين محاولات ولا مكافآت.
 * الرابط غير عام ولا مفهرَس (noindex بالعرض). النسخ المسودة التي لم تُرسل بعد لا تُعاين من هنا (المراجع يرى المرسَل وما بعده فقط).
 */
class CreatorReviewPreviewController extends Controller
{
    public function __construct(protected CreatorPreviewService $previews) {}

    public function show(CreatorPuzzleRevision $revision)
    {
        $this->assertReviewable($revision);
        $revision->loadMissing('work.creator.user:id,name', 'category:id,name');

        $content = (array) $revision->content;
        $solution = match ($revision->kind) {
            'sequence' => ['الترتيب الصحيح (كما أدخله المبدع)' => array_values((array) ($content['items'] ?? []))],
            'memory' => ['أزواج البطاقات' => array_values((array) ($content['faces'] ?? []))],
            'multiple_choice' => ['الجواب الصحيح' => (string) ($content['answer'] ?? ''), 'الاختيارات' => array_values((array) ($content['choices'] ?? []))],
            default => ['الجواب الصحيح' => (string) ($content['answer'] ?? '')],
        };

        return view('creator.puzzles.preview', [
            'work' => $revision->work,
            'revision' => $revision,
            'checkRoute' => route('community.review.check', $revision),
            'backRoute' => null,
            'solution' => $solution,
        ] + $this->previews->view($revision));
    }

    public function check(SolvePuzzleRequest $request, CreatorPuzzleRevision $revision): RedirectResponse
    {
        $this->assertReviewable($revision);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        $result = $this->previews->check($revision, (string) $request->input('answer', ''), $submission, $request->user()->id);

        return back()->with($result->correct ? 'success' : 'error', $result->correct ? 'إجابة صحيحة (معاينة مراجع).' : 'إجابة غير صحيحة (معاينة مراجع).');
    }

    protected function assertReviewable(CreatorPuzzleRevision $revision): void
    {
        abort_if($revision->status === CreatorPuzzleRevision::STATUS_DRAFT, 404);
    }
}
