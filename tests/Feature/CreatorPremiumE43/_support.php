<?php

use App\Models\CreatorDirectSale;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesAccess;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Commerce\CommerceException;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorPremium\CreatorPremiumException;
use App\Services\CreatorPremium\CreatorSeriesOfferService;
use App\Services\CreatorPremium\CreatorSeriesPurchaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
| E43 - مساعدات مشتركة لاختبارات سوق المبدعين المميز. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| تعيد استخدام مساعدات E41 (مزوّد sandbox التجريبي)، وE30 (سلسلة منشورة بالخدمات الرسمية)، وE39 (مبدع مفعَّل التسييل ومسؤول مالي). SQLite وكاش array وطابور sync؛ لا شبكة.
| الزمن المثبَّت من e41_seed/e40_seed: now = 2026-12-10 12:00 (استعمل e43_tick لتقديمه؛ التجميد المتقادم يقارن بزمن المحاولة قبل أول تفعيل بدقة صارمة).
| كل التهيئة بالخدمات الرسمية: لا كتابة مباشرة في الوصول أو البيع أو الدفتر (الاستثناء الموثَّق: خفض المتاح بتعديل إداري رسمي لمحاكاة «دفعها المبدع»).
*/

require_once __DIR__.'/../CommerceE41/_support.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';
require_once __DIR__.'/../CreatorFinanceE39/_support.php';

