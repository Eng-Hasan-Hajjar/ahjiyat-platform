<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E33 (B) - حملات الدعوة.
 *
 *  - القراءة العامة (حل ?c=slug أو الحملة الافتراضية) من كاش صفوف خفيفة بلا استعلام لكل طلب؛ كل إجراء إداري يفرّغ الكاش. الحيّة تُقيَّم بالذاكرة وقت الاستعمال فلا تتأخر حدود النافذة الزمنية.
 *  - slug غير صالح/منتهٍ/غير موجود **لا يكسر الإحالة**: يسقط إلى الحملة الافتراضية الحيّة أو إلى لا حملة (إعدادات config).
 *  - كل كتابة إدارية: صلاحية referrals.manage + حد معدّل referral-campaign-action لكل مشرف + تدقيق + تفريغ الكاش + معاملة. حملة افتراضية واحدة بـUNIQUE(default_slot).
 *  - تعديل المكافآت لا يغيّر إحالة سبق تأهيلها (لقطة user_referrals).
 */
class ReferralCampaignService
{
    public const CACHE_KEY = 'referrals:campaigns:rows';

    public function __construct(protected OperationalAuditService $audit) {}

    /** @return list<array{id: int, slug: string, status: string, default: bool, starts_at: ?string, ends_at: ?string, public: bool, season_id: ?int}> */
    protected function rows(): array
    {
        return Cache::remember(self::CACHE_KEY, max(30, (int) config('referrals.analytics.campaign_cache_seconds')), function () {
            return ReferralCampaign::query()
                ->where('status', ReferralCampaign::STATUS_ACTIVE)
                ->get(['id', 'slug', 'status', 'default_slot', 'starts_at', 'ends_at', 'is_public', 'season_id'])
                ->map(fn (ReferralCampaign $c) => [
                    'id' => (int) $c->id, 'slug' => (string) $c->slug, 'status' => (string) $c->status, 'default' => $c->isDefault(),
                    'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(), 'public' => (bool) $c->is_public,
                    'season_id' => $c->season_id !== null ? (int) $c->season_id : null,
                ])->all();
        });
    }

    protected function rowIsLive(array $row): bool
    {
        $now = now();

        return $row['status'] === ReferralCampaign::STATUS_ACTIVE
            && ($row['starts_at'] === null || \Illuminate\Support\Carbon::parse($row['starts_at'])->lte($now))
            && ($row['ends_at'] === null || \Illuminate\Support\Carbon::parse($row['ends_at'])->gt($now));
    }

    /** يحلّ slug الحملة الاختياري إلى حملة حيّة، وإلا الافتراضية الحيّة، وإلا null. لا يرمي أبدًا. */
    public function resolve(?string $slug): ?ReferralCampaign
    {
        $live = array_values(array_filter($this->rows(), fn (array $r) => $this->rowIsLive($r)));
        $slug = is_string($slug) ? strtolower(trim($slug)) : null;

        $pick = null;

        if ($slug !== null && $slug !== '') {
            $pick = collect($live)->firstWhere('slug', $slug);
        }

        $pick ??= collect($live)->firstWhere('default', true);

        return $pick === null ? null : ReferralCampaign::query()->find($pick['id']);
    }

    /** slug حملة حيّة فقط (للتخزين في النية)؛ غير الصالح يصير null بصمت. */
    public function validSlug(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '' || strlen($slug) > 64) {
            return null;
        }

        $slug = strtolower(trim($slug));
        $hit = collect($this->rows())->first(fn (array $r) => $r['slug'] === $slug && $this->rowIsLive($r));

