<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\AhjiyatPass\Concerns\AuthorizesPass;
use App\Services\OperationalAuditService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * E40 - بنّاء المسار (طبقة إدارة فوق الخدمات والحُرّاس؛ لا تعديل خام للقاعدة). كل كتابة: صلاحية داخل الخدمة + معاملة + تدقيق + تفريغ كاش التعريف.
 *
 * ما قبل النشر (مسودة): كل شيء قابل للتعديل (نافذة، سعر، شرائح، مكافآت، مهام).
 * بعد النشر (locked_at): العرض فقط (الاسم/الوصف/البانر/المظهر/التمييز) ونافذة بيع المميز (تقصير/إغلاق مبكر ضمن حدود المسار). الباقي مقفل هنا وفي حراس النماذج.
 * لا محرك مهام ولا مكافآت ثانٍ: المهام = وصلات فوق QuestDefinition؛ المكافآت = مراجع StoreItem.
 */
class PassBuilderService
{
    use AuthorizesPass;

    public function __construct(
        protected OperationalAuditService $audit,
        protected PassDefinitionCache $cache,
        protected PassMedia $media,
    ) {}

    // ───────────────────────────── المسار نفسه ─────────────────────────────

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, array $data): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.manage');

        $starts = $this->date($data['starts_at'] ?? null, 'تاريخ البداية');
        $ends = isset($data['ends_at']) ? $this->date($data['ends_at'], 'تاريخ النهاية') : $starts->copy()->addDays((int) config('ahjiyat_pass.lifecycle.default_duration_days', 30));
        $key = $this->key($data['internal_key'] ?? null);
        $grace = $this->grace($data['claim_grace_days'] ?? null);

        $attributes = [
            'internal_key' => $key,
            'slug' => $key,
            'name' => $this->text($data['name'] ?? null, 120, 'اسم المسار', true),
            'subtitle' => $this->text($data['subtitle'] ?? null, 160, 'العنوان الفرعي'),
            'description' => $this->text($data['description'] ?? null, 5000, 'الوصف'),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'claim_grace_days' => $grace,
            'claim_ends_at' => $ends->copy()->addDays($grace),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'theme_config' => $this->theme($data['theme'] ?? null),
            'created_by' => $actor->getKey(),
        ] + $this->commerceAttributes($data, true);

        try {
            $pass = DB::transaction(function () use ($attributes, $actor) {
                $pass = new AhjiyatPass;
                $pass->forceFill($attributes + ['status' => AhjiyatPass::STATUS_DRAFT])->save();
                $this->audit->log('ahjiyat_pass_created', $pass, ['public_id' => $pass->public_id, 'internal_key' => $pass->internal_key], $actor);

                return $pass;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PassException('duplicate_key', 'المفتاح الداخلي مستخدم لمسار آخر.');
        }

        $this->cache->flush();

        return $pass;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, AhjiyatPass $pass, array $data): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.manage');

        $updated = DB::transaction(function () use ($actor, $pass, $data) {
            $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === AhjiyatPass::STATUS_ARCHIVED) {
                throw new PassException('archived', 'المسار المؤرشف لا يُعدَّل.');
            }

            $before = $locked->only(['name', 'subtitle', 'description', 'is_featured', 'theme_config', 'premium_sales_start_at', 'premium_sales_end_at', 'starts_at', 'ends_at', 'premium_price_minor', 'currency']);
            $changes = [];

            foreach (['name' => [120, 'اسم المسار', true], 'subtitle' => [160, 'العنوان الفرعي', false], 'description' => [5000, 'الوصف', false]] as $field => [$max, $label, $required]) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $this->text($data[$field], $max, $label, $required);
                }
            }

            if (array_key_exists('is_featured', $data)) {
                $changes['is_featured'] = (bool) $data['is_featured'];
            }

            if (array_key_exists('theme', $data)) {
                $changes['theme_config'] = $this->theme($data['theme']);
            }

            if ($locked->locked_at === null) {
                if (array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data) || array_key_exists('claim_grace_days', $data)) {
                    $starts = array_key_exists('starts_at', $data) ? $this->date($data['starts_at'], 'تاريخ البداية') : $locked->starts_at;
                    $ends = array_key_exists('ends_at', $data) ? $this->date($data['ends_at'], 'تاريخ النهاية') : $locked->ends_at;
                    $grace = array_key_exists('claim_grace_days', $data) ? $this->grace($data['claim_grace_days']) : (int) $locked->claim_grace_days;
                    $changes += ['starts_at' => $starts, 'ends_at' => $ends, 'claim_grace_days' => $grace, 'claim_ends_at' => $ends->copy()->addDays($grace)];
                }

                if (array_key_exists('internal_key', $data) && $data['internal_key'] !== $locked->internal_key) {
                    $key = $this->key($data['internal_key']);
                    $changes += ['internal_key' => $key, 'slug' => $key];
                }

                $changes += $this->commerceAttributes($data, false, $locked);
            } else {
                // قيمة مطابقة للحالية تُتجاهل (نماذج الإدارة ترسل الحقول كلها)؛ أي قيمة مختلفة لحقل مقفل ترفض.
                $same = [
                    'starts_at' => fn ($v) => $this->date($v, 'تاريخ البداية')->equalTo($locked->starts_at),
                    'ends_at' => fn ($v) => $this->date($v, 'تاريخ النهاية')->equalTo($locked->ends_at),
                    'claim_grace_days' => fn ($v) => (int) $v === (int) $locked->claim_grace_days,
                    'internal_key' => fn ($v) => (string) $v === (string) $locked->internal_key,
                    'premium_enabled' => fn ($v) => (bool) $v === (bool) $locked->premium_enabled,
                    'premium_price_minor' => fn ($v) => $v !== null && $v !== '' && (int) $v === (int) $locked->premium_price_minor,
                    'currency' => fn ($v) => strtoupper((string) $v) === (string) $locked->currency,
                    'premium_price' => fn ($v) => false,
                ];

                foreach ($same as $lockedField => $isSame) {
                    if (array_key_exists($lockedField, $data) && ! $isSame($data[$lockedField])) {
                        throw new PassException('locked', 'الحقل مقفل بعد نشر المسار.');
                    }
                }

                $window = $this->salesWindow($data, $locked);

                if ($window !== []) {
                    $changes += $window;
                }
            }

            try {
                $locked->forceFill($changes)->save();
            } catch (UniqueConstraintViolationException) {
                throw new PassException('duplicate_key', 'المفتاح الداخلي مستخدم لمسار آخر.');
            }

            $this->audit->log('ahjiyat_pass_updated', $locked, ['public_id' => $locked->public_id, 'fields' => array_keys($changes), 'locked' => $locked->locked_at !== null], $actor);

            return $locked;
        });

        $this->cache->flush();

        return $updated;
    }

    public function setBanner(User $actor, AhjiyatPass $pass, UploadedFile $file): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.manage');
        $path = $this->media->store($file);
        $old = null;

        try {
            $updated = DB::transaction(function () use ($actor, $pass, $path, &$old) {
                $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status === AhjiyatPass::STATUS_ARCHIVED) {
                    throw new PassException('archived', 'المسار المؤرشف لا يُعدَّل.');
                }

                $old = $locked->banner_path;
                $locked->forceFill(['banner_path' => $path])->save();
                $this->audit->log('ahjiyat_pass_banner_set', $locked, ['public_id' => $locked->public_id], $actor);

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->media->delete($path);

            throw $e;
        }

        $this->media->delete($old);
        $this->cache->flush();

        return $updated;
    }

    // ───────────────────────────── الشرائح ─────────────────────────────

    /**
     * يستبدل شرائح المسودة بقائمة جديدة (الترقيم 1..N تلقائي). تُحفظ مكافآت الشرائح التي يبقى رقمها.
     *
     * @param  list<array{required_points: int|string, label?: ?string, is_milestone?: bool}>  $tiers
     */
    public function replaceTiers(User $actor, AhjiyatPass $pass, array $tiers): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.manage');
        $rows = $this->validatedTiers($tiers);

        $updated = DB::transaction(function () use ($actor, $pass, $rows) {
            $locked = $this->lockedDraft($pass);
            $existing = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $locked->getKey())->get()->keyBy('tier_number');

            foreach ($rows as $index => $row) {
                $number = $index + 1;
                $tier = $existing->get($number) ?? new AhjiyatPassTier;
                $tier->forceFill(['ahjiyat_pass_id' => $locked->getKey(), 'tier_number' => $number] + $row)->save();
            }

            foreach ($existing as $number => $tier) {
                if ($number > count($rows)) {
                    AhjiyatPassTierReward::query()->where('ahjiyat_pass_tier_id', $tier->getKey())->get()->each->delete();
                    $tier->delete();
                }
            }

            $locked->forceFill(['total_tiers' => count($rows)])->save();
            $this->audit->log('ahjiyat_pass_tiers_replaced', $locked, ['public_id' => $locked->public_id, 'tiers' => count($rows)], $actor);

            return $locked;
        });

        $this->cache->flush();

        return $updated;
    }

    /**
     * يولّد شرائح بعتبات تراكمية: الشريحة 1 = 0، ثم تزيد كل شريحة عن سابقتها بـ(step + (n-2)*ramp). أعداد صحيحة حتمية.
     */
    public function generateTiers(User $actor, AhjiyatPass $pass, int $count, int $step, int $ramp = 0, int $milestoneEvery = 5): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.manage');

        if ($count < 1 || $count > (int) config('ahjiyat_pass.limits.max_tiers', 100)) {
            throw new PassException('tier_count', 'عدد الشرائح خارج الحدود المسموحة.');
        }

        if ($step < 1 || $ramp < 0) {
            throw new PassException('tier_step', 'خطوة النقاط يجب أن تكون موجبة.');
        }

        $rows = [];
        $points = 0;

        for ($n = 1; $n <= $count; $n++) {
            if ($n > 1) {
                $points += $step + ($n - 2) * $ramp;
            }

            $rows[] = ['required_points' => $points, 'label' => null, 'is_milestone' => $milestoneEvery > 0 && $n % $milestoneEvery === 0];
        }

        return $this->replaceTiers($actor, $pass, $rows);
    }

    // ───────────────────────────── مكافآت الشرائح ─────────────────────────────

    public function setTierReward(User $actor, AhjiyatPassTier $tier, string $track, StoreItem|int $item, int $quantity = 1): AhjiyatPassTierReward
    {
        $this->authorizePass($actor, 'passes.manage-rewards');

        if (! array_key_exists($track, AhjiyatPassTierReward::TRACKS)) {
            throw new PassException('track', 'مسار المكافأة غير معروف.');
        }

        $storeItem = $item instanceof StoreItem ? $item : StoreItem::query()->find($item);

        if ($storeItem === null) {
            throw new PassException('item_missing', 'عنصر المتجر غير موجود.');
        }

        if (($problem = PassRules::itemProblem($storeItem)) !== null) {
            throw new PassException($problem[0], $problem[1]);
        }

        if ($quantity < 1 || $quantity > (int) config('ahjiyat_pass.limits.max_reward_quantity', 5)) {
            throw new PassException('quantity', 'كمية المكافأة خارج الحدود المسموحة.');
        }

        $reward = DB::transaction(function () use ($actor, $tier, $track, $storeItem, $quantity) {
            $pass = $this->lockedDraft($tier->ahjiyat_pass_id);
            $lockedTier = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->whereKey($tier->getKey())->first();

            if ($lockedTier === null) {
                throw new PassException('tier_missing', 'الشريحة غير موجودة في هذا المسار.');
            }

            $reward = AhjiyatPassTierReward::query()->where('ahjiyat_pass_tier_id', $lockedTier->getKey())->where('track', $track)->first() ?? new AhjiyatPassTierReward;
            $reward->forceFill([
                'ahjiyat_pass_tier_id' => $lockedTier->getKey(),
                'ahjiyat_pass_id' => $pass->getKey(),
                'track' => $track,
                'store_item_id' => $storeItem->getKey(),
                'quantity' => $quantity,
            ])->save();

            $this->audit->log('ahjiyat_pass_reward_set', $pass, ['public_id' => $pass->public_id, 'tier' => $lockedTier->tier_number, 'track' => $track, 'store_item_id' => $storeItem->getKey(), 'quantity' => $quantity], $actor);

            return $reward;
        });

        $this->cache->flush();

        return $reward;
    }

    public function clearTierReward(User $actor, AhjiyatPassTier $tier, string $track): void
    {
        $this->authorizePass($actor, 'passes.manage-rewards');

        DB::transaction(function () use ($actor, $tier, $track): void {
            $pass = $this->lockedDraft($tier->ahjiyat_pass_id);
            $reward = AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $pass->getKey())->where('ahjiyat_pass_tier_id', $tier->getKey())->where('track', $track)->first();

            if ($reward !== null) {
                $reward->delete();
                $this->audit->log('ahjiyat_pass_reward_cleared', $pass, ['public_id' => $pass->public_id, 'tier' => $tier->tier_number, 'track' => $track], $actor);
            }
        });

        $this->cache->flush();
    }

    // ───────────────────────────── المهام ─────────────────────────────

    /** @param array{points?: int, max_completions?: int, week_number?: ?int, is_featured?: bool, sort_order?: int} $options */
    public function attachMission(User $actor, AhjiyatPass $pass, QuestDefinition|int $quest, string $difficulty, array $options = []): AhjiyatPassMission
    {
        $this->authorizePass($actor, 'passes.manage-missions');

        $definition = $quest instanceof QuestDefinition ? $quest : QuestDefinition::query()->find($quest);

        if ($definition === null) {
            throw new PassException('quest_missing', 'تعريف المهمة غير موجود.');
        }

        if (! array_key_exists($difficulty, AhjiyatPassMission::DIFFICULTIES)) {
            throw new PassException('difficulty', 'مستوى الصعوبة غير معروف.');
        }

        $mission = DB::transaction(function () use ($actor, $pass, $definition, $difficulty, $options) {
            $locked = $this->lockedDraft($pass);

            if (($problem = PassRules::questProblem($definition, $locked)) !== null) {
                throw new PassException($problem[0], $problem[1]);
            }

            $points = (int) ($options['points'] ?? config("ahjiyat_pass.default_points.{$difficulty}", 50));
            $max = (int) ($options['max_completions'] ?? 1);
            $week = $options['week_number'] ?? null;

            $this->assertMissionNumbers($points, $max, $week);

            $count = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $locked->getKey())->count();

            if ($count >= (int) config('ahjiyat_pass.limits.max_missions', 40)) {
                throw new PassException('too_many_missions', 'بلغت الحدّ الأقصى لعدد المهام.');
            }

            try {
                $mission = new AhjiyatPassMission;
                $mission->forceFill([
                    'ahjiyat_pass_id' => $locked->getKey(),
                    'quest_definition_id' => $definition->getKey(),
                    'difficulty' => $difficulty,
                    'pass_points' => $points,
                    'max_completions' => $max,
                    'week_number' => $week,
                    'is_featured' => (bool) ($options['is_featured'] ?? false),
                    'sort_order' => (int) ($options['sort_order'] ?? $count),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new PassException('duplicate_mission', 'هذه المهمة مرتبطة بالمسار مسبقًا.');
            }

            $this->audit->log('ahjiyat_pass_mission_attached', $locked, ['public_id' => $locked->public_id, 'quest_definition_id' => $definition->getKey(), 'difficulty' => $difficulty, 'points' => $points, 'max_completions' => $max], $actor);

            return $mission;
        });

        $this->cache->flush();

        return $mission;
    }

    /** @param array{difficulty?: string, points?: int, max_completions?: int, week_number?: ?int, is_featured?: bool, sort_order?: int} $data */
    public function updateMission(User $actor, AhjiyatPassMission $mission, array $data): AhjiyatPassMission
    {
        $this->authorizePass($actor, 'passes.manage-missions');

        $updated = DB::transaction(function () use ($actor, $mission, $data) {
            $pass = $this->lockedDraft($mission->ahjiyat_pass_id);
            $row = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->whereKey($mission->getKey())->firstOrFail();

            $difficulty = $data['difficulty'] ?? $row->difficulty;

            if (! array_key_exists($difficulty, AhjiyatPassMission::DIFFICULTIES)) {
                throw new PassException('difficulty', 'مستوى الصعوبة غير معروف.');
            }

            $points = (int) ($data['points'] ?? $row->pass_points);
            $max = (int) ($data['max_completions'] ?? $row->max_completions);
            $week = array_key_exists('week_number', $data) ? $data['week_number'] : $row->week_number;
            $this->assertMissionNumbers($points, $max, $week);

            $row->forceFill([
                'difficulty' => $difficulty,
                'pass_points' => $points,
                'max_completions' => $max,
                'week_number' => $week,
                'is_featured' => (bool) ($data['is_featured'] ?? $row->is_featured),
                'sort_order' => (int) ($data['sort_order'] ?? $row->sort_order),
            ])->save();

            $this->audit->log('ahjiyat_pass_mission_updated', $pass, ['public_id' => $pass->public_id, 'mission_id' => $row->getKey(), 'difficulty' => $difficulty, 'points' => $points, 'max_completions' => $max], $actor);

            return $row;
        });

        $this->cache->flush();

        return $updated;
    }

    public function detachMission(User $actor, AhjiyatPassMission $mission): void
    {
        $this->authorizePass($actor, 'passes.manage-missions');

        DB::transaction(function () use ($actor, $mission): void {
            $pass = $this->lockedDraft($mission->ahjiyat_pass_id);
            $row = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->whereKey($mission->getKey())->first();

            if ($row !== null) {
                $questId = $row->quest_definition_id;
                $row->delete();
                $this->audit->log('ahjiyat_pass_mission_detached', $pass, ['public_id' => $pass->public_id, 'quest_definition_id' => $questId], $actor);
            }
        });

        $this->cache->flush();
    }

    /**
     * «إنشاء مهمة للمسار»: يُنشئ **QuestDefinition عاديًا** عبر النموذج وحُرّاسه (لا مُقيِّم ولا محرك جديد) بلا مكافأة XP أو عملة أصلية — المكافأة الوحيدة نقاط المسار.
     *
     * @param  array{name: string, description?: ?string, period_type: string, condition_type: string, target_value: int, scope_type?: ?string, scope_id?: ?int}  $data
     */
    public function createQuestForPass(User $actor, AhjiyatPass $pass, array $data, string $difficulty, array $options = []): AhjiyatPassMission
    {
        $this->authorizePass($actor, 'passes.manage-missions');

        if ($pass->locked_at !== null) {
            throw new PassException('locked', 'مهام مسار منشور مقفلة.');
        }

        $quest = DB::transaction(function () use ($actor, $pass, $data) {
            $quest = new QuestDefinition;
            $quest->fill([
                'internal_key' => 'pass-'.$pass->internal_key.'-'.substr((string) \Illuminate\Support\Str::ulid(), -8),
                'name' => $this->text($data['name'] ?? null, 120, 'اسم المهمة', true),
                'description' => $this->text($data['description'] ?? null, 500, 'وصف المهمة'),
                'period_type' => $data['period_type'] ?? null,
                'condition_type' => $data['condition_type'] ?? null,
                'target_value' => (int) ($data['target_value'] ?? 0),
                'scope_type' => $data['scope_type'] ?? null,
                'scope_id' => $data['scope_id'] ?? null,
                'xp_reward' => 0,
                'is_active' => true,
                'sort_order' => 0,
                'starts_at' => $pass->starts_at,
                'ends_at' => $pass->ends_at,
                'created_by' => $actor->getKey(),
            ]);

            try {
                $quest->save();
            } catch (\App\Exceptions\StoreItemInvariantViolation $e) {
                throw new PassException('quest_invalid', $e->getMessage());
            }

            return $quest;
        });

        $this->audit->log('ahjiyat_pass_quest_created', $quest, ['quest_definition_id' => $quest->getKey(), 'pass' => $pass->public_id], $actor);

        return $this->attachMission($actor, $pass, $quest, $difficulty, $options);
    }

    // ───────────────────────────── مساعدات ─────────────────────────────

    /** يقفل صف المسار ويشترط أنه مسودة (غير منشور). @throws PassException */
    protected function lockedDraft(AhjiyatPass|int $pass): AhjiyatPass
    {
        $id = $pass instanceof AhjiyatPass ? $pass->getKey() : $pass;
        $locked = AhjiyatPass::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($locked->locked_at !== null || $locked->status !== AhjiyatPass::STATUS_DRAFT) {
            throw new PassException('locked', 'المسار منشور؛ الشرائح والمكافآت والمهام مقفلة.');
        }

        return $locked;
    }

    protected function assertMissionNumbers(int $points, int $max, mixed $week): void
    {
        $limits = (array) config('ahjiyat_pass.limits');

        if ($points < 1 || $points > (int) ($limits['max_points_per_mission'] ?? 1000)) {
            throw new PassException('points', 'نقاط المهمة خارج الحدود المسموحة.');
        }

        if ($max < 1 || $max > (int) ($limits['max_completions_cap'] ?? 14)) {
            throw new PassException('max_completions', 'سقف إكمالات المهمة خارج الحدود المسموحة.');
        }

        if ($week !== null && ((int) $week < 1 || (int) $week > 20)) {
            throw new PassException('week', 'رقم الأسبوع غير صالح.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tiers
     * @return list<array{required_points: int, label: ?string, is_milestone: bool}>
     */
    protected function validatedTiers(array $tiers): array
    {
        if ($tiers === [] || count($tiers) > (int) config('ahjiyat_pass.limits.max_tiers', 100)) {
            throw new PassException('tier_count', 'عدد الشرائح خارج الحدود المسموحة.');
        }

        $out = [];
        $previous = null;
        $max = (int) config('ahjiyat_pass.limits.max_required_points', 1_000_000);

        foreach (array_values($tiers) as $row) {
            $raw = $row['required_points'] ?? null;

            if (! (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,9}$/', $raw) === 1))) {
                throw new PassException('tier_points', 'عتبة الشريحة يجب أن تكون عددًا صحيحًا.');
            }

            $points = (int) $raw;

            if ($points < 0 || $points > $max) {
                throw new PassException('tier_points', 'عتبة الشريحة خارج الحدود المسموحة.');
            }

            if ($previous !== null && $points <= $previous) {
                throw new PassException('tier_order', 'عتبات الشرائح يجب أن تكون تصاعدية صارمة (لا تساوٍ ولا تناقص).');
            }

            $previous = $points;
            $out[] = ['required_points' => $points, 'label' => $this->text($row['label'] ?? null, 60, 'اسم الشريحة'), 'is_milestone' => (bool) ($row['is_milestone'] ?? false)];
        }

        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function commerceAttributes(array $data, bool $creating, ?AhjiyatPass $existing = null): array
    {
        $out = [];
        $has = fn (string $k): bool => array_key_exists($k, $data);

        if ($has('premium_enabled') || $creating) {
            $out['premium_enabled'] = (bool) ($data['premium_enabled'] ?? true);
        }

        $enabled = $out['premium_enabled'] ?? (bool) ($data['premium_enabled'] ?? $existing?->premium_enabled ?? true);

        // تعديل جزئي (اسم/مظهر فقط) لا يمسّ التسعير ولا يعيد التحقق منه؛ يُعاد التحقق فقط عند لمس السعر/العملة/التفعيل.
        if ($has('currency') || $has('premium_price') || $has('premium_price_minor') || $has('premium_enabled') || $creating) {
            $currency = $has('currency')
                ? ($data['currency'] !== null && $data['currency'] !== '' ? strtoupper(trim((string) $data['currency'])) : null)
                : $existing?->currency;
            $minor = ($has('premium_price_minor') || $has('premium_price')) ? null : $existing?->premium_price_minor;

            if ($has('premium_price_minor') && $data['premium_price_minor'] !== null && $data['premium_price_minor'] !== '') {
                $minor = (int) $data['premium_price_minor'];

                if ((string) $minor !== (string) $data['premium_price_minor']) {
                    throw new PassException('price', 'السعر يجب أن يكون عددًا صحيحًا بالوحدة الصغرى.');
                }
            } elseif ($has('premium_price') && $data['premium_price'] !== null && $data['premium_price'] !== '') {
                try {
                    $minor = SponsorshipMoney::parse((string) $data['premium_price'], (string) $currency);
                } catch (SponsorshipException $e) {
                    throw new PassException('price', $e->getMessage());
                }
            }

            if ($enabled && ($minor !== null || $currency !== null)) {
                if (($problem = PassRules::priceProblem($minor, $currency)) !== null) {
                    throw new PassException($problem[0], $problem[1]);
                }
            }

            $out['premium_price_minor'] = $minor;
            $out['currency'] = $currency;
        }

        return $out + $this->salesWindow($data, null);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function salesWindow(array $data, ?AhjiyatPass $existing): array
    {
        $out = [];

        foreach (['premium_sales_start_at', 'premium_sales_end_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field] === null || $data[$field] === '' ? null : $this->date($data[$field], 'نافذة البيع');
            }
        }

        if ($out === []) {
            return [];
        }

        $start = array_key_exists('premium_sales_start_at', $out) ? $out['premium_sales_start_at'] : $existing?->premium_sales_start_at;
        $end = array_key_exists('premium_sales_end_at', $out) ? $out['premium_sales_end_at'] : $existing?->premium_sales_end_at;

        if ($start !== null && $end !== null && $end->lte($start)) {
            throw new PassException('sales_window', 'نافذة بيع المميز غير صالحة.');
        }

        if ($existing !== null) {
            if ($end !== null && $end->gt($existing->ends_at)) {
                throw new PassException('sales_window', 'لا يستمر بيع المميز بعد انتهاء المسار.');
            }

            if ($start !== null && $start->lt($existing->starts_at)) {
                throw new PassException('sales_window', 'لا يبدأ بيع المميز قبل بدء المسار.');
            }
        }

        return $out;
    }

    protected function date(mixed $value, string $label): Carbon
    {
        if ($value === null || $value === '') {
            throw new PassException('date', "{$label} مطلوب.");
        }

        try {
            return $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new PassException('date', "{$label} غير صالح.");
        }
    }

    protected function key(mixed $value): string
    {
        $key = strtolower(trim((string) $value));

        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,62}[a-z0-9]$/', $key) !== 1) {
            throw new PassException('internal_key', 'المفتاح الداخلي: حروف إنجليزية صغيرة وأرقام وشرطات (3–64).');
        }

        return $key;
    }

    protected function grace(mixed $value): int
    {
        $days = $value === null || $value === '' ? (int) config('ahjiyat_pass.lifecycle.claim_grace_days', 5) : (int) $value;

        if ($days < 0 || $days > (int) config('ahjiyat_pass.lifecycle.claim_grace_max_days', 14)) {
            throw new PassException('grace', 'مهلة الاستلام خارج الحدود المسموحة.');
        }

        return $days;
    }

    protected function text(mixed $value, int $max, string $label, bool $required = false): ?string
    {
        $text = trim(strip_tags((string) ($value ?? '')));

        if ($text === '') {
            if ($required) {
                throw new PassException('required', "{$label} مطلوب.");
            }

            return null;
        }

        if (mb_strlen($text) > $max) {
            throw new PassException('too_long', "{$label} أطول من المسموح ({$max}).");
        }

        return $text;
    }

    /** @return array{accent: string, background: string, tagline: ?string}|null */
    protected function theme(mixed $theme): ?array
    {
        if ($theme === null) {
            return null;
        }

        $theme = (array) $theme;
        $accent = (string) ($theme['accent'] ?? 'amber');
        $background = (string) ($theme['background'] ?? 'aurora');

        if (! in_array($accent, (array) config('ahjiyat_pass.theme.accents', []), true) || ! in_array($background, (array) config('ahjiyat_pass.theme.backgrounds', []), true)) {
            throw new PassException('theme', 'قيم المظهر خارج القائمة المسموحة.');
        }

        return ['accent' => $accent, 'background' => $background, 'tagline' => $this->text($theme['tagline'] ?? null, (int) config('ahjiyat_pass.theme.tagline_max', 90), 'الشعار')];
    }
}
