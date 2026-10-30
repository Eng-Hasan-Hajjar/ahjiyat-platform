<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterAward;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - إنهاء دوري الكتّاب: **حدث صريح وحيد** متكرر الأمان يكتب لقطة الترتيب النهائي مرة واحدة ولا تتغير بعدها (لا أثر رجعي لأي تصويت/تعديل لاحق).
 *
 * الشروط (كلها داخل معاملة تقفل صف الإعدادات حصريًا، فلا يمرّ صوت بين الفحص والكتابة):
 *   صلاحية seasonal-recognition.finalize ∧ المفتاح العام ومفتاح الموسم مفعّلان ∧ نافذة التصويت أُغلقت ∧ لا مخالفة في الدفتر مقابل المجاميع ∧ كاتب نشط واحد على الأقل.
 * النتيجة: final_rank تسلسلي 1..N بترتيب SeasonWriterLeaderboardService الحتمي، المراكز الأولى (حتى 3 بشرط نجوم ≥ الحدّ الأدنى)، ومن الأدنى ترتيبًا (عدد الاستبعاد المضبوط، افتراضيًا 5)
 *   خارج المنصّة بوسم ineligible_next_season: **قائمة الموسم التالي المباشر فقط** - ليس حظرًا ولا إيقافًا ولا إخفاءً (SeasonWriterRotationService). لا يُوسَم صاحب مركز.
 *   وقرارات الجوائز من لقطة الخطة (فرادة (كاتب، بند) ⇒ لا تضاعف). المالي التزام فقط؛ لا دفع ولا مساس برصيد (SeasonWriterAwardService).
 * بعد commit فقط وبعزل كل فشل: إبطال الكاش، بثّ الإنهاء، منح الجوائز التجميلية، إشعار الكتّاب. إعادة التشغيل لا تفعل شيئًا (already=true). redeliver() يعيد المنح/الإشعار الفائت بأمان.
 * التعطيل بعد الإنهاء لا يمسح شيئًا. لا تحويل أرباح من النجوم أبدًا.
 */
class SeasonWriterFinalizer
{
    public const PERMISSION = 'seasonal-recognition.finalize';

    /** رموز مخالفات الدفتر التي تمنع الإنهاء (المجاميع يجب أن تطابق الدفتر). */
    private const BLOCKING_CODES = ['entry_aggregate_mismatch', 'budget_mismatch', 'allocation_without_budget', 'self_vote_allocation', 'allocation_cross_season'];

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonWriterLeaderboardService $leaderboard,
        protected SeasonWriterReconciler $reconciler,
        protected SeasonWriterAwardService $awards,
        protected SeasonalRecognitionNotifier $notifier,
        protected SeasonalRecognitionBroadcaster $broadcaster,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * معاينة الإنهاء (قراءة فقط): المراتب والنتائج المتوقعة وقرارات الجوائز وموانع الإنهاء.
     *
     * @return array{can_finalize: bool, already: bool, state: string, blockers: list<string>, rows: list<array<string, mixed>>, awards: list<array<string, mixed>>, eliminated_count: int}
     */
    public function preview(Season $season): array
    {
        $setting = $this->settings->settingFor($season);
        $state = $this->settings->votingState($season, $setting);
        $blockers = $this->blockers($season, $setting, $state);

        $entries = $this->leaderboard->orderedActiveQuery((int) $season->id)->with(['user:id,name'])->get();
        $outcomes = $this->assignOutcomes($entries->map(fn (SeasonWriterEntry $e) => (int) $e->stars_total)->all(), (int) ($setting?->writers_eliminated_count ?? 0));

        $rows = [];
        $awards = [];
        $plan = (array) ($setting?->writers_reward_plan ?? []);

        foreach ($entries as $i => $entry) {
            $outcome = $outcomes[$i];
            $rows[] = [
                'rank' => $i + 1,
                'public_id' => (string) $entry->public_id,
                'name' => (string) ($entry->user?->name ?? ''),
                'stars' => (int) $entry->stars_total,
                'voters' => (int) $entry->voters_count,
                'outcome' => $outcome,
            ];

            foreach ($plan as $index => $item) {
                if (($item['placement'] ?? null) === $outcome) {
                    $awards[] = ['rank' => $i + 1, 'name' => (string) ($entry->user?->name ?? ''), 'plan_index' => (int) $index, 'kind' => (string) $item['kind'], 'label' => (string) $item['label']];
                }
            }
        }

        return [
            'can_finalize' => $blockers === [],
            'already' => $setting?->writers_finalized_at !== null,
            'state' => $state,
            'blockers' => $blockers,
            'rows' => $rows,
            'awards' => $awards,
            'eliminated_count' => (int) ($setting?->writers_eliminated_count ?? 0),
        ];
    }

