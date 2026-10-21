<?php

namespace App\Services\LiveOps;

use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E34 - إدارة مجموعات المهام (طبقة إدارة فوق QuestDefinition؛ لا محرك مهام).
 *
 * كل كتابة: صلاحية liveops.manage + حد معدّل لكل مشرف + تدقيق + معاملة + تفريغ كاش اللقطة المشتركة.
 * لا أثر رجعي: تعديل مجموعة لا يغيّر لقطة إسناد سبق أن أُنشئت لأي مستخدم (يسري على الفترة التالية).
 * لا تُحذف مجموعة لها تاريخ إسناد (تُعطَّل). لا تُفعَّل مجموعة بلا مهام (لا شيء يُسند).
 */
class MissionPoolService
{
    public function __construct(protected OperationalAuditService $audit) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): MissionPool
    {
        $this->authorize($actor);
        $clean = $this->validated($data, null);

        $pool = DB::transaction(function () use ($clean, $actor) {
            $p = new MissionPool;
            $p->forceFill($clean + ['is_active' => false, 'created_by' => $actor->id])->save();

            return $p;
        });

        $this->audit->log('mission_pool_created', $pool, ['slug' => $pool->slug], $actor);
        MissionAssignmentService::flushSnapshot();

        return $pool;
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, MissionPool $pool, array $data): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool, $data) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $clean = $this->validated($data + $locked->only(['name', 'slug', 'period_type']), $locked);

            if ($clean['period_type'] !== $locked->period_type
                && ($locked->assignments()->exists() || DB::table('mission_pool_quests')->where('mission_pool_id', $locked->id)->exists())) {
                throw new LiveOpsException('period_locked', 'لا يمكن تغيير نوع فترة مجموعة لها مهام أو سجل إسناد.');
            }

            $before = $locked->only(array_keys($clean));
            $locked->forceFill($clean)->save();

            $this->audit->log('mission_pool_updated', $locked, ['before' => $before, 'after' => $locked->only(array_keys($clean))], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function activate(User $actor, MissionPool $pool): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if (! DB::table('mission_pool_quests')->where('mission_pool_id', $locked->id)->exists()) {
                throw new LiveOpsException('empty_pool', 'لا يمكن تفعيل مجموعة بلا مهام.');
            }

            // الحدّ الصلب الوحيد (غير الآمن فعلًا): لا يتجاوز الحدّ الأعلى النظري لمهام المستخدم المتزامنة الحدّ المضبوط. بقية التجاوزات تحذيرات فقط.
            if (app(LiveOpsPlannerService::class)->wouldExceedHardCap($locked)) {
                throw new LiveOpsException('hard_cap', 'تفعيل هذه المجموعة يتجاوز الحدّ الأقصى لمهام اللاعب المتزامنة. خفّض المهام الفعّالة أو عدد الاختيار أولًا.');
            }

            $locked->forceFill(['is_active' => true])->save();
            $this->audit->log('mission_pool_activated', $locked, ['slug' => $locked->slug], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function deactivate(User $actor, MissionPool $pool): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => false])->save();
            $this->audit->log('mission_pool_deactivated', $locked, ['slug' => $locked->slug], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function attach(User $actor, MissionPool $pool, QuestDefinition $quest, int $sortOrder = 0): void
    {
        $this->authorize($actor);

        if ($quest->period_type !== $pool->period_type) {
            throw new LiveOpsException('period_mismatch', 'نوع فترة المهمة لا يطابق نوع فترة المجموعة.');
        }

        try {
            DB::transaction(function () use ($actor, $pool, $quest, $sortOrder) {
                $now = now();

                if (DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->where('quest_definition_id', $quest->id)->exists()) {
                    throw new LiveOpsException('already_member', 'المهمة عضو بالمجموعة أصلًا.');
                }

                DB::table('mission_pool_quests')->insert([
                    'mission_pool_id' => $pool->id,
                    'quest_definition_id' => $quest->id,
                    'sort_order' => max(0, min(65535, $sortOrder)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->audit->log('mission_pool_quest_attached', $pool, ['quest' => $quest->internal_key], $actor);
            });
        } catch (UniqueConstraintViolationException) {
            throw new LiveOpsException('already_member', 'المهمة عضو بالمجموعة أصلًا.');
        }

        MissionAssignmentService::flushSnapshot();
    }

    public function detach(User $actor, MissionPool $pool, QuestDefinition $quest): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $pool, $quest) {
            $removed = DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->where('quest_definition_id', $quest->id)->delete();

            if ($removed > 0) {
                $this->audit->log('mission_pool_quest_detached', $pool, ['quest' => $quest->internal_key], $actor);
            }
        });

        MissionAssignmentService::flushSnapshot();
    }

    public function delete(User $actor, MissionPool $pool): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if ($locked->assignments()->exists()) {
                throw new LiveOpsException('has_history', 'لا تُحذف مجموعة لها سجل إسناد؛ عطّلها بدلًا من ذلك.');
            }

            $this->audit->log('mission_pool_deleted', $locked, ['slug' => $locked->slug], $actor);
            $locked->delete();
        });

        MissionAssignmentService::flushSnapshot();
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->can('liveops.manage')) {
            throw new LiveOpsException('forbidden', 'لا تملك صلاحية إدارة مجموعات المهام.');
        }

        $key = 'liveops-action:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('liveops.limits.action_per_minute', 30))) {
            throw new LiveOpsException('rate_limited', 'عدد كبير من إجراءات LiveOps خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function validated(array $data, ?MissionPool $existing): array
    {
        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('mission_pools', 'slug')->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'period_type' => ['required', Rule::in(QuestDefinition::PERIOD_TYPES)],
            'selection_count' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('liveops.pools.max_selection', 10)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'eligibility_rule' => ['sometimes', Rule::in(array_keys(MissionEligibilityRule::options()))],
            'personalized' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...(! empty($data['starts_at']) ? ['after:starts_at'] : [])],
        ]);

        if ($v->fails()) {
            throw ValidationException::withMessages($v->errors()->toArray());
        }

        $d = $v->validated();

        return [
            'name' => $d['name'],
            'slug' => $d['slug'],
            'description' => $d['description'] ?? null,
            'period_type' => $d['period_type'],
            'selection_count' => (int) ($d['selection_count'] ?? $existing?->selection_count ?? 3),
            'priority' => (int) ($d['priority'] ?? $existing?->priority ?? 100),
            'eligibility_rule' => $d['eligibility_rule'] ?? $existing?->eligibility_rule ?? MissionEligibilityRule::All->value,
            'personalized' => (bool) ($d['personalized'] ?? $existing?->personalized ?? false),
            'starts_at' => $d['starts_at'] ?? null,
            'ends_at' => $d['ends_at'] ?? null,
        ];
    }
}
