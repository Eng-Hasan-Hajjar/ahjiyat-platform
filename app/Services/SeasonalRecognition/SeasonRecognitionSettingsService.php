<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterVoteBudget;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\SeasonLifecycleService;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E44 - إعدادات التقدير الموسمي: القراءة (المفاتيح، نافذة التصويت، الحالة، الجاهزية) والكتابة (إعدادات الدوري وخطة الجوائز) بقواعد صارمة.
 *
 * الفعّال = المفتاح العام (config) ∧ مفتاح الموسم. إيقافه يمنع الإجراءات الجديدة فقط ولا يمسح قائمة ولا أصواتًا ولا ترتيبًا.
 * قاعدة الأسبوع: إغلاق التصويت ≤ نهاية الموسم الفعلية − 7 أيام (نهاية الموسم، وإلا نهاية حملته الأساسية؛ لا نهاية ⇒ الجاهزية تفشل ولا يُفتح تصويت).
 * تُطبَّق القاعدة وقت الحفظ **وأيضًا وقت التصويت** (تحريك نهاية الموسم لاحقًا لا يفتح نافذة تتجاوز الأسبوع): الإغلاق الفعلي = min(closes_at, النهاية − 7 أيام).
 */
class SeasonRecognitionSettingsService
{
    public const STATE_DISABLED = 'disabled';

    public const STATE_NOT_CONFIGURED = 'not_configured';

    public const STATE_UPCOMING = 'upcoming';

    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    public const STATE_FINALIZED = 'finalized';

    public const PERMISSION = 'seasonal-recognition.manage';

    public function __construct(
        protected SeasonLifecycleService $lifecycle,
        protected OperationalAuditService $audit,
    ) {}

    // ───────────────────────────── المفاتيح ─────────────────────────────

    public function writersGloballyEnabled(): bool
    {
        return (bool) config('seasonal_recognition.writers.enabled', false);
    }

    public function supportersGloballyEnabled(): bool
    {
        return (bool) config('seasonal_recognition.supporters.enabled', false);
    }

    public function settingFor(Season|int $season): ?SeasonRecognitionSetting
    {
        return SeasonRecognitionSetting::query()->where('season_id', $season instanceof Season ? $season->id : $season)->first();
    }