if (! function_exists('e43_seed')) {
    /** المفتاح مفعَّل، التسييل مفعَّل، العملة USD، المزوّد التجريبي مفعَّل (e41). */
    function e43_seed(array $config = []): void
    {
        e41_seed(array_merge([
            'creator_premium.enabled' => true,
            'creator_premium.notifications.enabled' => true,
            'creator_monetization.enabled' => true,
            'creator_monetization.accounting_currency' => 'USD',
            'sponsorship.accounting_currency' => 'USD',
        ], $config));
    }

    function e43_tick(int $minutes = 1): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    function e43_offers(): CreatorSeriesOfferService
    {
        return app(CreatorSeriesOfferService::class);
    }

    function e43_purchases(): CreatorSeriesPurchaseService
    {
        return app(CreatorSeriesPurchaseService::class);
    }

    function e43_access_service(): \App\Services\CreatorPremium\CreatorPremiumAccess
    {
        return app(\App\Services\CreatorPremium\CreatorPremiumAccess::class);
    }

    /** عرض بسعر نصي (وحدة رئيسية)؛ يفعّله افتراضيًا. */
    function e43_offer(User $creator, CreatorSeries $series, string $price = '5.00', bool $activate = true): CreatorSeriesSaleOffer
    {
        $offer = e43_offers()->createDraft($creator, $series->public_id, $price);

        return $activate ? e43_offers()->activate($creator, $offer->public_id) : $offer;
    }

    /**
     * مشهد كامل: مسؤول مالي، مبدع معتمَد مفعَّل التسييل، سلسلة منشورة بـ$works أحجيات، وعرض نشط (5.00 USD افتراضيًا).
     *
     * @return array{admin: User, user: User, profile: \App\Models\CreatorProfile, account: \App\Models\CreatorEarningAccount, works: list<CreatorPuzzle>, series: CreatorSeries, offer: ?CreatorSeriesSaleOffer}
     */
    function e43_world(array $o = []): array
    {
        $admin = e39_admin();
        [$user, $profile, $account] = e39_creator($admin);
        $works = e30s_works($user, $o['works'] ?? 3);
        $series = e30s_published($user, $works);
        $offer = ($o['offer'] ?? true) ? e43_offer($user, $series, $o['price'] ?? '5.00', $o['activate'] ?? true) : null;

        return ['admin' => $admin, 'user' => $user, 'profile' => $profile, 'account' => $account->fresh(), 'works' => $works, 'series' => $series->fresh(), 'offer' => $offer?->fresh()];
    }

    /** طلب شراء + بدء checkout عبر sandbox. @return array{purchase: DigitalPurchase, session: string, url: string, user: User} */
    function e43_order(User $buyer, CreatorSeries $series, ?string $key = null): array
    {
        $purchase = e43_purchases()->request($buyer, $series->fresh(), $key);
        $started = e41_checkout()->start($buyer, $purchase);

        return ['purchase' => $purchase->fresh(), 'session' => $started['session_id'], 'url' => $started['url'], 'user' => $buyer];
    }

    /** شراء مدفوع ومحقَّق عبر مسار المزوّد الموثوق. @return array{purchase: DigitalPurchase, session: string, user: User, result: array, sale: ?CreatorDirectSale} */
    function e43_buy(array $world, ?User $buyer = null, ?string $key = null): array
    {
        $buyer ??= e16User();
        $order = e43_order($buyer, $world['series'], $key);
        $result = e41_pay($order['session']);
        $purchase = $order['purchase']->fresh();

        return ['purchase' => $purchase, 'session' => $order['session'], 'user' => $buyer, 'result' => $result, 'sale' => CreatorDirectSale::query()->where('digital_purchase_id', $purchase->id)->first()];
    }

    function e43_access_row(User $user, CreatorSeries $series): ?CreatorSeriesAccess
    {
        return CreatorSeriesAccess::query()->where('user_id', $user->id)->where('creator_series_id', $series->id)->first();
    }

    /** رمز السبب لأي استثناء مجال متوقع من E43/E41/E39، أو null إن لم يُرمَ. */
    function e43_reason(callable $call): ?string
    {
        try {
            $call();
        } catch (CreatorPremiumException|CommerceException $e) {
            return $e->reason;
        } catch (CreatorFinanceException $e) {
            return 'finance:'.$e->getMessage();
        } catch (\App\Services\Creators\CreatorException $e) {
            return 'creator:'.$e->getMessage();
        }

        return null;
    }

    /** رصيد المبدع المتاح (لقطة الحساب). */
    function e43_available(\App\Models\CreatorEarningAccount $account): int
    {
        return (int) $account->fresh()->available_minor;
    }

    /** يخفض المتاح لحساب بتعديل إداري رسمي (محاكاة صرف/خصم) كي يتعذّر عكس حصة بيع لاحقًا. */
    function e43_drain(array $world, ?string $leave = '0.00'): void
    {
        $account = $world['account']->fresh();
        $minor = (int) $account->available_minor;
        $leaveMinor = \App\Services\CreatorFinance\CreatorMoney::parse($leave, (string) $account->currency);
        $take = $minor - $leaveMinor;

        if ($take > 0) {
            e39_ledger()->adjust($account, e39_admin(), 'debit', \App\Services\CreatorFinance\CreatorMoney::toMajorText($take, (string) $account->currency), 'correction', 'تفريغ المتاح لاختبار العكس المحجوب.');
        }
    }

    /** لقطة صفوف الجداول التي يجب ألا يمسّها السوق المميز (عزل الاقتصاد/التقدّم/الموسم/الإحالة/السمعة/الرعاية). */
    function e43_foreign_counts(): array
    {
        $tables = ['xp_transactions', 'gem_transactions', 'currency_transactions', 'puzzle_attempts', 'user_quest_progress', 'user_achievement_progress', 'user_pass_progress', 'user_pass_rewards',
            'user_referrals', 'sponsorship_contributions', 'sponsorship_payments', 'community_reputation_snapshots', 'competitive_event_participants', 'competitive_reward_grants',
            'creator_revenue_periods', 'creator_revenue_allocations', 'creator_payouts', 'store_purchases'];

        $counts = [];

        foreach ($tables as $t) {
            $counts[$t] = \Illuminate\Support\Facades\Schema::hasTable($t) ? DB::table($t)->count() : -1;
        }

        return $counts + ['wallets_available' => (int) DB::table('wallets')->sum('available_balance')];
    }

    /** إشعارات نوع محدد لمستخدم. */
    function e43_notes(User $user, string $type)
    {
        return e39_notes($user, $type);
    }
}
