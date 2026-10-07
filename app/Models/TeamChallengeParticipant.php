<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مقعد لاعب بالمباراة (E20-B). **لا يُعدَّل الروستر بعد القفل ولو بيد المالك**: حارس النموذج يمنع تغيير الفريق/اللاعب/الدور بعد locked_at ويمنع الحذف، ولا يُنشأ مقعد جديد
 * إلا والتحدّي معلّق. نتيجة اللاعب تكتبها خدمة اللعب بتحديث شرطي (completed_at IS NULL) مرة واحدة. الفريق هنا لقطة وقت الاختيار: لا يُقرأ من العضوية الحالية أبدًا.
 */
class TeamChallengeParticipant extends Model
{
    public const STATUS_SELECTED = 'selected';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_PLAYED = 'played';

    public const STATUS_MISSED = 'missed';

    protected $fillable = ['team_challenge_id', 'team_id', 'user_id', 'role_snapshot'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime', 'completed_at' => 'datetime', 'is_correct' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (TeamChallengeParticipant $p) {
            if (TeamChallenge::query()->whereKey($p->team_challenge_id)->value('status') !== TeamChallenge::STATUS_PENDING) {
                throw new \InvalidArgumentException('الروستر مقفل: لا إضافة بعد قبول التحدّي.');
            }
        });

        static::updating(function (TeamChallengeParticipant $p) {
            if ($p->getOriginal('locked_at') !== null && $p->isDirty(['team_challenge_id', 'team_id', 'user_id', 'role_snapshot', 'locked_at'])) {
                throw new \InvalidArgumentException('الروستر مقفل: لا تبديل ولا تعديل.');
            }
        });

        static::deleting(function (TeamChallengeParticipant $p) {
            if ($p->locked_at !== null || TeamChallenge::query()->whereKey($p->team_challenge_id)->value('status') !== TeamChallenge::STATUS_PENDING) {
                throw new \InvalidArgumentException('الروستر مقفل: لا حذف.');
            }
        });
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(TeamChallenge::class, 'team_challenge_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