    /** صف الإعدادات (يُنشأ كسولًا بافتراضات الإعداد العام). فشل التنافس على الإنشاء يقرأ الصف الفائز. */
    public function ensure(Season $season): SeasonRecognitionSetting
    {
        $existing = $this->settingFor($season);

        if ($existing !== null) {
            return $existing;
        }

        DB::table('season_recognition_settings')->insertOrIgnore([
            'season_id' => $season->id,
            'writers_enabled' => false,
            'writers_capacity' => min(SeasonRecognitionSetting::HARD_MAX_CAPACITY, max(1, (int) config('seasonal_recognition.writers.default_capacity', 20))),
            'writers_stars_per_voter' => min(SeasonRecognitionSetting::HARD_MAX_STARS, max(1, (int) config('seasonal_recognition.writers.stars_per_voter', 10))),
            'writers_eliminated_count' => max(0, (int) config('seasonal_recognition.writers.eliminated_count', 5)),
            'supporters_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->settingFor($season) ?? throw new SeasonalRecognitionException('settings_unavailable', 'تعذّر تجهيز إعدادات الموسم الآن.');
    }

    public function writersActive(Season $season, ?SeasonRecognitionSetting $setting = null): bool
    {
        $setting ??= $this->settingFor($season);

        return $this->writersGloballyEnabled() && $setting !== null && $setting->writers_enabled;
    }

    public function supportersActive(Season $season, ?SeasonRecognitionSetting $setting = null): bool
    {
        $setting ??= $this->settingFor($season);

        return $this->supportersGloballyEnabled() && $setting !== null && $setting->supporters_enabled;
    }

    // ───────────────────────────── النافذة والحالة ─────────────────────────────

    public function seasonEnd(Season $season): ?CarbonInterface
    {
        return $this->lifecycle->windowEnd($season);
    }

    public function seasonStart(Season $season): ?CarbonInterface
    {
        return $this->lifecycle->windowStart($season);
    }

    /** أقصى موعد مسموح لإغلاق التصويت (النهاية − الأسبوع)؛ null إن لم تكن للموسم نهاية صالحة. */
    public function latestAllowedClose(Season $season): ?CarbonInterface
    {
        $end = $this->seasonEnd($season);

        return $end?->copy()->subDays(max(1, (int) config('seasonal_recognition.writers.min_close_before_end_days', 7)));
    }

    /** الإغلاق الفعلي = الأقدم بين المضبوط وأقصى موعد مسموح؛ null إن لم يمكن حسابه (لا نهاية أو لا إغلاق). */
    public function effectiveClose(Season $season, ?SeasonRecognitionSetting $setting = null): ?CarbonInterface
    {
        $setting ??= $this->settingFor($season);
        $latest = $this->latestAllowedClose($season);

        if ($setting === null || $setting->writers_voting_closes_at === null || $latest === null) {
            return null;
        }

        return $setting->writers_voting_closes_at->lessThanOrEqualTo($latest) ? $setting->writers_voting_closes_at : $latest;
    }

    public function votingState(Season $season, ?SeasonRecognitionSetting $setting = null, ?CarbonInterface $now = null): string
    {
        $setting ??= $this->settingFor($season);
        $now ??= now();

        if ($setting === null || ! $setting->writers_enabled) {
            return $setting?->writers_finalized_at !== null ? self::STATE_FINALIZED : self::STATE_DISABLED;
        }

        if ($setting->writers_finalized_at !== null) {
            return self::STATE_FINALIZED;
        }

        $close = $this->effectiveClose($season, $setting);

        if ($setting->writers_voting_opens_at === null || $close === null) {
            return self::STATE_NOT_CONFIGURED;
        }

        return match (true) {
            $now->lessThan($setting->writers_voting_opens_at) => self::STATE_UPCOMING,
            $now->greaterThanOrEqualTo($close) => self::STATE_CLOSED,
            default => self::STATE_OPEN,
        };
    }

    /** التصويت مفتوح فعلًا الآن (المفتاح العام ∧ مفتاح الموسم ∧ نافذة مفتوحة ∧ لم يُنهَ). */
    public function isVotingOpen(Season $season, ?SeasonRecognitionSetting $setting = null): bool
    {
        $setting ??= $this->settingFor($season);

        return $this->writersGloballyEnabled() && $this->votingState($season, $setting) === self::STATE_OPEN;
    }

    // ───────────────────────────── الجاهزية ─────────────────────────────

    /**
     * فحوص الجاهزية للاستعراض الإداري (قراءة فقط): required تمنع اعتبار الدوري جاهزًا، advisory تنبيهات.
     *
     * @return array{ok: bool, checks: list<array{key: string, required: bool, ok: bool, label: string}>}
     */
    public function readiness(Season $season): array
    {
        $setting = $this->settingFor($season);
        $end = $this->seasonEnd($season);
        $latest = $this->latestAllowedClose($season);
        $active = $setting !== null ? SeasonWriterEntry::query()->where('season_id', $season->id)->active()->count() : 0;
        $total = $setting !== null ? SeasonWriterEntry::query()->where('season_id', $season->id)->count() : 0;
        $withoutSeries = $setting !== null
            ? SeasonWriterEntry::query()->where('season_id', $season->id)->active()->whereDoesntHave('seriesLinks')->count()
            : 0;

        $checks = [
            ['key' => 'global_flag', 'required' => true, 'ok' => $this->writersGloballyEnabled(), 'label' => 'مفتاح الدوري العام مُفعَّل (SEASON_WRITERS_ENABLED)'],
            ['key' => 'season_flag', 'required' => true, 'ok' => $setting?->writers_enabled === true, 'label' => 'الدوري مُفعَّل لهذا الموسم'],
            ['key' => 'season_end', 'required' => true, 'ok' => $end !== null, 'label' => 'للموسم نهاية صالحة (نهاية الموسم أو حملته الأساسية)'],
            ['key' => 'window_set', 'required' => true, 'ok' => $setting?->writers_voting_opens_at !== null && $setting?->writers_voting_closes_at !== null, 'label' => 'نافذة التصويت مضبوطة (فتح وإغلاق)'],
            ['key' => 'window_order', 'required' => true, 'ok' => $setting?->writers_voting_opens_at !== null && $setting?->writers_voting_closes_at !== null && $setting->writers_voting_closes_at->greaterThan($setting->writers_voting_opens_at), 'label' => 'الإغلاق بعد الفتح'],
            ['key' => 'week_rule', 'required' => true, 'ok' => $latest !== null && $setting?->writers_voting_closes_at !== null && $setting->writers_voting_closes_at->lessThanOrEqualTo($latest), 'label' => 'الإغلاق قبل نهاية الموسم بسبعة أيام على الأقل'],
            ['key' => 'roster_present', 'required' => true, 'ok' => $active >= 1, 'label' => 'في القائمة كاتب نشط واحد على الأقل'],
            ['key' => 'roster_capacity', 'required' => true, 'ok' => $setting !== null && $total <= (int) $setting->writers_capacity && $total <= SeasonRecognitionSetting::HARD_MAX_CAPACITY, 'label' => 'القائمة ضمن السعة (والحد المطلق 100)'],
            ['key' => 'series_linked', 'required' => false, 'ok' => $active >= 1 && $withoutSeries === 0, 'label' => 'لكل كاتب نشط سلسلة مربوطة بالموسم (وإلا لا يستطيع اللاعبون التصويت له)'],
            ['key' => 'reward_plan', 'required' => false, 'ok' => ! empty($setting?->writers_reward_plan), 'label' => 'خطة جوائز المراكز الثلاثة مضبوطة'],
        ];

        $ok = collect($checks)->where('required', true)->every(fn (array $c) => $c['ok']);

        return ['ok' => $ok, 'checks' => $checks];
    }

    // ───────────────────────────── الكتابة ─────────────────────────────

    /**
     * يحفظ إعدادات دوري الكتّاب (صلاحية manage). الحقول الممكنة: enabled, capacity, opens_at, closes_at, stars_per_voter, eliminated_count, previous_season_id, reward_plan.
     * أي قاعدة تُخالَف ⇒ SeasonalRecognitionException بلا كتابة جزئية (معاملة واحدة تقفل صف الإعدادات).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws SeasonalRecognitionException
     */
    public function saveWriterSettings(Season $season, array $input, User $actor): SeasonRecognitionSetting
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة دوري الكتّاب.');
        }

        $this->ensure($season);

        return DB::transaction(function () use ($season, $input, $actor) {
            /** @var SeasonRecognitionSetting $setting */
            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->firstOrFail();

            if ($setting->writers_finalized_at !== null) {
                throw new SeasonalRecognitionException('finalized', 'انتهى ترتيب الدوري؛ إعداداته مجمَّدة.');
            }

            $now = now();
            $votingStarted = $setting->writers_voting_opens_at !== null && $setting->writers_voting_opens_at->lessThanOrEqualTo($now);
            $votingClosed = $setting->writers_voting_closes_at !== null && $setting->writers_voting_closes_at->lessThanOrEqualTo($now);
            $changes = [];

            if (array_key_exists('capacity', $input)) {
                $capacity = $this->intInRange($input['capacity'], 1, min(SeasonRecognitionSetting::HARD_MAX_CAPACITY, (int) config('seasonal_recognition.writers.max_capacity', 100)), 'capacity', 'السعة بين 1 و100');
                $rosterCount = SeasonWriterEntry::query()->where('season_id', $season->id)->count();

                if ($capacity < $rosterCount) {
                    throw new SeasonalRecognitionException('capacity_below_roster', "السعة لا تقل عن عدد الكتّاب الحاليين ({$rosterCount}).");
                }

                $changes['writers_capacity'] = $capacity;
            }

            if (array_key_exists('stars_per_voter', $input)) {
                $stars = $this->intInRange($input['stars_per_voter'], 1, SeasonRecognitionSetting::HARD_MAX_STARS, 'stars_per_voter', 'نجوم الناخب بين 1 و10');

                if ($stars !== (int) $setting->writers_stars_per_voter && (SeasonWriterVoteBudget::query()->where('season_id', $season->id)->exists() || $votingStarted)) {
                    throw new SeasonalRecognitionException('budget_locked', 'لا تُغيَّر ميزانية النجوم بعد بدء التصويت.');
                }

                $changes['writers_stars_per_voter'] = $stars;
            }

            if (array_key_exists('eliminated_count', $input)) {
                $changes['writers_eliminated_count'] = $this->intInRange($input['eliminated_count'], 0, (int) config('seasonal_recognition.writers.max_eliminated_count', 20), 'eliminated_count', 'عدد المستبعدين خارج الحدود');
            }

            foreach (['opens_at' => 'writers_voting_opens_at', 'closes_at' => 'writers_voting_closes_at'] as $key => $column) {
                if (! array_key_exists($key, $input)) {
                    continue;
                }

                $value = $input[$key] === null || $input[$key] === '' ? null : \Illuminate\Support\Carbon::parse($input[$key]);

                if ($key === 'opens_at' && $votingStarted && ! $this->same($value, $setting->writers_voting_opens_at)) {
                    throw new SeasonalRecognitionException('window_locked', 'بدأ التصويت؛ موعد الفتح لا يتغير.');
                }

                if ($key === 'closes_at' && $votingClosed && ! $this->same($value, $setting->writers_voting_closes_at)) {
                    throw new SeasonalRecognitionException('window_locked', 'أُغلق التصويت؛ موعد الإغلاق لا يتغير ولا يُعاد فتحه.');
                }

                $changes[$column] = $value;
            }

            if (array_key_exists('previous_season_id', $input)) {
                $previous = $input['previous_season_id'] === null || $input['previous_season_id'] === '' ? null : (int) $input['previous_season_id'];

                if ($previous !== null && ($previous === $season->id || ! Season::query()->whereKey($previous)->exists())) {
                    throw new SeasonalRecognitionException('invalid_previous', 'الموسم السابق غير صالح.');
                }

                $changes['previous_season_id'] = $previous;
            }

            if (array_key_exists('reward_plan', $input)) {
                $changes['writers_reward_plan'] = $this->normalizeRewardPlan((array) $input['reward_plan']);
            }

            if (array_key_exists('enabled', $input)) {
                $changes['writers_enabled'] = (bool) $input['enabled'];
            }

            $opens = array_key_exists('writers_voting_opens_at', $changes) ? $changes['writers_voting_opens_at'] : $setting->writers_voting_opens_at;
            $closes = array_key_exists('writers_voting_closes_at', $changes) ? $changes['writers_voting_closes_at'] : $setting->writers_voting_closes_at;

            if ($opens !== null && $closes !== null && $closes->lessThanOrEqualTo($opens)) {
                throw new SeasonalRecognitionException('window_order', 'إغلاق التصويت يجب أن يكون بعد فتحه.');
            }

            if ($closes !== null) {
                $latest = $this->latestAllowedClose($season);

                if ($latest === null) {
                    throw new SeasonalRecognitionException('no_season_end', 'لا يمكن تحديد إغلاق التصويت: للموسم أو لحملته الأساسية لا توجد نهاية صالحة.');
                }

                if ($closes->greaterThan($latest)) {
                    throw new SeasonalRecognitionException('week_rule', 'يجب أن يُغلق التصويت قبل نهاية الموسم بسبعة أيام على الأقل (آخر موعد: '.$latest->format('Y-m-d H:i').').');
                }
            }

            if (($changes['writers_enabled'] ?? false) === true && $this->latestAllowedClose($season) === null) {
                throw new SeasonalRecognitionException('no_season_end', 'لا يُفعَّل الدوري لموسم بلا نهاية صالحة (هو شرط جاهزية).');
            }

            $before = Arr::only($setting->getAttributes(), array_keys($changes));
            $setting->forceFill($changes)->save();

            $this->audit->log('seasonal_writers_settings_saved', $setting, [
                'season' => $season->slug,
                'changed' => array_keys($changes),
                'before' => array_map(fn ($v) => $v instanceof CarbonInterface ? $v->toIso8601String() : $v, $before),
            ], $actor);

            return $setting->refresh();
        });
    }

