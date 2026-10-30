<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\SeasonLifecycleService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - إنهاء ترتيب «داعمو الموسم»: لقطة **ثابتة** للمرتبة/النقاط/مستوى البطاقة العلني، تُكتب مرة واحدة بعد انتهاء الموسم (الترتيب يبقى مفتوحًا حتى نهايته).
 *
 * معاملة واحدة: قفل صف الإعدادات حصريًا ⇒ قفل **كل** صفوف الإحصاء بترتيب المعرّف (ينتظر أي منح جارٍ ليُنهي كتابته؛ ومنحٌ لاحق يرى علم الإنهاء فيُرفض)
 * ⇒ ترتيب المؤهَّلين علنيًا (نفس استعلام اللوحة) ⇒ كتابة final_rank لأعلى {leaderboard_limit} فقط، وfinal_points (= النقاط العلنية المرتَّب بها) وfinal_card_tier_key (أعلى مستوى **علني**) ووقت الإنهاء لكل الصفوف.
 *  - العكس المتأخر (استرداد بعد الإنهاء) يعدّل المجاميع الحيّة فقط ولا يمسّ اللقطة (نموذج الإحصاء يمنع ذلك).
 *  - إعادة التشغيل: already=true بلا أثر. إشعار المرتّبين بعد commit بعزل الفشل (مفتاح idempotency دلالي).
 *  - نقاط الدعم للتقدير فقط؛ لا مكافأة مالية ولا استحقاق يتولد من الترتيب.
 */
class SeasonSupporterFinalizer
{
    public const PERMISSION = 'seasonal-recognition.finalize';

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonLifecycleService $lifecycle,
        protected SeasonSupporterLeaderboardService $leaderboard,
        protected SeasonalRecognitionNotifier $notifier,
        protected SeasonalRecognitionBroadcaster $broadcaster,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * معاينة (قراءة فقط): هل يمكن الإنهاء الآن، وأعلى المرتّبين.
     *
     * @return array{can_finalize: bool, already: bool, blockers: list<string>, ranked: int, top: list<array<string, mixed>>}
     */
    public function preview(Season $season): array
    {
        $setting = $this->settings->settingFor($season);
        $blockers = [];

        if ($setting?->supporters_finalized_at !== null) {
            return ['can_finalize' => false, 'already' => true, 'blockers' => ['انتهى ترتيب الداعمين.'], 'ranked' => 0, 'top' => []];
        }

        if (! $this->settings->supportersGloballyEnabled() || $setting === null || ! $setting->supporters_enabled) {
            $blockers[] = 'تقدير الداعمين غير مُفعَّل.';
        }

        if (! in_array($this->lifecycle->state($season), [SeasonLifecycleService::STATE_ENDED, SeasonLifecycleService::STATE_ARCHIVED], true)) {
            $blockers[] = 'الموسم لم ينتهِ بعد؛ يبقى الترتيب مفتوحًا حتى نهايته.';
        }

        $limit = max(1, (int) config('seasonal_recognition.supporters.leaderboard_limit', 100));
        $rows = $this->leaderboard->eligibleQuery((int) $season->id)->limit($limit)->get(['s.user_id', 'u.name', 's.public_points_total as points']);

        return [
            'can_finalize' => $blockers === [],
            'already' => false,
            'blockers' => $blockers,
            'ranked' => $rows->count(),
            'top' => $rows->take(10)->values()->map(fn ($r, $i) => ['rank' => $i + 1, 'name' => (string) $r->name, 'points' => (int) $r->points])->all(),
        ];
    }

