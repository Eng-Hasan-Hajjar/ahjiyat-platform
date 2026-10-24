<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use Illuminate\Support\Facades\Cache;

/**
 * E40 - تذكير مهلة الاستلام: **مرة واحدة** لكل لاعب/مسار، فقط لمن لديه مكافآت قابلة للاستلام فعلًا، ضمن آخر N ساعة من مهلة الاستلام بعد انتهاء المسار.
 * لا لغة ضغط ولا تكرار (مفتاح idempotency دلالي في PassNotifier). المسح مُجزَّأ بمؤشر في الكاش ومحدود بـ reminder_batch صفًا لكل تشغيل (لا مسح شامل في تشغيل واحد).
 * غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط. اللاعب المجمَّد لا يُشعَر (PassNotifier).
 */
class PassReminderService
{
    public function __construct(
        protected PassDefinitionCache $cache,
        protected PassNotifier $notifier,
    ) {}

    /** @return array{passes: int, scanned: int, sent: int} */
    public function run(): array
    {
        if (! (bool) config('ahjiyat_pass.enabled', true) || ! (bool) config('ahjiyat_pass.notifications.enabled', true)) {
            return ['passes' => 0, 'scanned' => 0, 'sent' => 0];
        }

        $hours = max(1, (int) config('ahjiyat_pass.notifications.claim_window_reminder_hours', 48));
        $batch = max(10, (int) config('ahjiyat_pass.notifications.reminder_batch', 200));
        $now = now();

        $passes = AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->where('status', '!=', AhjiyatPass::STATUS_ARCHIVED)
            ->where('ends_at', '<=', $now)
            ->where('claim_ends_at', '>', $now)
            ->where('claim_ends_at', '<=', $now->copy()->addHours($hours))
            ->orderBy('claim_ends_at')
            ->get();

        $scanned = 0;
        $sent = 0;

        foreach ($passes as $pass) {
            $key = 'ahjiyat_pass:reminder:'.$pass->getKey();
            $state = Cache::get($key, ['cursor' => 0, 'done' => false]);

            if (($state['done'] ?? false) === true) {
                continue;
            }

            $track = $this->cache->track($pass);
            $rows = UserPassProgress::query()
                ->where('ahjiyat_pass_id', $pass->getKey())
                ->where('id', '>', (int) ($state['cursor'] ?? 0))
                ->where('highest_tier', '>', 0)
                ->orderBy('id')
                ->limit($batch)
                ->get(['id', 'user_id', 'highest_tier', 'premium_unlocked_at']);

            if ($rows->isEmpty()) {
                Cache::put($key, ['cursor' => (int) ($state['cursor'] ?? 0), 'done' => true], $pass->claim_ends_at);

                continue;
            }

            $claimed = UserPassRewardClaim::query()
                ->whereIn('user_pass_progress_id', $rows->pluck('id'))
                ->get(['user_pass_progress_id', 'ahjiyat_pass_tier_reward_id'])
                ->groupBy('user_pass_progress_id');

            $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

            foreach ($rows as $row) {
                $scanned++;
                $user = $users->get($row->user_id);

                if ($user === null || $user->is_frozen) {
                    continue;
                }

                $ids = ($claimed->get($row->id) ?? collect())->mapWithKeys(fn ($c) => [(int) $c->ahjiyat_pass_tier_reward_id => true])->all();

                if ($track->claimable((int) $row->highest_tier, $row->premium_unlocked_at !== null, $ids) === []) {
                    continue;
                }

                $sent += $this->notifier->claimWindowClosing($user, $pass) ? 1 : 0;
            }

            Cache::put($key, ['cursor' => (int) $rows->last()->id, 'done' => false], $pass->claim_ends_at);
        }

        return ['passes' => $passes->count(), 'scanned' => $scanned, 'sent' => $sent];
    }
}
