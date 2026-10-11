<?php

namespace App\Services;

use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use Carbon\CarbonInterface;

/**
 * E24: مهمة «تحويل الجواهر المعلّقة إلى متاحة» (كانت إغلاقًا داخل routes/console.php).
 *
 * نفس المعادلة بالضبط لكل مستخدم: toRelease = مجموع earn_pending الأقدم من (الآن - pending_hold_days) − مجموع release_available السابق،
 * وإن كان موجبًا يُنفَّذ التحرير **حصرًا** عبر GemWalletService::releasePendingToAvailable (قفل المحفظة + التقييد بالرصيد المعلّق الفعلي + سجل المعاملة).
 * هذه الخدمة لا تلمس الأرصدة بنفسها إطلاقًا؛ هي فقط تختار المرشَّحين وتحسب المبلغ بدفعة واحدة.
 *
 * ما تغيّر عن الأصل (الأداء/الموثوقية فقط، لا الدلالات):
 *  - استعلامان مجمَّعان (GROUP BY user_id) لكل دفعة بدل استعلامَي SUM لكل مستخدم (كان 2×N استعلامًا).
 *  - chunkById على المحافظ بدل chunk() على المستخدمين: الأصل كان يعدّل المجموعة التي يُرقِّمها (المستخدم يخرج من الشرط بعد التحرير) فيتخطّى chunk() بالإزاحة مستخدمين حين يتجاوز المرشَّحون الدفعة (200).
 *  - المرشَّحون: محافظ العملة المكتسبة الافتراضية بـ pending_balance > 0 (هي بالضبط علاقة User::wallet() التي استعملها الأصل)، بلا تحميل User::all().
 * إعادة التشغيل آمنة (idempotent): بعد التحرير يصير المجموعان متساويين فلا شيء يُحرَّر ثانيةً، والتحرير نفسه مقيَّد بالرصيد المعلّق.
 */
class PendingGemReleaseService
{
    public function __construct(protected GemWalletService $gems, protected CurrencyRegistry $currencies) {}

    /**
     * @return array{candidates: int, released_users: int, released_gems: int}
     */
    public function run(?CarbonInterface $now = null, int $chunk = 500): array
    {
        $cutoff = ($now ?? now())->copy()->subDays((int) config('gems.pending_hold_days'));
        $currencyId = $this->currencies->defaultEarnedCurrency()->id;
        $stats = ['candidates' => 0, 'released_users' => 0, 'released_gems' => 0];

        Wallet::query()
            ->where('currency_id', $currencyId)
            ->where('pending_balance', '>', 0)
            ->chunkById(max(1, $chunk), function ($wallets) use ($cutoff, &$stats) {
                $userIds = $wallets->pluck('user_id')->unique()->values();

                // نفس مجموعَي الأصل (بلا فلتر عملة، كما كانا) لكن لدفعة كاملة باستعلام واحد لكلٍّ منهما.
                $earned = $this->sumByUser($userIds, CurrencyTransaction::TYPE_EARN_PENDING, $cutoff);
                $released = $this->sumByUser($userIds, CurrencyTransaction::TYPE_RELEASE_AVAILABLE);
                $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

                foreach ($wallets as $wallet) {
                    $stats['candidates']++;
                    $user = $users->get($wallet->user_id);

                    if ($user === null) {
                        continue;
                    }

                    $toRelease = (int) ($earned[$user->id] ?? 0) - (int) ($released[$user->id] ?? 0);

                    if ($toRelease > 0 && ($tx = $this->gems->releasePendingToAvailable($user, $toRelease, 'pending_hold_expired')) !== null) {
                        $stats['released_users']++;
                        $stats['released_gems'] += (int) $tx->amount;
                    }
                }
            });

        return $stats;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $userIds
     * @return array<int, int> user_id => مجموع المبالغ
     */
    protected function sumByUser($userIds, string $type, ?CarbonInterface $createdUntil = null): array
    {
        return CurrencyTransaction::query()
            ->selectRaw('user_id, sum(amount) as total')
            ->whereIn('user_id', $userIds)
            ->where('type', $type)
            ->when($createdUntil !== null, fn ($q) => $q->where('created_at', '<=', $createdUntil))
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