    /**
     * يفعّل/يعطّل تقدير الداعمين لموسم (صلاحية manage). التعطيل يوقف الإجراءات الجديدة فقط.
     *
     * @throws SeasonalRecognitionException
     */
    public function setSupportersEnabled(Season $season, bool $enabled, User $actor): SeasonRecognitionSetting
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة تقدير الداعمين.');
        }

        $this->ensure($season);

        return DB::transaction(function () use ($season, $enabled, $actor) {
            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->firstOrFail();

            if ($setting->supporters_finalized_at !== null && $enabled) {
                throw new SeasonalRecognitionException('finalized', 'انتهى ترتيب الداعمين لهذا الموسم؛ لا يُعاد تفعيله.');
            }

            if ((bool) $setting->supporters_enabled !== $enabled) {
                $setting->forceFill(['supporters_enabled' => $enabled])->save();
                $this->audit->log('seasonal_supporters_toggled', $setting, ['season' => $season->slug, 'enabled' => $enabled], $actor);
            }

            return $setting->refresh();
        });
    }

    /**
     * يتحقق من خطة الجوائز ويعيدها بشكلها المعياري (قائمة بيضاء مغلقة). المالي: التزام يُسجَّل فقط - لا دفع آلي ولا تحويل عملات.
     *
     * @param  array<int, mixed>  $plan
     * @return list<array<string, mixed>>
     *
     * @throws SeasonalRecognitionException
     */
    public function normalizeRewardPlan(array $plan): array
    {
        $maxItems = 12;
        $items = array_values($plan);

        if (count($items) > $maxItems) {
            throw new SeasonalRecognitionException('reward_plan_too_long', "خطة الجوائز لا تتجاوز {$maxItems} بندًا.");
        }

        $normalized = [];
        $placements = [SeasonWriterEntry::OUTCOME_FIRST, SeasonWriterEntry::OUTCOME_SECOND, SeasonWriterEntry::OUTCOME_THIRD];
        $kinds = (array) config('seasonal_recognition.rewards.kinds', ['cosmetic', 'monetary']);
        $currencies = (array) config('seasonal_recognition.rewards.currencies', ['USD']);

        foreach ($items as $index => $item) {
            $item = (array) $item;

            $validator = Validator::make($item, [
                'placement' => ['required', 'string', 'in:'.implode(',', $placements)],
                'kind' => ['required', 'string', 'in:'.implode(',', $kinds)],
                'label' => ['required', 'string', 'max:120'],
            ]);

            if ($validator->fails()) {
                throw new SeasonalRecognitionException('reward_plan_invalid', 'بند الجائزة رقم '.($index + 1).' غير صالح.');
            }

            $row = [
                'placement' => (string) $item['placement'],
                'kind' => (string) $item['kind'],
                'label' => trim(strip_tags((string) $item['label'])),
                'store_item_id' => null,
                'amount_minor' => null,
                'currency' => null,
            ];

            if ($row['label'] === '') {
                throw new SeasonalRecognitionException('reward_plan_invalid', 'اسم الجائزة مطلوب في البند رقم '.($index + 1).'.');
            }

            if ($row['kind'] === 'cosmetic') {
                $storeItem = StoreItem::query()->find((int) ($item['store_item_id'] ?? 0));

                if ($storeItem === null || $storeItem->item_type !== StoreItem::TYPE_COSMETIC || $storeItem->fulfillment_type !== StoreItem::FULFILLMENT_INVENTORY) {
                    throw new SeasonalRecognitionException('reward_item_invalid', 'الجائزة التجميلية تحتاج عنصر متجر تجميليًا قابلًا للتسليم كمخزون (البند '.($index + 1).').');
                }

                $row['store_item_id'] = $storeItem->id;
            } else {
                $minor = $item['amount_minor'] ?? null;
                $currency = strtoupper((string) ($item['currency'] ?? ''));

                if (! is_numeric($minor) || (int) $minor != $minor
                    || (int) $minor < (int) config('seasonal_recognition.rewards.monetary_min_minor', 100)
                    || (int) $minor > (int) config('seasonal_recognition.rewards.monetary_max_minor', 100000000)
                    || ! in_array($currency, $currencies, true)) {
                    throw new SeasonalRecognitionException('reward_amount_invalid', 'الجائزة المالية تحتاج مبلغًا صحيحًا بالوحدة الصغرى وعملة مسموحة (البند '.($index + 1).').');
                }

                $row['amount_minor'] = (int) $minor;
                $row['currency'] = $currency;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    protected function intInRange(mixed $value, int $min, int $max, string $field, string $message): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
            throw new SeasonalRecognitionException('invalid_'.$field, $message.'.');
        }

        return (int) $value;
    }

    protected function same(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && $a->equalTo($b));
    }
}
