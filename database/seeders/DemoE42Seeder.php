<?php

namespace Database\Seeders;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTier;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\CompetitiveEvent;
use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\LiveOps\MissionPoolService;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use Database\Seeders\Concerns\RefusesProduction;
use Database\Seeders\Demo\DemoPuzzleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E42 - بيانات Demo لتطوّر الموسم.   php artisan db:seed --class=DemoE42Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق season.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من أي seeder آخر.
 * - تبني الموسم ومراحله وإعلاناته وروابطه عبر SeasonBuilderService الرسمي (صلاحية + قفل + تدقيق)، ومجموعة المهام عبر MissionPoolService. لا إشعارات مراحل/إعلانات ولا مكافآت ولا جواهر ولا XP (إشعارات دورة الحياة القائمة - إتاحة حملة/بدء موسم - تصل لحسابات العرض فقط).
 *   الاستثناء الوحيد والموثَّق: صف تقدّم سردي واحد للاعب التجريبي (UserCampaignProgress) هو **بيانات مصدر** تجعله «مشاركًا» في الحملة الأساسية ليعرض مركز الموسم حالة المشارك.
 * - المشهد: موسم منشور بنافذة صريحة (بدأ قبل 12 يومًا وينتهي بعد 18)، ثلاث مراحل (الحالية في الوسط)، حملة إضافية، منافستان (جارية وقادمة)، مجموعة مهام، مسار أحجيات مرتبط، وثلاثة إعلانات (أحدها مجدول للمستقبل).
 * - المسار: مسار مجاني صغير خاص بهذا العرض (demo-e42-pass) عبر PassBuilderService الرسمي، فلا يعتمد هذا الـseeder على أي بيانات عرض أخرى (ومنها عرض E40) ولا يشير إليها.
 * - المنافسات: حدثان منشوران بأحجيتين من كتالوج الأحجيات التجريبي القائم (DemoPuzzleCatalog، idempotent). لا مشاركات ولا نتائج ولا جوائز: التسجيل والنتائج تبقى سلطة المنافسة.
 * - Idempotent: معرّف الموسم (slug) علامة العرض؛ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE42Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-season-admin@season.test';

    public const PLAYER_EMAIL = 'demo-season-player@season.test';

    public const SEASON_SLUG = 'demo-e42-season';

    public function run(): void
    {
        $this->refuseInProduction('DemoE42Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير المواسم التجريبي', 'administrator');

        if (Season::query()->where('slug', self::SEASON_SLUG)->exists()) {
            $this->command?->info('DemoE42Seeder: بيانات العرض موجودة بالفعل (لا تكرار).');

            return;
        }

        $builder = app(SeasonBuilderService::class);

        [$campaign, $firstStep] = $this->campaign('demo-e42-primary', 'موسم الشتاء التجريبي', 'الحملة الأساسية للموسم التجريبي.');

        $start = now()->subDays(12)->startOfDay();

        $season = new Season;
        $season->fill([
            'campaign_id' => $campaign->getKey(),
            'code' => 'D42',
            'slug' => self::SEASON_SLUG,
            'theme_config' => $builder->normalizeTheme(['preset' => 'generic', 'hero_tagline' => 'موسم تجريبي بثلاث مراحل وتحديات متنوعة']),
            'is_published' => false,
            'is_featured' => true,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(30),
        ]);
        $season->save();

        $this->phases($builder, $admin, $season, $start);
        $this->content($builder, $admin, $season);
        $this->competitions($builder, $admin, $season);
        $this->pass($builder, $admin, $season);
        $this->announcements($builder, $admin, $season, $start);

        try {
            $builder->publish($admin, $season->fresh());
        } catch (SeasonException $e) {
            $this->command?->warn('DemoE42Seeder: لم يُنشر الموسم - '.$e->getMessage().' '.implode(' • ', $e->details));
        }

        $player = $this->user(self::PLAYER_EMAIL, 'لاعب الموسم التجريبي', null);
        UserCampaignProgress::query()->firstOrCreate(
            ['user_id' => $player->getKey(), 'campaign_step_id' => $firstStep->getKey()],
            ['started_at' => now()->subDays(3), 'completed_at' => now()->subDays(3)],
        );

        $this->command?->info('DemoE42Seeder: أُنشئ موسم تجريبي بثلاث مراحل. الصفحة: /seasons/'.self::SEASON_SLUG.' . الدخول: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    protected function phases(SeasonBuilderService $builder, User $admin, Season $season, \Carbon\CarbonInterface $start): void
    {
        $fmt = fn (\Carbon\CarbonInterface $d) => $d->format('Y-m-d H:i:s');

        $builder->addPhase($admin, $season, [
            'name' => 'الافتتاح', 'description' => 'انطلاقة الموسم: تعرّف على الحملة وابدأ رحلتك.',
            'starts_at' => $fmt($start), 'ends_at' => $fmt($start->copy()->addDays(6)), 'sort_order' => 0, 'theme' => ['accent' => 'sky'],
        ]);
        $builder->addPhase($admin, $season, [
            'name' => 'ذروة التحدي', 'description' => 'المرحلة الأكثر نشاطًا: حملة إضافية ومهام خاصة.',
            'starts_at' => $fmt($start->copy()->addDays(6)), 'ends_at' => $fmt($start->copy()->addDays(18)), 'sort_order' => 1, 'is_featured' => true, 'theme' => ['accent' => 'gold'],
        ]);
        $builder->addPhase($admin, $season, [
            'name' => 'الختام الكبير', 'description' => 'الأسابيع الأخيرة قبل إسدال الستار على الموسم.',
            'starts_at' => $fmt($start->copy()->addDays(18)), 'ends_at' => $fmt($start->copy()->addDays(30)), 'sort_order' => 2, 'theme' => ['accent' => 'rose'],
        ]);
    }

    protected function content(SeasonBuilderService $builder, User $admin, Season $season): void
    {
        [$extra] = $this->campaign('demo-e42-extra', 'تحدي الأسبوع الإضافي', 'حملة إضافية تظهر ضمن الموسم.');
        $peak = $season->phases()->get()->firstWhere('name', 'ذروة التحدي');

        $builder->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->getKey(), [
            'display_label' => 'تحدٍّ إضافي', 'season_phase_id' => $peak?->getKey(), 'is_featured' => true,
        ]);

        $pool = $this->pool($admin);
        $builder->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->getKey(), ['display_label' => 'مهام الموسم', 'sort_order' => 1]);
    }

    /** منافستان منشورتان (جارية وقادمة) بأحجيتين من كتالوج العرض القائم؛ الموسم يعرضهما فقط. */
    protected function competitions(SeasonBuilderService $builder, User $admin, Season $season): void
    {
        DemoPuzzleCatalog::ensure();

        $specs = [
            ['demo-e42-live', 'منافسة الموسم الجارية', 'C01', now()->subDay(), now()->addDays(5)],
            ['demo-e42-next', 'منافسة الموسم القادمة', 'C02', now()->addDays(7), now()->addDays(8)],
        ];

        foreach ($specs as $i => [$slug, $title, $puzzleKey, $startsAt, $endsAt]) {
            $event = CompetitiveEvent::query()->where('slug', $slug)->first();

            if ($event === null) {
                $event = new CompetitiveEvent([
                    'title' => $title, 'slug' => $slug, 'description' => 'منافسة تجريبية مرتبطة بالموسم التجريبي. التسجيل والنتائج من صفحة المنافسة نفسها.',
                    'puzzle_id' => DemoPuzzleCatalog::puzzle($puzzleKey)->getKey(), 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                ]);
                $event->forceFill(['status' => CompetitiveEvent::STATUS_PUBLISHED, 'published_at' => now()])->save();
            }

            $builder->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->getKey(), ['sort_order' => 10 + $i]); // بلا عنوان عرض: يظهر عنوان المنافسة نفسه
        }
    }

    /** مسار مجاني صغير خاص بالعرض بمهمة واحدة. الربط عرضٌ فقط: لا شراء ولا تقدّم ولا استلام. */
    protected function pass(SeasonBuilderService $builder, User $admin, Season $season): void
    {
        $pass = AhjiyatPass::query()->where('internal_key', 'demo-e42-pass')->first()
            ?? $this->makePass($admin);

        if ($pass !== null) {
            $builder->linkPass($admin, $season, $pass->getKey());
        }
    }

    protected function makePass(User $admin): ?AhjiyatPass
    {
        $passes = app(PassBuilderService::class);
        $start = now()->subDays(12)->startOfDay();

        $pass = $passes->createDraft($admin, [
            'internal_key' => 'demo-e42-pass', 'name' => 'مسار الموسم التجريبي', 'subtitle' => 'مسار مجاني صغير مرتبط بالموسم التجريبي',
            'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->copy()->addDays(30)->format('Y-m-d H:i:s'),
            'claim_grace_days' => 5, 'premium_enabled' => false,
        ]);
        $passes->generateTiers($admin, $pass, 5, 100, 0, 0);

        // مكافأة مجانية واحدة على الأقل شرط جاهزية المسار: لقب تجميلي (لا قيمة نقدية ولا ميزة لعب) على الشريحة الثانية.
        $tier = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->where('tier_number', 2)->firstOrFail();
        $passes->setTierReward($admin, $tier, 'free', $this->title());

        // مهمة عامة بلا مكافأة XP (مهام المجموعات لا تصلح لمسار الأحجيات): تُضاف للمسار فقط ولا تمنح شيئًا.
        $quest = QuestDefinition::query()->where('internal_key', 'demo_e42_pass_quest')->first()
            ?? QuestDefinition::query()->create([
                'internal_key' => 'demo_e42_pass_quest', 'name' => 'حلّ أحجية (مسار الموسم التجريبي)', 'description' => 'مهمة عامة تجريبية.', 'period_type' => QuestDefinition::PERIOD_DAILY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED, 'target_value' => 1, 'xp_reward' => 0, 'is_active' => true, 'sort_order' => 30,
            ]);

        $passes->attachMission($admin, $pass, $quest, 'easy', ['points' => 50, 'max_completions' => 14, 'sort_order' => 0]);

        try {
            app(PassLifecycleService::class)->schedule($admin, $pass->fresh());
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE42Seeder: بقي مسار العرض مسودة (لن يظهر للجمهور) - '.$e->getMessage());
        }

        return $pass->fresh();
    }

    protected function announcements(SeasonBuilderService $builder, User $admin, Season $season, \Carbon\CarbonInterface $start): void
    {
        $fmt = fn (\Carbon\CarbonInterface $d) => $d->format('Y-m-d H:i:s');
        $peak = $season->phases()->get()->firstWhere('name', 'ذروة التحدي');

        $builder->createAnnouncement($admin, $season, [
            'title' => 'أهلًا بكم في الموسم التجريبي', 'body' => 'بدأ الموسم. تصفّحوا المراحل والمحتوى المرتبط من هذه الصفحة.',
            'published_at' => $fmt($start), 'is_featured' => true,
        ]);
        $builder->createAnnouncement($admin, $season, [
            'title' => 'بدأت مرحلة «ذروة التحدي»', 'body' => 'انضمّت حملة إضافية ومجموعة مهام إلى الموسم.',
            'published_at' => $fmt($start->copy()->addDays(6)), 'season_phase_id' => $peak?->getKey(),
        ]);
        $builder->createAnnouncement($admin, $season, [
            'title' => 'إعلان مجدول: الختام الكبير', 'body' => 'يظهر هذا الإعلان تلقائيًا في موعده المجدول.',
            'published_at' => $fmt(now()->addDays(2)),
        ]);
    }

    /** @return array{0: Campaign, 1: CampaignStep} */
    protected function campaign(string $slug, string $title, string $description): array
    {
        $campaign = Campaign::query()->where('slug', $slug)->first();

        if ($campaign === null) {
            $campaign = Campaign::query()->create(['slug' => $slug, 'title' => $title, 'description' => $description, 'is_active' => true, 'starts_at' => null, 'ends_at' => null]);
            $stage = CampaignStage::query()->create(['campaign_id' => $campaign->getKey(), 'title' => 'المرحلة الأولى', 'sort_order' => 1]);
            $gate = CampaignGate::query()->create(['campaign_stage_id' => $stage->getKey(), 'title' => 'البوابة الأولى', 'sort_order' => 1]);
            CampaignStep::query()->create([
                'campaign_gate_id' => $gate->getKey(), 'kind' => CampaignStep::KIND_NARRATIVE, 'sort_order' => 1, 'title' => 'البداية',
                'content' => ['body' => 'نص سردي تجريبي لعرض الحملة.'], 'reward_mode' => CampaignStep::REWARD_MODE_INHERIT,
            ]);
        }

        $step = CampaignStep::query()->whereIn('campaign_gate_id', CampaignGate::query()->whereIn('campaign_stage_id', CampaignStage::query()->where('campaign_id', $campaign->getKey())->select('id'))->select('id'))->orderBy('id')->firstOrFail();

        return [$campaign, $step];
    }

    protected function pool(User $admin): MissionPool
    {
        $service = app(MissionPoolService::class);
        $pool = MissionPool::query()->where('slug', 'demo-e42-pool')->first()
            ?? $service->create($admin, [
                'name' => 'مجموعة مهام الموسم التجريبية', 'slug' => 'demo-e42-pool', 'description' => 'تجريبية',
                'period_type' => QuestDefinition::PERIOD_DAILY, 'selection_count' => 1, 'priority' => 100, 'eligibility_rule' => 'all', 'personalized' => true,
            ]);

        foreach ([['a', 1, 3], ['b', 2, 5]] as $i => [$suffix, $target, $xp]) {
            $quest = QuestDefinition::query()->where('internal_key', "demo_e42_pool_{$suffix}")->first()
                ?? QuestDefinition::query()->create([
                    'internal_key' => "demo_e42_pool_{$suffix}", 'name' => "مهمة الموسم {$suffix}", 'description' => 'مهمة تجريبية.', 'period_type' => QuestDefinition::PERIOD_DAILY,
                    'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED, 'target_value' => $target, 'xp_reward' => $xp, 'is_active' => true, 'sort_order' => 20 + $i,
                ]);

            if (! $pool->quests()->whereKey($quest->getKey())->exists()) {
                $service->attach($admin, $pool, $quest, $i);
            }
        }

        return $pool->is_active ? $pool : $service->activate($admin, $pool);
    }

    protected function title(): StoreItem
    {
        $item = StoreItem::query()->where('sku', 'DEMO-E42-F01')->first();

        if ($item === null) {
            $item = new StoreItem;
            $item->fill([
                'sku' => 'DEMO-E42-F01', 'slug' => 'demo-e42-f01', 'name' => 'لقب المسار: رفيق الموسم', 'short_description' => 'لقب تجميلي من مسار الموسم التجريبي.',
                'item_type' => StoreItem::TYPE_COSMETIC, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'cosmetic_slot' => StoreItem::SLOT_TITLE, 'cosmetic_text' => 'رفيق الموسم',
                'is_active' => true, 'is_featured' => false, 'grant_quantity' => 1, 'sort_order' => 0,
            ]);
            $item->save();
        }

        return $item;
    }

    protected function user(string $email, string $name, ?string $role): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user = $user->fresh();
        }

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user;
    }
}
