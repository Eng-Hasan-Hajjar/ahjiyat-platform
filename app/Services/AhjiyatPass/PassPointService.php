<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\PassPointGrant;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserQuestProgress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E40 - منح نقاط المسار من **إكمال مهمة فعلي** (UserQuestProgress.completed_at) لمهمة مرتبطة بالمسار. لا مصدر آخر ولا عميل:
 *  - QuestService يبقى السلطة على الإكمال؛ هذه الخدمة تتفاعل معه فقط (خطّاف معزول الفشل) ولا تعيد تقييم شيء ولا تلمس مكافأة المهمة الأصلية.
 *  - idempotent بقوة القاعدة: source_key فريد = «pass:{pass}:u:{user}:uqp:{user_quest_progress}»؛ تكرار الاستدعاء/السباق لا يمنح مرتين.
 *  - سقف زراعة: max_completions لكل مهمة/لاعب (فترات يومية/أسبوعية متعاقبة).
 *  - نافذة الاحتساب [starts_at, ends_at) بوقت الإكمال الفعلي؛ لا نقاط جديدة بعد النهاية. الاستعادة (healFor) تُكمل ما فاته خلل مؤقت لإكمالات داخل النافذة فقط.
 *  - pass_points = مجموع الدفتر دائمًا (لا زيادة عمياء) فيصحّح نفسه، وhighest_tier حتمي من العتبات التراكمية.
 *  - الحساب المجمَّد لا يكسب.
 */
class PassPointService
{
    public function __construct(
        protected PassLifecycleService $lifecycle,
        protected PassDefinitionCache $cache,
        protected PassProgressService $progress,
    ) {}

    /** الخطّاف الحيّ: يُستدعى من QuestService بعد إكمال المهمة. لا يرمي أبدًا لمن يستدعيه (المستدعي يلتقط على أي حال). */
    public function onQuestCompleted(User $user, UserQuestProgress $questProgress): ?PassPointGrant
    {
        if (! (bool) config('ahjiyat_pass.enabled', true) || $questProgress->completed_at === null) {
            return null;
        }

        $pass = $this->lifecycle->current();

        if ($pass === null) {
            return null;
        }

        $track = $this->cache->track($pass);
        $mission = $track->mission((int) $questProgress->quest_definition_id);

        if ($mission === null) {
            return null;
        }

        return $this->grant($user, $pass, $track, $mission, $questProgress);
    }

    /**
     * استعادة ذاتية: يمنح ما فاته (إكمالات داخل نافذة المسار بلا دفتر). آمن للتكرار. يعمل خلال الفعّال ومهلة الاستلام.
     *
     * @return int عدد المنح الجديدة
     */
    public function healFor(User $user, AhjiyatPass $pass): int
    {
        if (! (bool) config('ahjiyat_pass.enabled', true) || $user->is_frozen || ! $pass->isClaimWindowOpen()) {
            return 0;
        }

        $track = $this->cache->track($pass);
        $questIds = array_keys($track->missions);

        if ($questIds === []) {
            return 0;
        }

        $completed = UserQuestProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('quest_definition_id', $questIds)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $pass->starts_at)
            ->where('completed_at', '<', $pass->ends_at)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        if ($completed->isEmpty()) {
            return 0;
        }

        $already = PassPointGrant::query()
            ->where('ahjiyat_pass_id', $pass->getKey())
            ->whereIn('user_quest_progress_id', $completed->pluck('id'))
            ->pluck('user_quest_progress_id')
            ->flip();

        $granted = 0;

        foreach ($completed as $row) {
            if ($already->has($row->id)) {
                continue;
            }

            try {
                if ($this->grant($user, $pass, $track, $track->mission((int) $row->quest_definition_id) ?? [], $row) !== null) {
                    $granted++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $granted;
    }

    public static function sourceKey(AhjiyatPass|int $pass, User|int $user, UserQuestProgress|int $questProgress): string
    {
        return 'pass:'.($pass instanceof AhjiyatPass ? $pass->getKey() : $pass)
            .':u:'.($user instanceof User ? $user->getKey() : $user)
            .':uqp:'.($questProgress instanceof UserQuestProgress ? $questProgress->getKey() : $questProgress);
    }

    /** @param array<string, mixed> $mission */
    protected function grant(User $user, AhjiyatPass $pass, PassTrack $track, array $mission, UserQuestProgress $questProgress): ?PassPointGrant
    {
        if ($mission === [] || $user->is_frozen || (int) $questProgress->user_id !== (int) $user->getKey()) {
            return null;
        }

        $completedAt = $questProgress->completed_at;

        if ($completedAt === null || $completedAt->lt($pass->starts_at) || $completedAt->gte($pass->ends_at)) {
            return null; // خارج نافذة الاحتساب: لا نقاط جديدة بعد النهاية ولا قبل البدء.
        }

        $key = self::sourceKey($pass, $user, $questProgress);

        if (PassPointGrant::query()->where('source_key', $key)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $pass, $track, $mission, $questProgress, $key) {
                $progress = $this->progress->ensure($user, $pass);
                $locked = UserPassProgress::query()->whereKey($progress->getKey())->lockForUpdate()->firstOrFail();

                $taken = PassPointGrant::query()
                    ->where('user_pass_progress_id', $locked->getKey())
                    ->where('ahjiyat_pass_mission_id', $mission['id'])
                    ->count();

                if ($taken >= (int) $mission['max_completions']) {
                    return null;
                }

                $grant = new PassPointGrant;
                $grant->forceFill([
                    'user_pass_progress_id' => $locked->getKey(),
                    'user_id' => $user->getKey(),
                    'ahjiyat_pass_id' => $pass->getKey(),
                    'ahjiyat_pass_mission_id' => $mission['id'],
                    'user_quest_progress_id' => $questProgress->getKey(),
                    'source_type' => PassPointGrant::SOURCE_MISSION,
                    'source_key' => $key,
                    'points' => (int) $mission['points'],
                ])->save();

                $total = (int) PassPointGrant::query()->where('user_pass_progress_id', $locked->getKey())->sum('points');
                $locked->forceFill([
                    'pass_points' => $total,
                    'highest_tier' => $track->tierAt($total),
                    'last_progress_at' => now(),
                ])->save();

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // المصدر نفسه سُجّل من طلب متزامن: لا منح ثانٍ.
        }
    }
}
