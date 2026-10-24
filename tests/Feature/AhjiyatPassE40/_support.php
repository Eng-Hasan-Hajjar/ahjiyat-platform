<?php

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\DigitalPurchase;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassClaimService;
use App\Services\AhjiyatPass\PassDefinitionCache;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPointService;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\AhjiyatPass\PassProgressService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E40 - مساعدات مشتركة لاختبارات مسار الأحجيات. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. بناء المسار والنشر والشراء والتحقق والاستلام كلها بالخدمات الرسمية.
| إنجاز المهمة الحيّ يمرّ بالمسار الكامل (PuzzleAttemptService ⇒ EngagementService ⇒ QuestService ⇒ خطّاف المسار)؛ وإدراج UserQuestProgress المباشر
| يُستعمل فقط لبيانات المصدر التاريخية (إكمال سابق داخل النافذة) لاختبارات الاستعادة والحدود الزمنية.
| الزمن المثبَّت: now = 2026-12-10 12:00. المسار الافتراضي يبدأ قبل 14 يومًا (فعّال) وينتهي بعد 16 يومًا (30 يومًا إجمالًا) كي تتّسع الإكمالات اليومية التاريخية داخل النافذة.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../LiveOpsE34/_support.php'; // e34_solve (حلّ أحجية رسمي كامل المسار)