        return $hit === null ? null : $slug;
    }

    /** slug حملة حيّة علنية مرتبطة بموسم (لروابط مشاركة الموسم)، وإلا null. */
    public function slugForSeason(int $seasonId): ?string
    {
        $hit = collect($this->rows())->first(fn (array $r) => $r['season_id'] === $seasonId && $r['public'] && $this->rowIsLive($r));

        return $hit['slug'] ?? null;
    }

    /** حملات حيّة وعلنية للعرض في صفحة /referrals (بلا استعلام إضافي لكل طلب). */
    public function publicLive(): \Illuminate\Support\Collection
    {
        $ids = collect($this->rows())->filter(fn (array $r) => $r['public'] && $this->rowIsLive($r))->pluck('id')->all();

        return $ids === [] ? collect() : ReferralCampaign::query()->whereIn('id', $ids)->orderByDesc('default_slot')->orderBy('name')->get();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ---- إجراءات إدارية ----

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ReferralCampaign
    {
        $this->authorize($actor);
        $clean = $this->validated($data, null);

        $campaign = DB::transaction(function () use ($clean, $actor) {
            $c = new ReferralCampaign;
            $c->forceFill($clean + ['status' => ReferralCampaign::STATUS_DRAFT, 'created_by' => $actor->id])->save();

            return $c;
        });

        $this->audit->log('referral_campaign_created', $campaign, ['slug' => $campaign->slug], $actor);
        $this->forget();

        return $campaign;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, ReferralCampaign $campaign, array $data): ReferralCampaign
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $campaign, $data) {
            $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ReferralCampaign::STATUS_ENDED) {
                throw new ReferralException('campaign_ended', 'لا يمكن تعديل حملة منتهية.');
            }

            $clean = $this->validated($data + $locked->only(['name', 'slug']), $locked);
            $before = $locked->only(array_keys($clean));
            $locked->forceFill($clean)->save();

            $this->audit->log('referral_campaign_updated', $locked, ['before' => $before, 'after' => $locked->only(array_keys($clean))], $actor);
            $this->forget();

            return $locked;
        });
    }

    public function activate(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_DRAFT], ReferralCampaign::STATUS_ACTIVE, 'referral_campaign_activated');
    }

    public function pause(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_ACTIVE], ReferralCampaign::STATUS_PAUSED, 'referral_campaign_paused', clearDefault: true);
    }

    public function resume(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_PAUSED], ReferralCampaign::STATUS_ACTIVE, 'referral_campaign_resumed');
    }

    public function end(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_DRAFT, ReferralCampaign::STATUS_ACTIVE, ReferralCampaign::STATUS_PAUSED], ReferralCampaign::STATUS_ENDED, 'referral_campaign_ended', clearDefault: true);
    }

    /** يجعل حملة نشطة هي الافتراضية الوحيدة (يزيح السابقة بنفس المعاملة). */
    public function makeDefault(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        $this->authorize($actor);

        try {
            return DB::transaction(function () use ($actor, $campaign) {
                $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== ReferralCampaign::STATUS_ACTIVE) {
                    throw new ReferralException('campaign_not_active', 'الحملة الافتراضية يجب أن تكون نشطة.');
                }

                ReferralCampaign::query()->where('default_slot', 1)->where('id', '!=', $locked->id)->update(['default_slot' => null]);
                $locked->forceFill(['default_slot' => 1])->save();
                $this->audit->log('referral_campaign_default_set', $locked, ['slug' => $locked->slug], $actor);
                $this->forget();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReferralException('default_conflict', 'تعارض مع تغيير آخر للحملة الافتراضية. أعد المحاولة.');
        }
    }

    /** @param list<string> $from */
    protected function transition(User $actor, ReferralCampaign $campaign, array $from, string $to, string $auditAction, bool $clearDefault = false): ReferralCampaign
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $campaign, $from, $to, $auditAction, $clearDefault) {
            $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new ReferralException('invalid_transition', 'لا يمكن تنفيذ هذا الإجراء على حملة بحالتها الحالية.');
            }

            $attrs = ['status' => $to];

            if ($to === ReferralCampaign::STATUS_ENDED) {
                $attrs['ended_at'] = now();
            }

            if ($clearDefault) {
                $attrs['default_slot'] = null;
            }

            $locked->forceFill($attrs)->save();
            $this->audit->log($auditAction, $locked, ['slug' => $locked->slug], $actor);
            $this->forget();

            return $locked;
        });
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->can('referrals.manage')) {
            throw new ReferralException('forbidden', 'لا تملك صلاحية إدارة حملات الدعوة.');
        }

        $key = 'referral-campaign-action:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('referrals.limits.campaign_action_per_minute'))) {
            throw new ReferralException('rate_limited', 'عدد كبير من إجراءات الحملات خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, ?ReferralCampaign $existing): array
    {
        $rules = array_keys((array) config('referrals.qualification.rules'));
        $maxGems = (int) config('referrals.rewards.max_gems_per_side');
        $abs = (array) config('referrals.rewards.absolute_caps');

        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('referral_campaigns', 'slug')->ignore($existing?->id)],
            'is_public' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...(! empty($data['starts_at']) ? ['after:starts_at'] : [])],
            'season_id' => ['nullable', 'integer', Rule::exists(Season::class, 'id')],
            'qualification_rule' => ['sometimes', Rule::in($rules)],
            'min_account_age_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'rewards_enabled' => ['sometimes', 'boolean'],
            'referrer_reward_gems' => ['sometimes', 'integer', 'min:0', 'max:'.$maxGems],
            'referred_reward_gems' => ['sometimes', 'integer', 'min:0', 'max:'.$maxGems],
            'daily_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['daily']],
            'monthly_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['monthly']],
            'lifetime_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['lifetime']],
        ], [], ['name' => 'الاسم', 'slug' => 'المعرّف']);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $clean = $v->validated();
        $clean['slug'] = strtolower((string) $clean['slug']);

        return $clean;
    }
}
