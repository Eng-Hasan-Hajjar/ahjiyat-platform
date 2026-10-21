<?php

namespace App\Models;

use App\Services\LiveOps\MissionEligibilityRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E34 - مجموعة مهام (إعدادات فقط). ليست محرك مهام: قائمة اختيار فوق QuestDefinition.
 * كل تغيير حالة/عضوية يمر بـMissionPoolService (تدقيق + تفريغ الكاش). الإسناد الفعلي للمستخدمين بـMissionAssignmentService.
 */
class MissionPool extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $pool) {
            $pool->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'selection_count' => 'integer',
            'priority' => 'integer',
            'personalized' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function quests(): BelongsToMany
    {
        return $this->belongsToMany(QuestDefinition::class, 'mission_pool_quests', 'mission_pool_id', 'quest_definition_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('mission_pool_quests.sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserMissionAssignment::class, 'mission_pool_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rule(): MissionEligibilityRule
    {
        return MissionEligibilityRule::tryFrom((string) $this->eligibility_rule) ?? MissionEligibilityRule::All;
    }

    /** ضمن نافذتها الزمنية الحقيقية الآن (لا تفحص is_active). */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        return $this->ends_at === null || ! $at->gt($this->ends_at);
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
