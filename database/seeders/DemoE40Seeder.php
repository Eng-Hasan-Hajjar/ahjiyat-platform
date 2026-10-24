<?php

namespace Database\Seeders;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTier;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassClaimService;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPointService;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\AhjiyatPass\PassProgressService;
use App\Services\Engagement\QuestEvaluatorRegistry;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E40 - بيانات Demo لمسار الأحجيات.   php artisan db:seed --class=DemoE40Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق pass.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من أي seeder آخر.
 * - تبني كل شيء عبر خدمات E40 الرسمية (البنّاء/دورة الحياة/النقاط/الاستلام/المميز) — لا إدخال مباشر في الدفاتر أو المطالبات أو المشتريات.
 *   الاستثناء الوحيد والموثَّق: صفوف «إكمال المهمة» (UserQuestProgress) هي **بيانات مصدر** لا مالية، تُنشأ بوقت داخل نافذة المسار لأن لعب الماضي غير ممكن؛ والنقاط تُمنح منها عبر PassPointService::healFor.
 * - المشهد: مسار فعّال (24 شريحة، مكافآت حصرية تجميلية «ألقاب» بلا أسعار)، مهام سهلة/متوسطة/صعبة فوق QuestDefinition، لاعب مجاني بتقدّم، لاعب مميز (شراء يدوي محقَّق) استلم بعض مكافآته،
 *   لاعب بتقدّم متوسط، ولاعب جديد بلا تقدّم. لا جواهر ولا XP ولا دفع حقيقي ولا بيانات بطاقات.
 * - Idempotent: المفتاح الداخلي للمسار علامة العرض؛ إعادة التشغيل لا تضاعف مسارًا ولا نقاطًا ولا مشتريات.
 */
