<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupporterPackage;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E44 - باقات بطاقات الداعم لموسم (فضي/ذهبي/ماسي). **الخادم سلطة السعر والعملة والنقاط والنمط**؛ لا يصل من العميل إلا هوية الباقة.
 *
 * - الأسعار الافتراضية من config/seasonal_recognition.php (25 / 100 / 250 دولارًا؛ 2500/10000/25000 وحدة صغرى) وتُنسخ كصفوف قابلة للتعديل الإداري (صلاحية manage + تدقيق).
 * - الباقة مربوطة ببرنامج رعاية E30 **هدفه هذا الموسم** (target_type=season ∧ target_id) وغير مغلق/ملغى؛ الدفع الحقيقي يمرّ كاملًا عبر E32 (لا محرك دفع ثانٍ).
 * - السعر والعملة لا يتغيران بعد وجود أي بطاقة عليها (التاريخ يحمل لقطته على أي حال)؛ يُعطَّل وتُنشأ باقة جديدة بدل ذلك. التغيير يسري على الطلبات المستقبلية فقط.
 * - لا حذف أبدًا؛ التعطيل يخفي الباقة عن الطلبات الجديدة ويُبقي بطاقاتها وتاريخها.
 */
class SeasonSupporterPackageService
{
    public const PERMISSION = 'seasonal-recognition.manage';

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected OperationalAuditService $audit,
    ) {}

    /** @return Collection<int, SeasonSupporterPackage> */
    public function packagesFor(Season $season, bool $activeOnly = false): Collection
    {
        return SeasonSupporterPackage::query()
            ->where('season_id', $season->id)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * ينشئ الباقات الافتراضية الناقصة (فضي/ذهبي/ماسي) على برنامج رعاية للموسم. متكرر الأمان: الموجود لا يُعدَّل.
     *
     * @return int عدد الباقات المُنشأة
     *
     * @throws SeasonalRecognitionException
     */
    public function seedDefaults(Season $season, SponsorshipProgram $program, User $actor): int
    {
        $this->authorize($actor);
        $this->assertProgramFits($season, $program);
        $this->settings->ensure($season);

        $created = DB::transaction(function () use ($season, $program, $actor): int {
            $count = 0;

            foreach ((array) config('seasonal_recognition.supporters.card_tiers', []) as $tier => $def) {
                if (! in_array($tier, SeasonSupporterPackage::TIERS, true)) {
                    continue;
                }

                if (SeasonSupporterPackage::query()->where('season_id', $season->id)->where('tier_key', $tier)->exists()) {
                    continue;
                }

                $package = new SeasonSupporterPackage;
                $package->forceFill([
                    'season_id' => $season->id,
                    'sponsorship_program_id' => $program->id,
                    'tier_key' => $tier,
                    'name' => (string) $def['name'],
                    'price_minor' => (int) $def['price_minor'],
                    'currency' => strtoupper((string) config('seasonal_recognition.supporters.card_currency', 'USD')),
                    'support_points' => (int) $def['support_points'],
                    'name_style_key' => (string) $def['name_style_key'],
                    'social_mention_eligible' => (bool) $def['social_mention_eligible'],
                    'is_active' => true,
                    'sort_order' => (int) ($def['sort_order'] ?? 0),
                ])->save();

                $this->audit->log('seasonal_supporter_package_created', $package, ['season' => $season->slug, 'tier' => $tier, 'price_minor' => $package->price_minor, 'currency' => $package->currency], $actor);
                $count++;
            }

            return $count;
        });

        if ($created > 0) {
            SeasonalRecognitionCache::bump((int) $season->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
        }

        return $created;
    }

    /**
     * تعديل باقة. الحقول: name, support_points, is_active, social_mention_eligible، وprice_minor/currency (فقط قبل وجود أي بطاقة عليها).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws SeasonalRecognitionException
     */
    public function update(SeasonSupporterPackage $package, array $input, User $actor): SeasonSupporterPackage
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($package, $input, $actor): SeasonSupporterPackage {
            /** @var SeasonSupporterPackage $locked */
            $locked = SeasonSupporterPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $changes = [];

            if (array_key_exists('name', $input)) {
                $name = trim(strip_tags((string) $input['name']));

                if ($name === '' || mb_strlen($name) > 60) {
                    throw new SeasonalRecognitionException('invalid_name', 'اسم الباقة مطلوب (حتى 60 حرفًا).');
                }

                $changes['name'] = $name;
            }

            if (array_key_exists('support_points', $input)) {
                $max = (int) config('seasonal_recognition.supporters.max_support_points_per_purchase', 1000000);

                if (! is_numeric($input['support_points']) || (int) $input['support_points'] != $input['support_points'] || (int) $input['support_points'] < 0 || (int) $input['support_points'] > $max) {
                    throw new SeasonalRecognitionException('invalid_points', "نقاط الدعم عدد صحيح بين 0 و{$max}.");
                }

                $changes['support_points'] = (int) $input['support_points'];
            }

            foreach (['is_active', 'social_mention_eligible'] as $flag) {
                if (array_key_exists($flag, $input)) {
                    $changes[$flag] = (bool) $input[$flag];
                }
            }

            foreach (['price_minor', 'currency'] as $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                $new = $field === 'price_minor' ? (int) $input[$field] : strtoupper(trim((string) $input[$field]));

                if ($new == $locked->{$field}) {
                    continue;
                }

                if ($locked->cards()->exists()) {
                    throw new SeasonalRecognitionException('price_locked', 'لا يتغير سعر باقة عليها بطاقات؛ عطّلها وأنشئ باقة جديدة.');
                }

                if ($field === 'price_minor' && ($new < 1 || $new > 100000000)) {
                    throw new SeasonalRecognitionException('invalid_price', 'السعر عدد صحيح موجب بالوحدة الصغرى.');
                }

                if ($field === 'currency' && preg_match('/^[A-Z]{3}$/', (string) $new) !== 1) {
                    throw new SeasonalRecognitionException('invalid_currency', 'العملة رمز من 3 أحرف كبيرة.');
                }

                $changes[$field] = $new;
            }

            if ($changes === []) {
                return $locked;
            }

            $before = collect($locked->getAttributes())->only(array_keys($changes))->all();
            $locked->forceFill($changes)->save();
            $this->audit->log('seasonal_supporter_package_updated', $locked, ['season_id' => $locked->season_id, 'tier' => $locked->tier_key, 'changed' => array_keys($changes), 'before' => $before], $actor);

            SeasonalRecognitionCache::bump((int) $locked->season_id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);

            return $locked;
        });
    }

    /** @throws SeasonalRecognitionException */
    protected function assertProgramFits(Season $season, SponsorshipProgram $program): void
    {
        if ($program->target_type !== SponsorshipProgram::TARGET_SEASON || (int) $program->target_id !== (int) $season->id) {
            throw new SeasonalRecognitionException('program_target_mismatch', 'برنامج الرعاية يجب أن يكون هدفه هذا الموسم.');
        }

        if (in_array($program->status, [SponsorshipProgram::STATUS_CLOSED, SponsorshipProgram::STATUS_CANCELLED], true)) {
            throw new SeasonalRecognitionException('program_closed', 'برنامج الرعاية مغلق أو ملغى.');
        }
    }

    /** @throws SeasonalRecognitionException */
    protected function authorize(User $actor): void
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة باقات الداعمين.');
        }
    }
}
