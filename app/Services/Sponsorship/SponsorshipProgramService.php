<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * E30 - برامج الرعاية. كل كتابة هنا بصلاحية وتدقيق، بلا تعيين جماعي:
 *  - إنشاء/تعديل البرنامج وأدواره: sponsorships.manage. خطة تعزيز المكافآت: sponsorships.reward_plan (صلاحية منفصلة) وتُقفل بقفل خطة المنافسة.
 *  - الهدف من قائمة بيضاء رسمية (SponsorshipTargets) ويتحقق من وجوده؛ لا يتغير الهدف بعد أن صارت للبرنامج مساهمات أو خرج من المسودة.
 *  - راعٍ رئيسي واحد لكل برنامج (قيد قاعدة بيانات) ويشترط مساهمة مؤكَّدة منه بهذا البرنامج؛ الراعي الرئيسي ≠ أعلى داعم (تعيين إداري صريح فقط).
 */
class SponsorshipProgramService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected SponsorshipTargets $targets,
        protected SupportTotals $totals,
        protected SponsorshipNotifier $notifier,
    ) {}

    /**
     * @param  array{title: string, description?: ?string, target_type: string, target_id?: ?int, starts_at?: mixed, ends_at?: mixed, goal_amount?: string|int|null, show_public_amounts?: bool, show_goal_progress?: bool}  $data
     *
     * @throws SponsorshipException
     */
    public function create(User $actor, array $data): SponsorshipProgram
    {
        $this->authorize($actor);
        $clean = $this->clean($data);
        $this->targets->validate($data['target_type'] ?? '', isset($data['target_id']) ? (int) $data['target_id'] : null);

        $program = DB::transaction(function () use ($actor, $data, $clean) {
            $program = new SponsorshipProgram;
            $program->forceFill($clean + [
                'target_type' => $data['target_type'],
                'target_id' => $data['target_type'] === SponsorshipProgram::TARGET_PLATFORM ? null : (int) $data['target_id'],
                'slug' => $this->uniqueSlug((string) $data['title']),
                'status' => SponsorshipProgram::STATUS_DRAFT,
                'accounting_currency' => SponsorshipMoney::accountingCurrency(),
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_program_created', $program, ['slug' => $program->slug, 'target' => $program->target_type, 'target_id' => $program->target_id], $actor);

            return $program;
        });

        SponsorshipCache::bust();

        return $program;
    }

    /** @throws SponsorshipException */
    public function update(User $actor, SponsorshipProgram $program, array $data): SponsorshipProgram
    {
        $this->authorize($actor);

        if (in_array($program->status, [SponsorshipProgram::STATUS_CANCELLED], true)) {
            throw new SponsorshipException('البرنامج الملغى لا يُعدَّل.');
        }

        $clean = $this->clean($data + ['title' => $program->title], partial: true);

        if (isset($data['target_type']) && ($data['target_type'] !== $program->target_type || (int) ($data['target_id'] ?? 0) !== (int) $program->target_id)) {
            if (! $program->isDraft() || $program->contributions()->exists()) {
                throw new SponsorshipException('لا يتغير هدف البرنامج بعد خروجه من المسودة أو بعد وجود مساهمات عليه.');
            }

            $this->targets->validate($data['target_type'], isset($data['target_id']) ? (int) $data['target_id'] : null);
            $clean['target_type'] = $data['target_type'];
            $clean['target_id'] = $data['target_type'] === SponsorshipProgram::TARGET_PLATFORM ? null : (int) $data['target_id'];
        }

        return DB::transaction(function () use ($actor, $program, $clean) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);
            $locked->forceFill($clean);
            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                $this->audit->log('sponsorship_program_updated', $locked, ['changed' => $changed], $actor);
                SponsorshipCache::bust();
            }

            return $locked;
        });
    }

    /**
     * خطة تعزيز المكافآت: step بعملة المحاسبة (نصًّا) + نقاط أساسية لكل خطوة + سقف. بصلاحية reward_plan وقبل القفل فقط.
     *
     * @param  array{enabled: bool, step?: string|int|null, per_step_bps?: int, max_bps?: int}  $plan
     *
     * @throws SponsorshipException
     */
    public function updateRewardPlan(User $actor, SponsorshipProgram $program, array $plan): SponsorshipProgram
    {
        abort_unless($actor->can('sponsorships.reward_plan'), 403);

        if ($program->reward_plan_locked_at !== null) {
            throw new SponsorshipException('خطة المكافآت مقفلة (منافسة اقتربت من البدء) ولا تُعدَّل.');
        }

        if ($program->status === SponsorshipProgram::STATUS_CANCELLED) {
            throw new SponsorshipException('البرنامج الملغى لا يُعدَّل.');
        }

        $enabled = (bool) ($plan['enabled'] ?? false);
        $hard = (int) config('sponsorship.reward.hard_max_bps', 5000);
        $step = null;
        $per = max(0, (int) ($plan['per_step_bps'] ?? 0));
        $max = max(0, (int) ($plan['max_bps'] ?? 0));

        if ($enabled) {
            $step = SponsorshipMoney::parse($plan['step'] ?? '', SponsorshipMoney::accountingCurrency());

            if ($per <= 0 || $max <= 0) {
                throw new SponsorshipException('حدّد نسبة التعزيز لكل خطوة وسقف التعزيز (نقاط أساسية، 100 = 1%).');
            }

            if ($max < $per) {
                throw new SponsorshipException('سقف التعزيز يجب ألا يقلّ عن نسبة الخطوة الواحدة.');
            }

            if ($max > $hard) {
                throw new SponsorshipException('سقف التعزيز يتجاوز الحدّ الصلب للمنصة ('.$hard.' نقطة أساسية).');
            }
        } elseif (filled($plan['step'] ?? null)) {
            $step = SponsorshipMoney::parse($plan['step'], SponsorshipMoney::accountingCurrency());
        }

        return DB::transaction(function () use ($actor, $program, $enabled, $step, $per, $max) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if ($locked->reward_plan_locked_at !== null) {
                throw new SponsorshipException('خطة المكافآت مقفلة ولا تُعدَّل.');
            }

            $before = $locked->only(['reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps']);
            $locked->forceFill(['reward_boost_enabled' => $enabled, 'funding_step_minor' => $step, 'boost_per_step_bps' => $enabled ? $per : 0, 'max_boost_bps' => $enabled ? $max : 0])->save();

            $this->audit->log('sponsorship_reward_plan_updated', $locked, ['before' => $before, 'after' => $locked->only(['reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps'])], $actor);
            SponsorshipCache::bust();

            return $locked;
        });
    }

    /** @throws SponsorshipException */
    public function activate(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $activated = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! $locked->isDraft()) {
                throw new SponsorshipException('يُفعَّل البرنامج من حالة المسودة فقط.');
            }

            $this->targets->validate((string) $locked->target_type, $locked->target_id);

            if ($locked->ends_at !== null && $locked->starts_at !== null && $locked->ends_at->lessThanOrEqualTo($locked->starts_at)) {
                throw new SponsorshipException('تاريخ الانتهاء يجب أن يلي تاريخ البدء.');
            }

            if ($locked->ends_at !== null && $locked->ends_at->isPast()) {
                throw new SponsorshipException('تاريخ الانتهاء مضى؛ عدّل المدة قبل التفعيل.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_ACTIVE, 'activated_at' => now()])->save();
            $this->audit->log('sponsorship_program_activated', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();
        $this->announceStart($activated);

        return $activated;
    }

    /** @throws SponsorshipException */
    public function close(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $closed = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if ($locked->status !== SponsorshipProgram::STATUS_ACTIVE) {
                throw new SponsorshipException('يُغلق البرنامج النشط فقط.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_CLOSED, 'closed_at' => now()])->save();
            $this->audit->log('sponsorship_program_closed', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $closed;
    }

    /** @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $cancelled = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! in_array($locked->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                throw new SponsorshipException('لا يُلغى إلا مسودة أو برنامج نشط.');
            }

            if ($locked->contributions()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->exists()) {
                throw new SponsorshipException('عليه مساهمات مؤكَّدة؛ أغلقه بدل الإلغاء أو استردّ مساهماته أولًا.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_CANCELLED, 'closed_at' => now()])->save();
            $this->audit->log('sponsorship_program_cancelled', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $cancelled;
    }

    /**
     * يعيّن دور داعم بالبرنامج. sponsor/main_sponsor يشترطان مساهمة مؤكَّدة من الداعم بهذا البرنامج وملفًا غير موقوف. الراعي الرئيسي واحد: تعيين جديد يُنزل السابق إلى sponsor.
     *
     * @throws SponsorshipException
     */
    public function assignRole(User $actor, SponsorshipProgram $program, SupporterProfile $supporter, string $role): SponsorshipProgramRole
    {
        $this->authorize($actor);

        if (! in_array($role, SponsorshipProgramRole::ROLES, true)) {
            throw new SponsorshipException('دور غير صالح.');
        }

        if (in_array($program->status, [SponsorshipProgram::STATUS_CANCELLED], true)) {
            throw new SponsorshipException('البرنامج ملغى.');
        }

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف.');
        }

        $result = DB::transaction(function () use ($actor, $program, $supporter, $role) {
            SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! $this->totals->hasConfirmed($supporter->id, $program->id)) {
                throw new SponsorshipException('لا يُعيَّن راعيًا إلا من له مساهمة مؤكَّدة بهذا البرنامج.');
            }

            if ($role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR) {
                $previous = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('role', SponsorshipProgramRole::ROLE_MAIN_SPONSOR)
                    ->where('supporter_profile_id', '!=', $supporter->id)->first();

                if ($previous !== null) {
                    $previous->forceFill(['role' => SponsorshipProgramRole::ROLE_SPONSOR, 'main_marker' => null])->save();
                    $this->audit->log('main_sponsor_removed', $program, ['supporter_profile_id' => $previous->supporter_profile_id, 'reason' => 'replaced'], $actor);
                }
            }

            $row = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->first() ?? new SponsorshipProgramRole;
            $wasMain = $row->exists && $row->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR;
            $row->forceFill([
                'sponsorship_program_id' => $program->id,
                'supporter_profile_id' => $supporter->id,
                'role' => $role,
                'main_marker' => $role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? $program->id : null,
                'assigned_by' => $actor->id,
            ])->save();

            if ($role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR) {
                $this->audit->log('main_sponsor_assigned', $program, ['supporter' => $supporter->public_id], $actor);
            } elseif ($wasMain) {
                $this->audit->log('main_sponsor_removed', $program, ['supporter' => $supporter->public_id, 'reason' => 'demoted'], $actor);
            } else {
                $this->audit->log('sponsorship_role_assigned', $program, ['supporter' => $supporter->public_id, 'role' => $role], $actor);
            }

            return $row;
        });

        SponsorshipCache::bust();

        return $result;
    }

    /** @throws SponsorshipException */
    public function removeRole(User $actor, SponsorshipProgram $program, SupporterProfile $supporter): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $program, $supporter) {
            $row = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            $this->audit->log($row->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? 'main_sponsor_removed' : 'sponsorship_role_removed', $program, ['supporter' => $supporter->public_id, 'reason' => 'manual'], $actor);
            $row->delete();
        });

        SponsorshipCache::bust();
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('sponsorships.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function clean(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'min:3', 'max:160', new NoMarkup],
            'description' => ['nullable', 'string', 'max:1000', new NoMarkup],
            'show_public_amounts' => ['sometimes', 'boolean'],
            'show_goal_progress' => ['sometimes', 'boolean'],
        ], [], ['title' => 'العنوان', 'description' => 'الوصف']);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        $out = [];

        if (isset($data['title'])) {
            $out['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        foreach (['show_public_amounts', 'show_goal_progress'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $out[$flag] = (bool) $data[$flag];
            }
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = filled($data[$field]) ? Carbon::parse($data[$field]) : null;
            }
        }

        if (($out['starts_at'] ?? null) !== null && ($out['ends_at'] ?? null) !== null && $out['ends_at']->lessThanOrEqualTo($out['starts_at'])) {
            throw new SponsorshipException('تاريخ الانتهاء يجب أن يلي تاريخ البدء.');
        }

        if (array_key_exists('goal_amount', $data)) {
            $out['goal_amount_minor'] = filled($data['goal_amount']) ? SponsorshipMoney::parse($data['goal_amount'], SponsorshipMoney::accountingCurrency()) : null;
        }

        return $out;
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug(Str::ascii($title)), 70, '') ?: 'program';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SponsorshipProgram::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /** إشعار «بدأ البرنامج» لداعميه المرتبطين بحسابات (ذوو مساهمة/دور) على دفعات صغيرة؛ فشله لا يمسّ التفعيل. */
    protected function announceStart(SponsorshipProgram $program): void
    {
        try {
            SupporterProfile::query()->whereNotNull('user_id')->whereIn('id', fn ($q) => $q->select('supporter_profile_id')->from('sponsorship_contributions')
                ->where('sponsorship_program_id', $program->id)->whereIn('status', [SponsorshipContribution::STATUS_PENDING, SponsorshipContribution::STATUS_CONFIRMED]))
                ->with('user')->chunkById(100, function ($supporters) use ($program) {
                    foreach ($supporters as $supporter) {
                        $this->notifier->programStarted($supporter, $program);
                    }
                });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