class DemoE40Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-pass-admin@pass.test';

    public const PASS_KEY = 'demo-e40-pass';

    public const FREE_EMAIL = 'demo-pass-free@pass.test';

    public const PREMIUM_EMAIL = 'demo-pass-premium@pass.test';

    public const MID_EMAIL = 'demo-pass-mid@pass.test';

    public const NEW_EMAIL = 'demo-pass-new@pass.test';

    /** ألقاب حصرية (تجميلية بلا صور، بلا أسعار في المتجر). */
    private const FREE_TITLES = ['مبتدئ الأحجيات', 'متأمّل', 'صاحب الفطنة', 'حلّال ماهر', 'سريع البديهة', 'عقل منظّم', 'صيّاد الألغاز', 'محلّل دقيق', 'خبير الأنماط', 'ملاحظ بارع', 'عابر التحدي', 'سيّد المسار'];

    private const PREMIUM_TITLES = ['ضيف الشرف', 'نجم المسار', 'حارس الألغاز', 'متقن', 'صاحب البصيرة', 'فارس التحدي', 'بوصلة الذكاء', 'عين الصقر', 'مهندس الحلول', 'حكيم الأحجيات', 'ملهم المسار', 'قمّة التحدي', 'أسطورة المسار'];

    public function run(): void
    {
        $this->refuseInProduction('DemoE40Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير مسار الأحجيات التجريبي', 'administrator');

        if (AhjiyatPass::query()->where('internal_key', self::PASS_KEY)->exists()) {
            $this->command?->info('DemoE40Seeder: بيانات العرض موجودة بالفعل (لا تكرار).');

            return;
        }

        $builder = app(PassBuilderService::class);
        $lifecycle = app(PassLifecycleService::class);

        $pass = $builder->createDraft($admin, [
            'internal_key' => self::PASS_KEY,
            'name' => 'مسار الأحجيات — عرض تجريبي',
            'subtitle' => 'اجمع نقاط المسار بحلّ الأحجيات واستلم ألقابًا حصرية',
            'description' => 'مسار تجريبي لأغراض العرض فقط. النقاط خاصة بالمسار ولا تؤثر في الترتيب أو الجواهر أو XP.',
            'starts_at' => now()->subDays(10)->startOfDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->subDays(10)->startOfDay()->addDays(30)->format('Y-m-d H:i:s'),
            'claim_grace_days' => 5,
            'premium_enabled' => true,
            'premium_price_minor' => 499,
            'currency' => 'USD',
            'theme' => ['accent' => 'amber', 'background' => 'aurora', 'tagline' => 'تقدّم بثبات، واستلم ما كسبته'],
        ]);

        $builder->generateTiers($admin, $pass, 24, 60, 6, 6);
        $tiers = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('tier_number')->get()->keyBy('tier_number');

        $this->rewards($builder, $admin, $tiers);
        $this->missions($builder, $admin, $pass);

        $lifecycle->schedule($admin, $pass);
        $pass = $pass->fresh();

        $this->players($admin, $pass);

        $this->command?->info('DemoE40Seeder: أُنشئ مسار فعّال بـ24 شريحة ولاعبون تجريبيون. الدخول: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    /** مجاني على الشرائح الزوجية (12)، ومميز على الفردية + الأخيرة (13). */
    protected function rewards(PassBuilderService $builder, User $admin, $tiers): void
    {
        $free = 0;
        $premium = 0;

        foreach ($tiers as $number => $tier) {
            if ($number % 2 === 0) {
                $builder->setTierReward($admin, $tier, 'free', $this->exclusiveTitle('F', $free, self::FREE_TITLES[$free]));
                $free++;
            }

            if ($number % 2 === 1 || $number === 24) {
                $builder->setTierReward($admin, $tier, 'premium', $this->exclusiveTitle('P', $premium, self::PREMIUM_TITLES[$premium]));
                $premium++;
            }
        }
    }

    protected function exclusiveTitle(string $kind, int $index, string $text): StoreItem
    {
        $sku = sprintf('DEMO-E40-%s%02d', $kind, $index + 1);

        $item = StoreItem::query()->where('sku', $sku)->first();

        if ($item !== null) {
            return $item;
        }

        $item = new StoreItem;
        $item->fill([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'name' => 'لقب المسار: '.$text,
            'short_description' => 'لقب حصري من مسار الأحجيات التجريبي.',
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_TITLE,
            'cosmetic_text' => $text,
            'is_active' => true,
            'is_featured' => false,
            'grant_quantity' => 1,
            'sort_order' => 0,
        ]);
        $item->save();

        return $item;
    }

    protected function missions(PassBuilderService $builder, User $admin, AhjiyatPass $pass): void
    {
        $category = PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? PuzzleCategory::query()->create(['name' => 'متنوعة', 'slug' => 'misc', 'sort_order' => 99, 'is_active' => true]);

        $solved = QuestEvaluatorRegistry::PUZZLES_SOLVED;
        $inCategory = QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY;

        $rows = [
            ['easy', 'حلّ أحجية اليوم', QuestDefinition::PERIOD_DAILY, $solved, 1, null, 50, 14],
            ['easy', 'حلّ أحجيتين اليوم', QuestDefinition::PERIOD_DAILY, $solved, 2, null, 50, 14],
            ['medium', 'حلّ 10 أحجيات هذا الأسبوع', QuestDefinition::PERIOD_WEEKLY, $solved, 10, null, 100, 4],
            ['medium', 'حلّ 5 أحجيات من التصنيف المختار', QuestDefinition::PERIOD_WEEKLY, $inCategory, 5, $category->id, 100, 4],
            ['hard', 'حلّ 25 أحجية هذا الأسبوع', QuestDefinition::PERIOD_WEEKLY, $solved, 25, null, 200, 4],
            ['hard', 'حلّ 15 أحجية من التصنيف المختار', QuestDefinition::PERIOD_WEEKLY, $inCategory, 15, $category->id, 200, 4],
        ];

        foreach ($rows as $i => [$difficulty, $name, $period, $condition, $target, $scope, $points, $max]) {
            $builder->createQuestForPass($admin, $pass, [
                'name' => $name,
                'description' => 'مهمة تجريبية لمسار الأحجيات.',
                'period_type' => $period,
                'condition_type' => $condition,
                'target_value' => $target,
                'scope_type' => $scope === null ? null : 'puzzle_category',
                'scope_id' => $scope,
            ], $difficulty, ['points' => $points, 'max_completions' => $max, 'sort_order' => $i, 'is_featured' => $i === 0]);
        }
    }

    protected function players(User $admin, AhjiyatPass $pass): void
    {
        $free = $this->user(self::FREE_EMAIL, 'لاعب مسار مجاني');
        $premium = $this->user(self::PREMIUM_EMAIL, 'لاعب مسار مميز');
        $mid = $this->user(self::MID_EMAIL, 'لاعب مسار متوسط');
        $this->user(self::NEW_EMAIL, 'لاعب مسار جديد');

        $progress = app(PassProgressService::class);

        foreach ([$free, $premium, $mid] as $user) {
            $this->tryDo(fn () => $progress->ensure($user, $pass));
        }

        $dailyOne = $this->questNamed($pass, 'حلّ أحجية اليوم');
        $weeklyTen = $this->questNamed($pass, 'حلّ 10 أحجيات هذا الأسبوع');
        $weeklyHard = $this->questNamed($pass, 'حلّ 25 أحجية هذا الأسبوع');

        // المجاني: 9 أيام من المهمة اليومية + أسبوعان من المتوسطة (≈ 650 نقطة).
        $this->completions($free, $dailyOne, 9, 'daily');
        $this->completions($free, $weeklyTen, 2, 'weekly');
        // المميز: تقدّم أعلى (≈ 1500 نقطة): يوميات + متوسطة + صعبة.
        $this->completions($premium, $dailyOne, 12, 'daily');
        $this->completions($premium, $weeklyTen, 3, 'weekly');
        $this->completions($premium, $weeklyHard, 3, 'weekly');
        // المتوسط: بضعة أيام فقط.
        $this->completions($mid, $dailyOne, 4, 'daily');

        $points = app(PassPointService::class);

        foreach ([$free, $premium, $mid] as $user) {
            $this->tryDo(fn () => $points->healFor($user, $pass));
        }

        // المميز: شراء يدوي كامل الدورة عبر الخدمة الرسمية (مرجع تحويل وهمي، لا بيانات بطاقة)، ثم استلام بعض المكافآت.
        $this->tryDo(function () use ($premium, $pass, $admin) {
            $service = app(PassPremiumService::class);
            $order = $service->request($premium, $pass, 'demo-e40-premium');
            $order = $service->submit($premium, $order, 'DEMO-TRANSFER-0001', 'دفعة تجريبية لأغراض العرض.');
            $service->verify($admin, $order, 'تحقق تجريبي.');
            app(PassClaimService::class)->collectAll($premium, $pass->fresh());
        });

        // المجاني: يستلم أول مكافأة متاحة فقط (والباقي ينتظر «استلام الكل» من الواجهة).
        $this->tryDo(function () use ($free, $pass) {
            $track = app(\App\Services\AhjiyatPass\PassDefinitionCache::class)->track($pass);
            $first = collect($track->allRewards())->first(fn (array $r) => $r['track'] === 'free');

            if ($first !== null) {
                app(PassClaimService::class)->claim($free, $pass, (int) $first['id']);
            }
        });
    }

    /** يُنشئ إكمالات يومية/أسبوعية سابقة داخل نافذة المسار (بيانات مصدر) — تُمنح نقاطها لاحقًا عبر healFor. */
    protected function completions(User $user, ?QuestDefinition $quest, int $count, string $period): void
    {
        if ($quest === null) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $day = now()->subDays($period === 'daily' ? $i + 1 : ($i * 7) + 1)->startOfDay();
            $key = $period === 'daily' ? 'daily:'.$day->format('Y-m-d') : 'weekly:'.$day->copy()->startOfWeek()->format('o-\WW');

            UserQuestProgress::query()->firstOrCreate(
                ['user_id' => $user->getKey(), 'quest_definition_id' => $quest->getKey(), 'period_key' => $key],
                [
                    'period_type' => $period,
                    'period_start' => $period === 'daily' ? $day : $day->copy()->startOfWeek(),
                    'period_end' => $period === 'daily' ? $day->copy()->endOfDay() : $day->copy()->endOfWeek(),
                    'current_value' => (int) $quest->target_value,
                    'target_value_snapshot' => (int) $quest->target_value,
                    'completed_at' => $day->copy()->addHours(12),
                ],
            );
        }
    }

    protected function questNamed(AhjiyatPass $pass, string $name): ?QuestDefinition
    {
        return QuestDefinition::query()
            ->whereIn('id', $pass->missions()->pluck('quest_definition_id'))
            ->where('name', $name)
            ->first();
    }

    protected function user(string $email, string $name, ?string $role = null): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE40Seeder: تخطّي خطوة - '.$e->getMessage());
        }
    }
}
