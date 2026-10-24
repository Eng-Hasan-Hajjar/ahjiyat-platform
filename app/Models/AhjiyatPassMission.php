<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E40 - مهمة مسار = **وصلة إعداد** فوق QuestDefinition قائم (لا محرك مهام ثانٍ ولا مُقيِّم ثانٍ). الصعوبة (easy|medium|hard) بيانات عرض/إدارة فقط:
 * لا تغيّر المُقيِّم ولا المكافأة الأصلية للمهمة. التقدّم والإكمال يبقيان في UserQuestProgress؛ هذه الوصلة تحدد فقط كم نقطة مسار تمنحها وكم إكمالًا يُحتسب.
 * تُقفل بنشر المسار (النقاط/الصعوبة/السقف) لتبقى النقاط الممنوحة قابلة للتفسير.
 */
class AhjiyatPassMission extends Model
{
    public const EASY = 'easy';

    public const MEDIUM = 'medium';

    public const HARD = 'hard';

    public const DIFFICULTIES = [self::EASY => 'سهلة', self::MEDIUM => 'متوسطة', self::HARD => 'صعبة'];

    protected $table = 'ahjiyat_pass_missions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'pass_points' => 'integer',
            'max_completions' => 'integer',
            'week_number' => 'integer',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $mission) => $mission->assertParentUnlocked());
        static::updating(function (self $mission): void {
            foreach (['ahjiyat_pass_id', 'quest_definition_id'] as $immutable) {
                if ($mission->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            $mission->assertParentUnlocked();
        });
        static::deleting(fn (self $mission) => $mission->assertParentUnlocked());
    }

    protected function assertParentUnlocked(): void
    {
        $pass = AhjiyatPass::query()->find($this->ahjiyat_pass_id);

        if ($pass !== null && $pass->locked_at !== null) {
            throw new LogicException('مهام مسار منشور مقفلة.');
        }
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPass::class, 'ahjiyat_pass_id');
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(QuestDefinition::class, 'quest_definition_id');
    }

    public function difficultyLabel(): string
    {
        return self::DIFFICULTIES[$this->difficulty] ?? $this->difficulty;
    }
}