if (! function_exists('e40_seed')) {
    function e40_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00'));
        config([
            'ahjiyat_pass.enabled' => true,
            'ahjiyat_pass.cache.analytics_seconds' => 0,
            'ahjiyat_pass.premium.provider' => 'manual',
        ]);
        // كاشات LiveOps معطّلة لحتمية حلّ الأحجيات داخل الاختبارات
        config(['liveops.pools.cache_seconds' => 0, 'liveops.calendar.cache_seconds' => 0]);
    }

    function e40_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    /** مشرف عادي بلا أي صلاحية مسار. */
    function e40_plain_staff(): User
    {
        $user = e16User();
        $user->assignRole('moderator');

        return $user;
    }

    /** @param list<string> $permissions */
    function e40_staff_with(array $permissions): User
    {
        $user = e16User();
        $user->givePermissionTo($permissions);

        return $user;
    }

    function e40_builder(): PassBuilderService
    {
        return app(PassBuilderService::class);
    }

    function e40_life(): PassLifecycleService
    {
        return app(PassLifecycleService::class);
    }

    function e40_premium(): PassPremiumService
    {
        return app(PassPremiumService::class);
    }

    function e40_claims(): PassClaimService
    {
        return app(PassClaimService::class);
    }

    function e40_points(): PassPointService
    {
        return app(PassPointService::class);
    }

    function e40_cache(): PassDefinitionCache
    {
        return app(PassDefinitionCache::class);
    }

    function e40_progress(): PassProgressService
    {
        return app(PassProgressService::class);
    }

    /** لقب حصري (تجميلي بلا صورة ولا سعر) صالح كمكافأة. */
    function e40_item(array $over = []): StoreItem
    {
        return StoreItem::factory()->cosmeticTitle('لقب '.fake()->unique()->numerify('####'))->create($over);
    }

    /** امتياز (Entitlement) صالح كمكافأة. */
    function e40_entitlement_item(array $over = []): StoreItem
    {
        return StoreItem::factory()->entitlement('e40.perk.'.fake()->unique()->numerify('####'))->create($over);
    }

    /** مهمة حلّ أحجيات عامة (يومية افتراضيًا، هدف 1). */
    function e40_quest(array $over = []): QuestDefinition
    {
        return QuestDefinition::factory()->daily(1)->create($over);
    }

    /**
     * يبني مسارًا منشورًا بالخدمات الرسمية.
     * $o: tiers (5) | step (100) | ramp (0) | starts (قبل يومين) | days (30) | grace (5) | price (500 = 5.00) | currency (USD) | premium (true)
     *     | missions: list<array{difficulty, points, max, quest?}> (افتراضيًا مهمة سهلة واحدة 50×14) | free_tiers/premium_tiers (null ⇒ الزوجية/الفردية) | publish (true) | name
     *
     * @return array{pass: AhjiyatPass, tiers: array<int, AhjiyatPassTier>, rewards: array<int, array{free: ?AhjiyatPassTierReward, premium: ?AhjiyatPassTierReward}>, quests: list<QuestDefinition>, admin: User}
     */
    function e40_build(array $o = []): array
    {
        $admin = $o['admin'] ?? e40_admin();
        $builder = e40_builder();
        $starts = isset($o['starts']) ? Carbon::parse($o['starts']) : now()->subDays(14);
        $days = (int) ($o['days'] ?? 30);

        $pass = $builder->createDraft($admin, array_filter([
            'internal_key' => $o['key'] ?? 'pass-'.fake()->unique()->numerify('######'),
            'name' => $o['name'] ?? 'مسار الشتاء',
            'starts_at' => $starts->format('Y-m-d H:i:s'),
            'ends_at' => $starts->copy()->addDays($days)->format('Y-m-d H:i:s'),
            'claim_grace_days' => $o['grace'] ?? 5,
            'premium_enabled' => $o['premium'] ?? true,
            'premium_price_minor' => ($o['premium'] ?? true) ? ($o['price'] ?? 500) : null,
            'currency' => ($o['premium'] ?? true) ? ($o['currency'] ?? 'USD') : null,
            'premium_sales_start_at' => $o['sales_start'] ?? null,
            'premium_sales_end_at' => $o['sales_end'] ?? null,
        ], fn ($v) => $v !== null));

        $count = (int) ($o['tiers'] ?? 5);
        $builder->generateTiers($admin, $pass, $count, (int) ($o['step'] ?? 100), (int) ($o['ramp'] ?? 0), 0);

        $tiers = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->id)->orderBy('tier_number')->get()->keyBy('tier_number')->all();
        $rewards = [];

        foreach ($tiers as $number => $tier) {
            $rewards[$number] = ['free' => null, 'premium' => null];
            $wantFree = isset($o['free_tiers']) ? in_array($number, $o['free_tiers'], true) : $number % 2 === 0;
            $wantPremium = ($o['premium'] ?? true) && (isset($o['premium_tiers']) ? in_array($number, $o['premium_tiers'], true) : $number % 2 === 1);

            if ($wantFree) {
                $rewards[$number]['free'] = $builder->setTierReward($admin, $tier, 'free', $o['free_item'] ?? e40_item());
            }

            if ($wantPremium) {
                $rewards[$number]['premium'] = $builder->setTierReward($admin, $tier, 'premium', $o['premium_item'] ?? e40_item());
            }
        }

        $quests = [];
        $missions = $o['missions'] ?? [['difficulty' => 'easy', 'points' => 50, 'max' => 14]];

        foreach ($missions as $i => $m) {
            $quest = $m['quest'] ?? e40_quest(['name' => 'مهمة المسار '.($i + 1)]);
            $builder->attachMission($admin, $pass, $quest, $m['difficulty'] ?? 'easy', ['points' => $m['points'] ?? 50, 'max_completions' => $m['max'] ?? 14, 'sort_order' => $i]);
            $quests[] = $quest;
        }

        if (($o['publish'] ?? true) === true) {
            e40_life()->schedule($admin, $pass->fresh());
        }

        return ['pass' => $pass->fresh(), 'tiers' => $tiers, 'rewards' => $rewards, 'quests' => $quests, 'admin' => $admin];
    }

    /** اختصار: المسار فقط. */
    function e40_pass(array $o = []): AhjiyatPass
    {
        return e40_build($o)['pass'];
    }

    /** لاعب موثَّق (e16User) + اختياريًا تقدّم مُنشأ مسبقًا. */
    function e40_player(?AhjiyatPass $pass = null, array $attrs = []): User
    {
        $user = e16User($attrs);

        if ($pass !== null) {
            e40_progress()->ensure($user, $pass);
        }

        return $user->fresh();
    }

    /** إنجاز مهمة المسار عبر المسار الكامل الحقيقي (حلّ أحجية رسمية). يُرجع سجلّ تقدّم المهمة. */
    function e40_complete_live(User $user, ?QuestDefinition $quest = null): void
    {
        e34_solve($user);
    }

    /**
     * إكمال تاريخي لمهمة (بيانات مصدر): صف UserQuestProgress مُكتمل بوقت محدد داخل/خارج النافذة.
     * الفترة تُشتقّ من الوقت؛ المفتاح فريد لكل (مستخدم، مهمة، مفتاح فترة).
     */
    function e40_completion(User $user, QuestDefinition $quest, string|Carbon|null $at = null, ?string $periodKey = null): \App\Models\UserQuestProgress
    {
        $at = $at === null ? now() : Carbon::parse($at);
        $daily = $quest->period_type === QuestDefinition::PERIOD_DAILY;
        $start = $daily ? $at->copy()->startOfDay() : $at->copy()->startOfWeek();
        $end = $daily ? $at->copy()->endOfDay() : $at->copy()->endOfWeek();

        return \App\Models\UserQuestProgress::query()->create([
            'user_id' => $user->id,
            'quest_definition_id' => $quest->id,
            'period_type' => $quest->period_type,
            'period_key' => $periodKey ?? ($daily ? 'daily:'.$start->format('Y-m-d') : 'weekly:'.$start->format('o-\WW')),
            'period_start' => $start,
            'period_end' => $end,
            'current_value' => (int) $quest->target_value,
            'target_value_snapshot' => (int) $quest->target_value,
            'completed_at' => $at,
        ]);
    }

    /** منح نقاط مسار لمستخدم عبر الخدمة الرسمية: إكمال تاريخي ثم استعادة. يُرجع عدد المنح. */
    function e40_grant(User $user, AhjiyatPass $pass, QuestDefinition $quest, int $times = 1, ?Carbon $from = null): int
    {
        $from ??= now()->subDays(1);

        for ($i = 0; $i < $times; $i++) {
            e40_completion($user, $quest, $from->copy()->subDays($i)->setTime(10, 0));
        }

        return e40_points()->healFor($user->fresh(), $pass->fresh());
    }

    function e40_row(User $user, AhjiyatPass $pass): ?UserPassProgress
    {
        return UserPassProgress::query()->where('user_id', $user->id)->where('ahjiyat_pass_id', $pass->id)->first();
    }

    function e40_points_of(User $user, AhjiyatPass $pass): int
    {
        return (int) (e40_row($user, $pass)?->pass_points ?? 0);
    }

    function e40_tier_of(User $user, AhjiyatPass $pass): int
    {
        return (int) (e40_row($user, $pass)?->highest_tier ?? 0);
    }

    /** يمنح المستخدم نقاطًا مباشرةً لبلوغ شريحة (إكمالات تاريخية على مهمة المسار الأولى). */
    function e40_reach(User $user, array $build, int $points): void
    {
        $quest = $build['quests'][0];
        $mission = \App\Models\AhjiyatPassMission::query()->where('ahjiyat_pass_id', $build['pass']->id)->where('quest_definition_id', $quest->id)->first();
        $per = max(1, (int) $mission->pass_points);
        $times = (int) ceil($points / $per);
        e40_grant($user, $build['pass'], $quest, $times, now()->subDay());
    }

    /** يشتري المميز بالدورة الكاملة (طلب ⇒ تقديم ⇒ تحقق إداري). @return DigitalPurchase المحقَّق */
    function e40_buy_premium(User $user, AhjiyatPass $pass, ?User $admin = null): DigitalPurchase
    {
        $order = e40_premium()->request($user, $pass);
        $order = e40_premium()->submit($user, $order, 'TRX-'.fake()->unique()->numerify('########'), 'تحويل تجريبي');

        return e40_premium()->verify($admin ?? e40_admin(), $order, 'تحقق تجريبي');
    }

    /** رسالة PassException أو null. */
    function e40_error(callable $call): ?string
    {
        try {
            $call();
        } catch (PassException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** رمز السبب في PassException أو null. */
    function e40_reason(callable $call): ?string
    {
        try {
            $call();
        } catch (PassException $e) {
            return $e->reason;
        }

        return null;
    }

    /** إشعارات نوع محدد (قيمة NotificationType) لمستخدم، أو للجميع إن null. */
    function e40_notes(?User $user, string $type)
    {
        $query = \Illuminate\Notifications\DatabaseNotification::query();

        if ($user !== null) {
            $query->where('notifiable_id', $user->id);
        }

        return $query->get()->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->values();
    }

    /** يعدّ صفوف جداول E40 (لقياس «لم يتغير شيء»). */
    function e40_counts(): array
    {
        return [
            'passes' => DB::table('ahjiyat_passes')->count(),
            'tiers' => DB::table('ahjiyat_pass_tiers')->count(),
            'rewards' => DB::table('ahjiyat_pass_tier_rewards')->count(),
            'missions' => DB::table('ahjiyat_pass_missions')->count(),
            'progress' => DB::table('user_pass_progress')->count(),
            'grants' => DB::table('pass_point_grants')->count(),
            'claims' => DB::table('user_pass_reward_claims')->count(),
            'purchases' => DB::table('digital_purchases')->count(),
            'events' => DB::table('digital_purchase_events')->count(),
        ];
    }

    /** لقطة اقتصاد اللاعب (لإثبات العزل): XP، جواهر، أرصدة المحفظة، مستوى. */
    function e40_economy(User $user): array
    {
        return [
            'xp_total' => (int) (DB::table('player_progressions')->where('user_id', $user->id)->value('total_xp') ?? 0),
            'xp_tx' => (int) DB::table('xp_transactions')->where('user_id', $user->id)->count(),
            'currency_tx' => (int) DB::table('currency_transactions')->where('user_id', $user->id)->count(),
            'currency_sum' => (int) DB::table('currency_transactions')->where('user_id', $user->id)->sum('amount'),
            'wallet_pending' => (int) DB::table('wallets')->where('user_id', $user->id)->sum('pending_balance'),
            'wallet_available' => (int) DB::table('wallets')->where('user_id', $user->id)->sum('available_balance'),
        ];
    }

    /** جداول المنافسة والموسم والإحالة والرعاية والمبدعين والسمعة والإشراف — يجب ألا يتغير شيء فيها بسبب المسار. */
    function e40_foreign_counts(): array
    {
        $tables = ['seasons', 'competitive_event_participants', 'competitive_event_results', 'competitive_reward_grants', 'competitive_event_team_results', 'team_challenge_results', 'team_championship_results',
            'user_referrals', 'sponsorship_contributions', 'sponsorship_payments', 'creator_earning_entries', 'creator_earning_accounts', 'community_reputation_snapshots', 'moderation_cases', 'moderation_actions'];

        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /**
     * يفحص أن ملفات مجلد لا تذكر أيًّا من الكلمات (مسح مصدري لإثبات «لا اعتماد»).
     *
     * @param  list<string>  $needles
     * @return list<string> ملفات مخالفة بصيغة "file => needle"
     */
    function e40_scan_for(string $relativeDir, array $needles): array
    {
        $violations = [];
        $base = base_path($relativeDir);

        if (! is_dir($base)) {
            return $violations;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ($needles as $needle) {
                if (stripos($source, $needle) !== false) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' => '.$needle;
                }
            }
        }

        return $violations;
    }
}