    /**
     * @return array{finalized: bool, already: bool, writers: int, podium: int, eliminated: int, awards: int}
     *
     * @throws SeasonalRecognitionException
     */
    public function finalize(Season $season, User $actor): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إنهاء دوري الكتّاب.');
        }

        $this->settings->ensure($season);

        // الحملة الأساسية (لنهاية الموسم الفعلية) تُحمَّل قبل المعاملة: أول قراءة عادية داخل معاملة MySQL تُثبّت لقطتها، ويجب أن تأتي بعد قفل الإعدادات والكتّاب لا قبله.
        $season->loadMissing('campaign');

        $result = DB::transaction(function () use ($season, $actor): array {
            /** @var SeasonRecognitionSetting $setting */
            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->firstOrFail();

            if ($setting->writers_finalized_at !== null) {
                return ['finalized' => false, 'already' => true, 'writers' => 0, 'podium' => 0, 'eliminated' => 0, 'awards' => 0];
            }

            $state = $this->settings->votingState($season, $setting);
            $this->assertNotBlocked($season, $setting, $state);

            // قفل الكتّاب بترتيب المعرّف (ترتيب التصويت نفسه: الإعدادات ثم الكتّاب) ثم الترتيب الحتمي على الصفوف المقفلة.
            SeasonWriterEntry::query()->where('season_id', $season->id)->orderBy('id')->lockForUpdate()->pluck('id');

            $blocking = array_values(array_filter($this->reconciler->inspect($season, 20), fn (array $f) => in_array($f['code'], self::BLOCKING_CODES, true)));

            if ($blocking !== []) {
                throw new SeasonalRecognitionException('ledger_mismatch', 'مجاميع الدوري لا تطابق دفتر التوزيعات. شغّل «إعادة بناء المجاميع» من صفحة المطابقة أولًا.');
            }

            $ordered = $this->leaderboard->orderedActiveQuery((int) $season->id)->get();

            if ($ordered->isEmpty()) {
                throw new SeasonalRecognitionException('no_writers', 'لا يوجد كتّاب نشطون لإنهاء ترتيبهم.');
            }

            $outcomes = $this->assignOutcomes($ordered->map(fn (SeasonWriterEntry $e) => (int) $e->stars_total)->all(), (int) $setting->writers_eliminated_count);
            $plan = (array) ($setting->writers_reward_plan ?? []);
            $now = now();
            $podium = 0;
            $eliminated = 0;
            $awardsCreated = 0;

            foreach ($ordered as $i => $entry) {
                $outcome = $outcomes[$i];

                $entry->forceFill([
                    'final_rank' => $i + 1,
                    'final_stars' => (int) $entry->stars_total,
                    'final_voters_count' => (int) $entry->voters_count,
                    'outcome' => $outcome,
                    'finalized_at' => $now,
                ])->save();

                if (in_array($outcome, SeasonWriterEntry::PODIUM, true)) {
                    $podium++;
                } elseif ($outcome === SeasonWriterEntry::OUTCOME_INELIGIBLE_NEXT) {
                    $eliminated++;
                }

                foreach ($plan as $index => $item) {
                    if (($item['placement'] ?? null) !== $outcome) {
                        continue;
                    }

                    $award = new SeasonWriterAward;
                    $award->forceFill([
                        'season_id' => $season->id,
                        'writer_entry_id' => $entry->id,
                        'user_id' => $entry->user_id,
                        'plan_index' => (int) $index,
                        'placement' => $outcome,
                        'kind' => (string) $item['kind'],
                        'label' => (string) $item['label'],
                        'store_item_id' => $item['kind'] === SeasonWriterAward::KIND_COSMETIC ? ($item['store_item_id'] ?? null) : null,
                        'amount_minor' => $item['kind'] === SeasonWriterAward::KIND_MONETARY ? (int) $item['amount_minor'] : null,
                        'currency' => $item['kind'] === SeasonWriterAward::KIND_MONETARY ? (string) $item['currency'] : null,
                        'status' => $item['kind'] === SeasonWriterAward::KIND_MONETARY ? SeasonWriterAward::STATUS_PENDING_FINANCE : SeasonWriterAward::STATUS_PLANNED,
                    ])->save();
                    $awardsCreated++;
                }
            }

            $setting->forceFill(['writers_finalized_at' => $now, 'writers_finalized_by' => $actor->id])->save();

            $this->audit->log('seasonal_writers_finalized', $setting, [
                'season' => $season->slug,
                'writers' => $ordered->count(),
                'podium' => $podium,
                'ineligible_next_season' => $eliminated,
                'awards' => $awardsCreated,
            ], $actor);

            $seasonId = (int) $season->id;

            DB::afterCommit(function () use ($seasonId, $season): void {
                SeasonalRecognitionCache::bump($seasonId, SeasonalRecognitionCache::SCOPE_WRITERS);
                $this->broadcaster->writersFinalized($seasonId);
                $this->deliver($season);
            });

            return ['finalized' => true, 'already' => false, 'writers' => $ordered->count(), 'podium' => $podium, 'eliminated' => $eliminated, 'awards' => $awardsCreated];
        });

        return $result;
    }

    /**
     * يعيد منح الجوائز التجميلية الفائتة وإرسال إشعارات الترتيب النهائي (كلاهما متكرر الأمان: حالة الجائزة ومفتاح الإشعار). لدوري مُنهى فقط.
     *
     * @return array{granted: int, failed: int}
     *
     * @throws SeasonalRecognitionException
     */
    public function redeliver(Season $season, User $actor): array
    {
        if (! $actor->can(SeasonWriterAwardService::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة جوائز الكتّاب.');
        }

        $setting = $this->settings->settingFor($season);

        if ($setting === null || $setting->writers_finalized_at === null) {
            throw new SeasonalRecognitionException('not_finalized', 'لم يُنهَ الدوري بعد.');
        }

        return $this->deliver($season, $actor);
    }

    /**
     * ترتيب النتائج من النجوم المرتبة تنازليًا (قائمة الأرقام مرتبة بالفعل): فهرس الصف ⇒ النتيجة.
     * المراكز: الرتب 1..3 بشرط نجوم ≥ min_stars_to_place. الاستبعاد: آخر K رتبة من غير أصحاب المراكز (لا يُستبعد صاحب مركز أبدًا).
     *
     * @param  list<int>  $starsByRank
     * @return list<string>
     */
    public function assignOutcomes(array $starsByRank, int $eliminatedCount): array
    {
        $places = max(0, min(3, (int) config('seasonal_recognition.writers.places', 3)));
        $minStars = max(0, (int) config('seasonal_recognition.writers.min_stars_to_place', 1));
        $n = count($starsByRank);
        $outcomes = array_fill(0, $n, SeasonWriterEntry::OUTCOME_PARTICIPANT);
        $podium = 0;

        for ($i = 0; $i < min($places, $n); $i++) {
            if ($starsByRank[$i] < $minStars) {
                break; // مرتبة تنازليًا: ما بعده أقل.
            }

            $outcomes[$i] = SeasonWriterEntry::PODIUM[$i + 1];
            $podium++;
        }

        $eliminate = min(max(0, $eliminatedCount), max(0, $n - $podium));

        for ($i = $n - $eliminate; $i < $n; $i++) {
            if ($outcomes[$i] === SeasonWriterEntry::OUTCOME_PARTICIPANT) {
                $outcomes[$i] = SeasonWriterEntry::OUTCOME_INELIGIBLE_NEXT;
            }
        }

        return $outcomes;
    }

    /** @return array{granted: int, failed: int} */
    protected function deliver(Season $season, ?User $actor = null): array
    {
        $summary = ['granted' => 0, 'failed' => 0];

        try {
            $summary = $this->awards->grantPendingCosmetics($season, null);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            SeasonWriterEntry::query()->where('season_id', $season->id)->whereNotNull('final_rank')->orderBy('id')->get()
                ->each(fn (SeasonWriterEntry $entry) => $this->notifier->writerFinalStanding($entry, $season));
        } catch (Throwable $e) {
            report($e);
        }

        return $summary;
    }

    /** @return list<string> */
    protected function blockers(Season $season, ?SeasonRecognitionSetting $setting, string $state): array
    {
        $blockers = [];

        if ($setting?->writers_finalized_at !== null) {
            return ['انتهى الدوري وصدر ترتيبه.'];
        }

        if (! $this->settings->writersGloballyEnabled() || $setting === null || ! $setting->writers_enabled) {
            $blockers[] = 'دوري الكتّاب غير مُفعَّل.';
        } elseif ($state !== SeasonRecognitionSettingsService::STATE_CLOSED) {
            $blockers[] = match ($state) {
                SeasonRecognitionSettingsService::STATE_OPEN => 'التصويت ما زال مفتوحًا؛ ينتهي بحلول موعد الإغلاق.',
                SeasonRecognitionSettingsService::STATE_UPCOMING => 'لم يبدأ التصويت بعد.',
                default => 'نافذة التصويت غير مضبوطة.',
            };
        }

        if (SeasonWriterEntry::query()->where('season_id', $season->id)->active()->doesntExist()) {
            $blockers[] = 'لا يوجد كتّاب نشطون.';
        }

        $blocking = array_filter($this->reconciler->inspect($season, 20), fn (array $f) => in_array($f['code'], self::BLOCKING_CODES, true));

        if ($blocking !== []) {
            $blockers[] = 'المجاميع لا تطابق دفتر التوزيعات (أعد بناءها من صفحة المطابقة).';
        }

        return $blockers;
    }

    /** @throws SeasonalRecognitionException */
    protected function assertNotBlocked(Season $season, SeasonRecognitionSetting $setting, string $state): void
    {
        if (! $this->settings->writersGloballyEnabled() || ! $setting->writers_enabled) {
            throw new SeasonalRecognitionException('writers_disabled', 'دوري الكتّاب غير مُفعَّل.');
        }

        match ($state) {
            SeasonRecognitionSettingsService::STATE_CLOSED => null,
            SeasonRecognitionSettingsService::STATE_OPEN => throw new SeasonalRecognitionException('voting_open', 'التصويت ما زال مفتوحًا؛ لا يُنهى قبل موعد الإغلاق.'),
            SeasonRecognitionSettingsService::STATE_UPCOMING => throw new SeasonalRecognitionException('not_open_yet', 'لم يبدأ التصويت بعد.'),
            default => throw new SeasonalRecognitionException('not_configured', 'نافذة التصويت غير مضبوطة.'),
        };
    }
}
