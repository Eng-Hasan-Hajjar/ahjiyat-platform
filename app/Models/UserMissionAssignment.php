<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E34 - لقطة إسناد مهمة لمستخدم في فترة (تحدّد الظهور فقط). تُكتب حصرًا عبر MissionAssignmentService.
 * لا واجهة تكتبها ولا حقل يأتي من العميل.
 */
class UserMissionAssignment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(MissionPool::class, 'mission_pool_id');
    }

    public function questDefinition(): BelongsTo
    {
        return $this->belongsTo(QuestDefinition::class);
    }
}
