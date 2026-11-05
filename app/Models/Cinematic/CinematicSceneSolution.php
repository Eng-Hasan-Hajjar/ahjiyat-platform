<?php

namespace App\Models\Cinematic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CIN-1 - حل مشهد (خادمي فقط). payload مشفّر بمفتاح التطبيق ($hidden يمنع أي تسلسل عرضي). لا تُعرَض هذه الموديل ولا قيمتها في أي استجابة.
 */
class CinematicSceneSolution extends Model
{
    protected $table = 'cinematic_scene_solutions';

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(CinematicScene::class, 'scene_id');
    }
}
