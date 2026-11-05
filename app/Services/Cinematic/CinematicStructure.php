<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use Illuminate\Support\Collection;

/** CIN-1 - ترتيب موحّد للمشاهد داخل نسخة: المرحلة ثم الفصل ثم المشهد. المصدر الوحيد للترتيب الخطي (يعتمد عليه الفتح والتقدّم). */
final class CinematicStructure
{
    /** @return Collection<int, CinematicScene> */
    public static function scenes(CinematicRevision $revision): Collection
    {
        return CinematicScene::query()
            ->where('cinematic_scenes.revision_id', $revision->id)
            ->join('cinematic_stages', 'cinematic_stages.id', '=', 'cinematic_scenes.stage_id')
            ->join('cinematic_chapters', 'cinematic_chapters.id', '=', 'cinematic_scenes.chapter_id')
            ->orderBy('cinematic_stages.position')->orderBy('cinematic_stages.id')
            ->orderBy('cinematic_chapters.position')->orderBy('cinematic_chapters.id')
            ->orderBy('cinematic_scenes.position')->orderBy('cinematic_scenes.id')
            ->select('cinematic_scenes.*')
            ->with(['stage', 'chapter'])
            ->get();
    }
}
