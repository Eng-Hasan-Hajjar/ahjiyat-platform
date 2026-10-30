<?php

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonWriterEntry;
use App\Models\SponsorshipProgram;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\SeasonalRecognition\SeasonalRecognitionException;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupportPointService;
use App\Services\SeasonalRecognition\SeasonSupporterReconciler;
use App\Services\SeasonalRecognition\SeasonSupporterCardService;
use App\Services\SeasonalRecognition\SeasonSupporterFinalizer;
use App\Services\SeasonalRecognition\SeasonSupporterPackageService;
use App\Services\SeasonalRecognition\SeasonWriterAwardService;
use App\Services\SeasonalRecognition\SeasonWriterFinalizer;
use App\Services\SeasonalRecognition\SeasonWriterRosterService;
use App\Services\SeasonalRecognition\SeasonWriterVotingService;
use App\Services\Store\StorePurchaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| E44 - مساعدات مشتركة لاختبارات دوري كتّاب الموسم وتقدير الداعمين. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync وبث null؛ لا شبكة. الزمن المثبَّت من e40_seed: now = 2026-12-10 12:00.
| الموسم الأساسي: e42_live() (نافذة 2026-11-30 00:00 → 2026-12-30 00:00)، فآخر إغلاق مسموح للتصويت = 2026-12-23 00:00 (والمساعد e44_open يغلق 2026-12-20 12:00).
| كل التهيئة بالخدمات الرسمية (الإعدادات، القائمة، الربط، الدفع E32، الشراء من المتجر). الاستثناءات الموثَّقة: إدراج محاولات لعب بتاريخ محدد (e39_attempt) لاختبار حدود الأهلية الزمنية،
| وكتابة صفوف مكسورة عمدًا في اختبارات المطابقة.
*/

require_once __DIR__.'/../SeasonE42/_support.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';
require_once __DIR__.'/../CreatorFinanceE39/_support.php';
require_once __DIR__.'/../PaymentsE32/_support.php';