    /**
     * @return array{finalized: bool, already: bool, ranked: int, rows: int}
     *
     * @throws SeasonalRecognitionException
     */
    public function finalize(Season $season, User $actor): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إنهاء ترتيب الداعمين.');
        }

        $this->settings->ensure($season);

        // كل ما يلزم من قراءات عادية يُجهَّز **قبل** المعاملة: في MySQL (REPEATABLE READ) أول قراءة عادية داخل المعاملة تُثبّت لقطتها، ولو جاءت قبل قفل صفوف الإحصاء
        // (الذي ينتظر منحًا جاريًا) لرأى استعلام الترتيب لقطة أقدم من النقاط التي يختمها المُنهي (ترتيب لا يطابق final_points). الاستعلام الأول داخل المعاملة هو القفل نفسه.
        $season->loadMissing('campaign');
        $state = $this->lifecycle->state($season);

        return DB::transaction(function () use ($season, $actor, $state): array {
            /** @var SeasonRecognitionSetting $setting */
            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->firstOrFail();

            if ($setting->supporters_finalized_at !== null) {
                return ['finalized' => false, 'already' => true, 'ranked' => 0, 'rows' => 0];
            }

            if (! $this->settings->supportersGloballyEnabled() || ! $setting->supporters_enabled) {
                throw new SeasonalRecognitionException('supporters_disabled', 'تقدير الداعمين غير مُفعَّل.');
            }

            if (! in_array($state, [SeasonLifecycleService::STATE_ENDED, SeasonLifecycleService::STATE_ARCHIVED], true)) {
                throw new SeasonalRecognitionException('season_not_ended', 'لا يُنهى ترتيب الداعمين قبل نهاية الموسم.');
            }

            // قفل كل صفوف الإحصاء (ينتظر منحًا جاريًا ويحجب إدراج صفوف جديدة بقفل الفجوة) ثم القراءة العادية للترتيب: لقطتها تُؤخذ هنا بعد القفل فترى كل ما التُزم به.
            $stats = SeasonSupporterStat::query()->where('season_id', $season->id)->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');

            $limit = max(1, (int) config('seasonal_recognition.supporters.leaderboard_limit', 100));
            $ranked = $this->leaderboard->eligibleQuery((int) $season->id)->limit($limit)->pluck('s.user_id')->map(fn ($id) => (int) $id)->values();
            $position = $ranked->flip()->map(fn ($i) => (int) $i + 1);

            $publicTiers = $this->publicTiers((int) $season->id);
            $now = now();

            foreach ($stats as $userId => $stat) {
                $stat->forceFill([
                    'final_rank' => $position->get((int) $userId),
                    'final_points' => (int) $stat->public_points_total,
                    'final_card_tier_key' => $publicTiers[(int) $userId] ?? null,
                    'finalized_at' => $now,
                ])->save();
            }

            $setting->forceFill(['supporters_finalized_at' => $now, 'supporters_finalized_by' => $actor->id])->save();

            $this->audit->log('seasonal_supporters_finalized', $setting, ['season' => $season->slug, 'rows' => $stats->count(), 'ranked' => $ranked->count()], $actor);

            $seasonId = (int) $season->id;
            $rankedIds = $ranked->all();

            DB::afterCommit(function () use ($seasonId, $season, $rankedIds): void {
                SeasonalRecognitionCache::bump($seasonId, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
                $this->broadcaster->supportersUpdated($seasonId);
                $this->notifyRanked($season, $rankedIds);
            });

            return ['finalized' => true, 'already' => false, 'ranked' => $ranked->count(), 'rows' => $stats->count()];
        });
    }

    /**
     * أعلى مستوى علني (بطاقة نشطة غير مجهولة) لكل مستخدم في الموسم - استعلام واحد للموسم كله.
     *
     * @return array<int, string>
     */
    protected function publicTiers(int $seasonId): array
    {
        $best = [];

        SeasonSupporterCard::query()->where('season_id', $seasonId)->where('status', SeasonSupporterCard::STATUS_ACTIVE)->where('is_anonymous', false)
            ->get(['user_id', 'tier_key'])
            ->each(function (SeasonSupporterCard $card) use (&$best): void {
                $uid = (int) $card->user_id;

                if (SeasonSupporterPackage::rankOf($card->tier_key) > SeasonSupporterPackage::rankOf($best[$uid] ?? null)) {
                    $best[$uid] = $card->tier_key;
                }
            });

        return $best;
    }

    /** @param  list<int>  $rankedIds */
    protected function notifyRanked(Season $season, array $rankedIds): void
    {
        try {
            SeasonSupporterStat::query()->where('season_id', $season->id)->whereIn('user_id', $rankedIds)->whereNotNull('final_rank')->orderBy('id')->get()
                ->each(fn (SeasonSupporterStat $stat) => $this->notifier->supporterFinalRank($stat, $season));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
