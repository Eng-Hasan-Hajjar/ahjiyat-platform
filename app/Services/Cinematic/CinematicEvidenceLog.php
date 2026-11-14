<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\User;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use App\Services\Cinematic\Media\CinematicMediaService;

/**
 * CIN-2 - «سجل الأدلة»: قائمة القرائن التي اكتشفها اللاعب، **مشتقة** من التقدّم الموجود (المشاهد المكتملة) لا من جدول موازٍ.
 *
 *  - تعريف الدليل يعيش في إعداد المشهد (config.evidence: عنوان، ملخص، رقم القرينة، صورة المشهد اختياريًا، علامة على الصورة).
 *    هذا الإعداد لا يُرسل مع بيانات المشهد (CinematicScenePayload قائمة بيضاء) فلا يصل المتصفح إلا من هذه الخدمة وبعد إتمام المشهد.
 *  - لا يُعرض إلا دليل مشهد **مكتمل** في نسخة هذا التقدّم: لا أدلة مستقبلية، ولا حلول (الحلول مشفّرة في CinematicSceneSolution ولا تُقرأ هنا).
 *  - استئناف اللعب يحفظ السجل تلقائيًا لأنه يُحسب من حالات المشاهد المخزّنة. المعاينة الإشرافية لا تكتب شيئًا.
 *  - الصورة رابط موقَّع قصير العمر لصورة المشهد (poster) كباقي الوسائط، فلا مسار تخزين ولا معرّف داخلي يتسرّب.
 */
class CinematicEvidenceLog
{
    public function __construct(protected CinematicMediaLinks $links, protected CinematicMediaService $media) {}

    /** @return list<array<string, mixed>> بترتيب المشاهد. */
    public function forProgress(CinematicProgress $progress, User $user): array
    {
        $states = CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->get()->keyBy('scene_id');

        if ($states->isEmpty()) {
            return [];
        }

        $revision = CinematicRevision::query()->findOrFail($progress->revision_id);
        $out = [];

        foreach (CinematicStructure::scenes($revision)->load(['poster', 'chapter']) as $scene) {
            $state = $states->get($scene->id);
            $entry = $state ? $this->entry($scene, $user, false, $state->completed_at?->toIso8601String()) : null;

            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * دليل مشهد واحد (أو null إن لم يعرّف المشهد دليلًا). يُستعمل أيضًا في المعاينة الإشرافية (بلا حفظ).
     *
     * @return array<string, mixed>|null
     */
    public function entry(CinematicScene $scene, User $user, bool $preview, ?string $at = null): ?array
    {
        $ev = (array) ($scene->config['evidence'] ?? []);
        $title = (string) ($ev['title'] ?? '');
        $summary = (string) ($ev['summary'] ?? '');

        if ($title === '' && $summary === '') {
            return null;
        }

        $image = null;

        if (($ev['show_image'] ?? false) && $scene->poster !== null && $scene->poster->isReady() && $this->media->absolutePath($scene->poster) !== null) {
            $image = $this->links->url($scene->poster, CinematicMediaLinks::SCOPE_SCENE, $scene->public_id, $user, $preview);
        }

        return [
            'key' => $scene->stable_key,
            'title' => $title,
            'summary' => $summary,
            'clue' => isset($ev['clue']) ? (int) $ev['clue'] : null,
            'image' => $image,
            'marker' => is_array($ev['marker'] ?? null) ? $ev['marker'] : null,
            'chapter' => (string) ($scene->chapter?->title ?? ''),
            'at' => $at,
        ];
    }
}