if (! function_exists('e44_seed')) {
    /** المفتاحان العامان مفعَّلان، العملة المحاسبية USD، الدفع مفعَّل. $config يتجاوز أي مفتاح. */
    function e44_seed(array $config = []): void
    {
        e42_seed();
        Storage::fake('local');

        config(array_merge([
            'seasonal_recognition.writers.enabled' => true,
            'seasonal_recognition.supporters.enabled' => true,
            'seasonal_recognition.notifications.enabled' => true,
            'seasonal_recognition.cache.ttl_seconds' => 30,
            'sponsorship.accounting_currency' => 'USD',
            'payments.enabled' => true,
            'payments.notifications.enabled' => true,
        ], $config));
    }

    function e44_tick(int $minutes = 1): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    function e44_admin(): User
    {
        return e40_admin();
    }

    /** موظف بصلاحيات محدّدة فقط. @param list<string> $permissions */
    function e44_staff(array $permissions): User
    {
        return e40_staff_with($permissions);
    }

    function e44_settings(): SeasonRecognitionSettingsService
    {
        return app(SeasonRecognitionSettingsService::class);
    }

    function e44_roster(): SeasonWriterRosterService
    {
        return app(SeasonWriterRosterService::class);
    }

    function e44_voting(): SeasonWriterVotingService
    {
        return app(SeasonWriterVotingService::class);
    }

    function e44_finalizer(): SeasonWriterFinalizer
    {
        return app(SeasonWriterFinalizer::class);
    }

    function e44_awards(): SeasonWriterAwardService
    {
        return app(SeasonWriterAwardService::class);
    }

    function e44_cards(): SeasonSupporterCardService
    {
        return app(SeasonSupporterCardService::class);
    }

    function e44_points(): SeasonSupportPointService
    {
        return app(SeasonSupportPointService::class);
    }

    function e44_packages_svc(): SeasonSupporterPackageService
    {
        return app(SeasonSupporterPackageService::class);
    }

    function e44_supporter_finalizer(): SeasonSupporterFinalizer
    {
        return app(SeasonSupporterFinalizer::class);
    }

    function e44_supporter_reconciler(): SeasonSupporterReconciler
    {
        return app(SeasonSupporterReconciler::class);
    }

    /** رمز السبب لاستثناء المجال المتوقع، أو null إن لم يُرمَ. */
    function e44_reason(callable $call): ?string
    {
        try {
            $call();
        } catch (SeasonalRecognitionException $e) {
            return $e->reason;
        }

        return null;
    }

    /** رسالة أي استثناء (للأخطاء من خارج المجال كاستثناءات الدفع/الرعاية). */
    function e44_error(callable $call): ?string
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e instanceof SeasonalRecognitionException ? $e->reason : $e->getMessage();
        }

        return null;
    }

    /** يعدّ استعلامات قاعدة البيانات داخل الإغلاق. */
    function e44_queries(Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ───────────────────────────── الموسم والكتّاب ─────────────────────────────

    /** موسم مباشر بصف إعدادات (الدوري غير مُفعَّل لهذا الموسم بعد). */
    function e44_season(array $o = []): Season
    {
        $season = e42_live($o);
        e44_settings()->ensure($season);

        return $season;
    }

    /** يفعّل دوري الكتّاب للموسم (بلا نافذة بعد) ويضبط السعة/الجوائز حسب $extra. */
    function e44_enable(Season $season, array $extra = [], ?User $admin = null): void
    {
        e44_settings()->saveWriterSettings($season, $extra + ['enabled' => true], $admin ?? e44_admin());
    }

    /**
     * كاتب كامل: مبدع معتمَد ← عضوية قائمة ← سلسلة منشورة بـ$works أعمال ← ربط (ما لم يُطلب غير ذلك).
     * يتطلب أن يكون الدوري مُفعَّلًا وأن لا يكون التصويت قد فُتح بعد (القائمة تُقفل عند الفتح).
     *
     * @return array{user: User, profile: CreatorProfile, entry: SeasonWriterEntry, works: list<\App\Models\CreatorPuzzle>, series: CreatorSeries}
     */
    function e44_writer(Season $season, ?User $admin = null, array $o = []): array
    {
        $admin ??= e44_admin();
        $user = e28_creator();
        $profile = $user->creatorProfile()->firstOrFail();
        $entry = e44_roster()->addWriter($season, $profile, $admin);
        $works = e30s_works($user, $o['works'] ?? 2);
        $series = e30s_published($user, $works);

        if ($o['link'] ?? true) {
            e44_roster()->linkSeries($entry, $series, $admin);
        }

        return ['user' => $user, 'profile' => $profile, 'entry' => $entry->fresh(), 'works' => $works, 'series' => $series->fresh()];
    }

    /** يفتح نافذة التصويت من الآن حتى 2026-12-20 12:00 (يمكن تجاوز أي مفتاح). بعدها تُقفل القائمة. */
    function e44_open(Season $season, array $over = [], ?User $admin = null): void
    {
        e44_settings()->saveWriterSettings($season, $over + ['opens_at' => now(), 'closes_at' => '2026-12-20 12:00:00'], $admin ?? e44_admin());
    }

    /**
     * دوري كامل: موسم + $count كتّاب (كلٌّ بسلسلة مربوطة) ثم يُفتح التصويت ويُقدَّم الزمن دقيقة (كي تأتي محاولات اللعب بعد وقت الربط).
     * $o: open (true) | capacity | reward_plan | writers (عدد أعمال كل كاتب).
     *
     * @return array{season: Season, admin: User, writers: list<array<string, mixed>>}
     */
    function e44_league(int $count = 3, array $o = []): array
    {
        $admin = e44_admin();
        $season = e44_season();
        e44_enable($season, array_filter(['capacity' => $o['capacity'] ?? null, 'reward_plan' => $o['reward_plan'] ?? null, 'eliminated_count' => $o['eliminated_count'] ?? null], fn ($v) => $v !== null), $admin);

        $writers = [];

        for ($i = 0; $i < $count; $i++) {
            $writers[] = e44_writer($season, $admin, ['works' => $o['works'] ?? 2]);
        }

        if ($o['open'] ?? true) {
            e44_open($season, [], $admin);
            e44_tick(1);
        }

        return ['season' => $season->fresh(), 'admin' => $admin, 'writers' => $writers];
    }

    /** لاعب موثَّق حلّ فعلًا عملًا من سلسلة الكاتب (دليل لعب رسمي بخدمة المجتمع). */
    function e44_player_of(array $writer, bool $correct = true): User
    {
        $player = e16User();
        $correct ? e30s_solve($player, $writer['works'][0]) : e30s_wrong($player, $writer['works'][0]);

        return $player;
    }

    /** مصوّت مؤهَّل لكاتب واحد على الأقل + يوزّع له $stars. @return array{0: User, 1: array} [الناخب، نتيجة التوزيع] */
    function e44_vote(array $writer, int $stars, ?User $voter = null): array
    {
        $voter ??= e44_player_of($writer);

        return [$voter, e44_voting()->allocate($voter, $writer['entry']->fresh(), $stars)];
    }

    /** ناخب طاقم (صلاحية seasonal-recognition.vote فقط). */
    function e44_staff_voter(): User
    {
        return e44_staff(['seasonal-recognition.vote']);
    }

    /** نجوم كاتب الحيّة من القاعدة. */
    function e44_stars(array $writer): int
    {
        return (int) SeasonWriterEntry::query()->whereKey($writer['entry']->id)->value('stars_total');
    }

    /** مجموع توزيعات دفتر الكاتب (السلطة) للمقارنة بالمجاميع المخزَّنة. */
    function e44_ledger_stars(array $writer): int
    {
        return (int) DB::table('season_writer_star_allocations')->where('writer_entry_id', $writer['entry']->id)->sum('stars');
    }

    /** يسوّي القائمة: أول $stars لكل كاتب بالترتيب عبر ناخبين مختلفين. @param list<int> $starsPerWriter */
    function e44_cast(array $writers, array $starsPerWriter): void
    {
        foreach ($writers as $i => $writer) {
            $stars = $starsPerWriter[$i] ?? 0;

            if ($stars > 0) {
                e44_vote($writer, $stars);
            }
        }
    }

    /** يقدّم الزمن إلى ما بعد إغلاق التصويت (2026-12-20 12:00). */
    function e44_after_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:05:00'));
    }

    /** عنصر تجميلي قابل للمنح كمخزون (للجوائز). */
    function e44_cosmetic(): StoreItem
    {
        return StoreItem::factory()->cosmeticBadge()->create();
    }

    // ───────────────────────────── الداعمون ─────────────────────────────

    /** برنامج رعاية فعّال هدفه الموسم + الباقات الافتراضية الثلاث. @return array{program: SponsorshipProgram, packages: array<string, SeasonSupporterPackage>} */
    function e44_packages(Season $season, ?User $admin = null): array
    {
        $admin ??= e44_admin();
        $program = e30_program(['target_type' => 'season', 'target_id' => $season->id], true, $admin);
        e44_packages_svc()->seedDefaults($season, $program, $admin);

        return [
            'program' => $program,
            'packages' => SeasonSupporterPackage::query()->where('season_id', $season->id)->get()->keyBy('tier_key')->all(),
        ];
    }

    /** موسم مباشر بتقدير داعمين مُفعَّل وباقات جاهزة. @return array{season: Season, admin: User, program: SponsorshipProgram, packages: array<string, SeasonSupporterPackage>} */
    function e44_supporters_world(array $seasonOpts = []): array
    {
        $admin = e44_admin();
        $season = e44_season($seasonOpts);
        e44_settings()->setSupportersEnabled($season, true, $admin);

        return ['season' => $season->fresh(), 'admin' => $admin] + e44_packages($season, $admin);
    }

    function e44_request_card(User $user, SeasonSupporterPackage $package, bool $anonymous = false, ?string $key = null): SeasonSupporterCard
    {
        return e44_cards()->request($user, $package, $anonymous, null, $key);
    }

    /** طلب بطاقة ثم تحويل + تحقق مالي بالخدمات الرسمية E32. @return SeasonSupporterCard البطاقة بعد التفعيل (fresh) */
    function e44_buy_card(User $user, SeasonSupporterPackage $package, bool $anonymous = false): SeasonSupporterCard
    {
        $card = e44_request_card($user, $package, $anonymous);
        $payment = $card->payment()->firstOrFail();
        $payment = e32_payments()->markTransferred($user, $payment, 'حوّلت من حسابي', e32_proof());
        e32_payments()->verify(e32_finance(), $payment);

        return $card->fresh();
    }

    /** يسترد دفع بطاقة محقَّقة بالخدمة المالية الرسمية. */
    function e44_refund_card(SeasonSupporterCard $card, string $reason = 'استرداد تجريبي للاختبار'): SeasonSupporterCard
    {
        e32_payments()->refund(e32_finance(), $card->payment()->firstOrFail()->fresh(), $reason);

        return $card->fresh();
    }

    function e44_stat(Season $season, User $user): ?\App\Models\SeasonSupporterStat
    {
        return \App\Models\SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $user->id)->first();
    }

    function e44_user_points(Season $season, User $user): int
    {
        return (int) (e44_stat($season, $user)?->points_total ?? 0);
    }

    function e44_public_points(Season $season, User $user): int
    {
        return (int) (e44_stat($season, $user)?->public_points_total ?? 0);
    }

    function e44_entries(Season $season, ?User $user = null)
    {
        return \App\Models\SeasonSupportPointEntry::query()->where('season_id', $season->id)->when($user, fn ($q) => $q->where('user_id', $user->id))->orderBy('id')->get();
    }

    /** عنصر متجر بسعر عملة داخلية وبنقاط دعم محدّدة. @return array{item: StoreItem, price: StoreItemPrice} */
    function e44_store_item(int $supportPoints = 50, int $amount = 10): array
    {
        $item = StoreItem::factory()->create();
        $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => $amount, 'support_points' => $supportPoints]);

        return ['item' => $item, 'price' => $price->fresh()];
    }

    /** شراء حقيقي بالعملة الداخلية عبر خدمة المتجر (المحفظة تُشحن أولًا). */
    function e44_buy(User $user, array $store): StorePurchase
    {
        $price = $store['price'];
        app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, (int) $price->amount * 5, 'e44-test');

        return app(StorePurchaseService::class)->purchase($user, $store['item'], $price->fresh(), (string) Str::uuid());
    }

    /** إجمالي صفوف الجداول التي يجب ألا يمسّها E44 (عزل الاقتصاد والتقدّم والمبدعين والرعاية). */
    function e44_foreign_counts(): array
    {
        $tables = ['xp_transactions', 'gem_transactions', 'currency_transactions', 'puzzle_attempts', 'user_quest_progress', 'user_achievement_progress', 'user_pass_progress',
            'user_pass_rewards', 'user_referrals', 'sponsorship_contributions', 'community_reputation_snapshots', 'competitive_event_participants', 'competitive_reward_grants',
            'creator_earning_accounts', 'creator_earning_entries', 'creator_payouts', 'digital_purchases'];

        $counts = [];

        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }

    /** لقطة عدّادات "مالية" لمستخدم: لا يجوز أن تتغير بمنح جائزة أو نقاط دعم (قيود الدفتر + أرباح المبدعين + مدفوعاتهم). */
    function e44_money_state(User $user): array
    {
        $state = [];

        foreach (['currency_transactions', 'gem_transactions', 'xp_transactions'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $state[$table] = (int) DB::table($table)->where('user_id', $user->id)->count();
            }
        }

        foreach (['creator_earning_accounts', 'creator_earning_entries', 'creator_payouts'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $state[$table] = (int) DB::table($table)->count();
            }
        }

        return $state;
    }

    /** إشعارات قاعدة البيانات من نوع معيّن (لمستخدم اختياريًا). */
    function e44_notes(string $type, ?User $user = null)
    {
        return DB::table('notifications')->where('type_key', $type)->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
    }

    /** عدد سجلات التدقيق التشغيلي لإجراء. */
    function e44_audit(string $action): int
    {
        return DB::table('operational_audit_logs')->where('action', $action)->count();
    }

    /** صفحة الإدارة لمستخدم وموسم وتبويب محدّدين. */
    function e44_ap_page(User $user, ?Season $season = null, string $tab = 'writers')
    {
        test()->actingAs($user);
        $page = \Livewire\Livewire::test(\App\Filament\Pages\SeasonalRecognitionPage::class);

        if ($season !== null) {
            $page->set('seasonId', $season->id);
        }

        return $tab === 'writers' ? $page : $page->call('setTab', $tab);
    }

    /**
     * مسار نسبي إلى جذر المشروع بشرطات مائلة أمامية دائمًا، على لينكس وويندوز معًا.
     * على ويندوز يعيد base_path() شرطات عكسية بينما يعيد glob() شرطات أمامية، فلا يصح اقتطاع البادئة بمقارنة نصية مباشرة (تتكرر البادئة عند الدمج مجددًا).
     * نطبّع الطرفين ثم نقتطع البادئة بلا حساسية لحالة الأحرف (حرف القرص C:/c:)؛ وأي مسار نسبي أصلًا يمرّ كما هو.
     */
    function e44_rel(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return strncasecmp($path, $base, strlen($base)) === 0 ? substr($path, strlen($base)) : $path;
    }

    /** نص الشيفرة بلا تعليقات (للفحوص الساكنة)، بنهايات أسطر LF دائمًا (ملفات ويندوز قد تُسحب بـCRLF فتفشل أنماط \n الصريحة). */
    function e44_code(string $relative): string
    {
        return str_replace("\r\n", "\n", e42_code_only(e44_rel($relative)));
    }

    /** نص ملف كما هو لكن بنهايات أسطر LF دائمًا. */
    function e44_read(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(base_path(e44_rel($relative))));
    }
}
