<?php

use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Services\Economy\CurrencyWalletService;
use App\Services\RedemptionService;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\Integrity\WalletAuditScope;
use Illuminate\Support\Facades\DB;

/*
| E46 - مساعدات مشتركة لاختبارات «سلامة الجواهر». ليست ملف اختبار (لا تنتهي بـTest.php).
| لا شبكة ولا أسرار. كل الأسماء بالبادئة e46_ كي لا تتصادم مع مساعدات الاختبارات الأخرى في العملية نفسها.
*/

if (! function_exists('e46_gem')) {
    /** عملة «الجواهر المكتسبة» الافتراضية (platform-earned) من السجل الرسمي. */
    function e46_gem(): Currency
    {
        return app(CurrencyRegistry::class)->defaultEarnedCurrency();
    }

    /** عملة حملة/حدث معزولة (ليست الجواهر القابلة للاستبدال). */
    function e46_event_currency(): Currency
    {
        return Currency::factory()->create();
    }

    /** نطاق لمستخدم/عملات بلا نافذة سماح افتراضيًا (الاختبارات تتحكم بالحركة الجارية صراحةً). */
    function e46_scope(?int $userId = null, ?array $currencyIds = null, int $chunk = 500, int $grace = 0, bool $orphans = true): WalletAuditScope
    {
        return new WalletAuditScope($userId, $currencyIds, $chunk, $grace, $orphans);
    }

    /** يعدّل أرقام المحفظة بـSQL مباشر (يحاكي تعديلًا خارج الخدمة) ويؤرّخ updated_at قديمًا كي لا يُعدّ حركة جارية. */
    function e46_tamper(User|int $user, Currency $currency, array $attributes): void
    {
        DB::table('wallets')
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->where('currency_id', $currency->id)
            ->update($attributes + ['updated_at' => now()->subDays(3)]);
    }

    /** يجعل كل حركات الدفتر والمحافظ قديمة (خارج أي نافذة سماح). */
    function e46_age_everything(int $days = 3): void
    {
        DB::table('wallets')->update(['updated_at' => now()->subDays($days)]);
        DB::table('currency_transactions')->update(['created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);
    }

    /** إدراج صف دفتر خام (يتجاوز الخدمة عمدًا لبناء حالات شاذة/مرحَّلة). */
    function e46_raw_ledger(User|int $user, Currency $currency, string $type, int $amount, string $reason = 'raw'): int
    {
        return DB::table('currency_transactions')->insertGetId([
            'user_id' => $user instanceof User ? $user->id : $user,
            'currency_id' => $currency->id,
            'amount' => $amount,
            'type' => $type,
            'reason' => $reason,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
    }

    function e46_wallet(User $user, Currency $currency): Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->where('currency_id', $currency->id)->firstOrFail();
    }

    /** نتيجة الحالة الوحيدة (مستخدم+عملة) بنطاق بلا سماح. */
    function e46_case(User $user, Currency $currency, int $grace = 0): ?\App\Services\Economy\Integrity\WalletAuditCase
    {
        return app(\App\Services\Economy\Integrity\WalletIntegrityAuditor::class)
            ->auditPair($user->id, $currency->id, e46_scope($user->id, [$currency->id], 500, $grace));
    }

    /** مجموع المبالغ كما تفعل SUM(amount) وحدها (للبرهنة أنها لا تكفي). */
    function e46_naive_sum(User $user, Currency $currency): int
    {
        return (int) CurrencyTransaction::query()->where('user_id', $user->id)->where('currency_id', $currency->id)->sum('amount');
    }
}

if (! function_exists('e46_eligible_user')) {
    /** مستخدم مؤهّل فعلًا وفق config('gems.eligibility') (بدون العبث المباشر بالرصيد). */
    function e46_eligible_user(): User
    {
        $rules = config('gems.eligibility');
        $user = User::factory()->create(['created_at' => now()->subDays($rules['min_account_age_days'] + 1)]);

        for ($i = 0; $i < $rules['min_puzzles_solved']; $i++) {
            PuzzleAttempt::create([
                'user_id' => $user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1, 'is_correct' => true,
            ]);
        }

        return $user->fresh();
    }

    /** يموّل المحفظة عبر الخدمة الرسمية (يبقى الدفتر والمحفظة متطابقين) ثم يقدّم طلب استبدال حقيقيًا. */
    function e46_request(User $user, ?Currency $currency = null, ?int $amount = null): RedemptionRequest
    {
        $currency ??= e46_gem();
        $amount ??= (int) config('gems.min_redemption');

        app(CurrencyWalletService::class)->creditAvailable($user, $currency, $amount, 'e46_funding');

        return app(RedemptionService::class)->requestRedemption($user, $amount, 'قسيمة', $currency);
    }
}
