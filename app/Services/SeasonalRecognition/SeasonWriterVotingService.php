<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterStarAllocation;
use App\Models\SeasonWriterVoteBudget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E44 - توزيع نجوم الموسم على الكتّاب. **كل شيء يحسمه الخادم** داخل معاملة واحدة؛ حسابات الواجهة للعرض فقط.
 *
 * ميزانية واحدة لكل حساب في الموسم (10 نجوم افتراضيًا، لا تتضاعف بتعدد الصفات، ولا تُلزم الناخب بصرفها كلها). تُنشأ كسولًا عند أول تصويت صالح.
 * التوزيع لكل كاتب 0..10 بشرط مجموع ما يوزَّعه الناخب ≤ ميزانيته؛ إعادة التوزيع قبل الإغلاق مسموحة، وبعده (أو بعد الإنهاء) ثابت. الصفر يحذف التوزيع.
 *
 * ترتيب الأقفال (ثابت لتفادي الجمود): (1) صف ميزانية الناخب ⇒ (2) صف إعدادات الموسم **بقفل مشترك** (يسلسل الإغلاق/الإنهاء/تعديل القائمة التي تأخذه حصريًا)
 *   ⇒ (3) صف التوزيع ⇒ (4) صف الكاتب. المُنهي يأخذ القفل الحصري على الإعدادات ثم الكتّاب بترتيب المعرّف، فلا دورة انتظار.
 *   تصويتان لناخب واحد يتسلسلان عند (1)؛ ناخبان على كاتب واحد يتسلسلان عند (4) ولا يشتركان بأي قفل سابق. قيود الفرادة خط الدفاع الأخير.
 * المجاميع الحيّة (stars_total/voters_count/first_star_at/last_star_at) تُحدَّث ذريًا داخل المعاملة نفسها (دفتر التوزيع هو السلطة؛ المطابقة تعيد البناء).
 * بعد commit فقط: إبطال الكاش وبثّ إشارة تجميعية آمنة (فشلهما لا يمسّ الصوت المحفوظ).
 */
class SeasonWriterVotingService
{
    /** إعادة محاولة المعاملة الخارجية عند جمود نادر (1213): تُلغى كلها وتُعاد بلا أثر جانبي (البثّ والكاش بعد commit). تُتجاهل داخل معاملة أب. */
    public const DEADLOCK_RETRIES = 3;

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonWriterEligibilityService $eligibility,
        protected SeasonalRecognitionBroadcaster $broadcaster,
    ) {}

    /**
     * يضبط توزيع الناخب على كاتب (القيمة النهائية لا الفرق). 0 يسحب التوزيع.
     *
     * @return array{stars: int, delta: int, spent: int, remaining: int, total: int, writer_stars_total: int, writer_voters: int}
     *
     * @throws SeasonalRecognitionException
     */
    public function allocate(User $voter, SeasonWriterEntry $entry, int $stars): array
    {
        if ($stars < 0 || $stars > SeasonRecognitionSetting::HARD_MAX_STARS) {
            throw new SeasonalRecognitionException('invalid_stars', 'عدد النجوم بين 0 و'.SeasonRecognitionSetting::HARD_MAX_STARS.'.');
        }

        $season = Season::query()->findOrFail($entry->season_id);

        // فحص سريع بلا أقفال: لا نُنشئ صف ميزانية لطلب محكوم عليه بالرفض. (قراءة الإعدادات هنا خارج المعاملة عمدًا: أول قراءة عادية داخل معاملة MySQL تُثبّت لقطتها،
        // ويجب أن تأتي أول قراءة عادية داخل المعاملة بعد قفل ميزانية الناخب كي ترى توزيعاته الأخيرة.)
        $initial = $this->settings->settingFor($season);
        $this->assertOpen($season, $initial);
        $this->assertEligible($voter, $entry);

        $result = DB::transaction(function () use ($voter, $entry, $season, $stars, $initial) {
            // upsert لا INSERT IGNORE: الثاني يأخذ قفلًا مشتركًا على الصف الموجود ثم يُرقَّى إلى حصري أدناه فيتجمّد طلبان متزامنان لناخب واحد (1213 في مسبار التزامن).
            // total_stars لا يُمسّ بعد الإنشاء (لقطة أول تصويت)؛ يُلمس updated_at فقط.
            DB::table('season_writer_vote_budgets')->upsert([[
                'season_id' => $season->id,
                'user_id' => $voter->id,
                'total_stars' => (int) $initial->writers_stars_per_voter,
                'spent_stars' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['season_id', 'user_id'], ['updated_at']);

            /** @var SeasonWriterVoteBudget $budget */
            $budget = SeasonWriterVoteBudget::query()->where('season_id', $season->id)->where('user_id', $voter->id)->lockForUpdate()->firstOrFail();

            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->sharedLock()->firstOrFail();
            $this->assertOpen($season, $setting);

            // قراءة عادية بلا قفل عمدًا: توزيعات الناخب لا يغيّرها غير طلباته نفسها وهي متسلسلة بقفل ميزانيته أعلاه، وقراءة FOR UPDATE لصف غير موجود تأخذ قفل فجوة
            // يتشاركه ناخبان على فجوة الفهرس نفسها ثم يتعارضان عند الإدراج (جمود 1213). القراءة تأتي بعد قفل الميزانية فترى آخر ما التُزم به.
            /** @var SeasonWriterStarAllocation|null $allocation */
            $allocation = SeasonWriterStarAllocation::query()->where('writer_entry_id', $entry->id)->where('voter_user_id', $voter->id)->first();

            /** @var SeasonWriterEntry $locked */
            $locked = SeasonWriterEntry::query()->whereKey($entry->id)->where('season_id', $season->id)->lockForUpdate()->firstOrFail();

            $this->assertEligible($voter, $locked);

            $old = (int) ($allocation?->stars ?? 0);
            $delta = $stars - $old;

            if ($delta > 0 && $locked->status !== SeasonWriterEntry::STATUS_ACTIVE) {
                throw new SeasonalRecognitionException('entry_inactive', 'هذا الكاتب مسحوب من القائمة؛ يمكنك سحب نجومك منه فقط.');
            }

            $spent = (int) $budget->spent_stars + $delta;

            if ($spent > (int) $budget->total_stars) {
                throw new SeasonalRecognitionException('budget_exceeded', 'تجاوزتَ ميزانية نجومك في هذا الموسم ('.(int) $budget->total_stars.'). اسحب نجومًا من كاتب آخر أولًا.');
            }

            if ($spent < 0) {
                throw new SeasonalRecognitionException('budget_inconsistent', 'تعذّر ضبط الميزانية الآن. أعد المحاولة أو تواصل مع الإدارة.');
            }

            if ($delta !== 0) {
                if ($stars === 0) {
                    $allocation?->delete();
                } elseif ($allocation !== null) {
                    $allocation->forceFill(['stars' => $stars])->save();
                } else {
                    $allocation = new SeasonWriterStarAllocation;
                    $allocation->forceFill([
                        'season_id' => $season->id,
                        'writer_entry_id' => $locked->id,
                        'voter_user_id' => $voter->id,
                        'stars' => $stars,
                    ])->save();
                }

                $total = (int) $locked->stars_total + $delta;
                $voters = (int) $locked->voters_count + ($old === 0 ? 1 : 0) - ($stars === 0 ? 1 : 0);

                $locked->forceFill([
                    'stars_total' => max(0, $total),
                    'voters_count' => max(0, $voters),
                    'first_star_at' => $locked->first_star_at ?? ($total > 0 ? now() : null),
                    'last_star_at' => now(),
                ])->save();

                $budget->forceFill(['spent_stars' => $spent])->save();
            }

            return [
                'stars' => $stars,
                'delta' => $delta,
                'spent' => $spent,
                'remaining' => (int) $budget->total_stars - $spent,
                'total' => (int) $budget->total_stars,
                'writer_stars_total' => (int) $locked->stars_total,
                'writer_voters' => (int) $locked->voters_count,
                'entry_public_id' => (string) $locked->public_id,
            ];
        }, self::DEADLOCK_RETRIES);

        if ($result['delta'] !== 0) {
            $seasonId = (int) $season->id;
            $entryPublicId = $result['entry_public_id'];
            $starsTotal = $result['writer_stars_total'];
            $voters = $result['writer_voters'];

            DB::afterCommit(function () use ($seasonId, $entryPublicId, $starsTotal, $voters) {
                SeasonalRecognitionCache::bump($seasonId, SeasonalRecognitionCache::SCOPE_WRITERS);
                $this->broadcaster->writersUpdated($seasonId, $entryPublicId, $starsTotal, $voters);
            });
        }

        unset($result['entry_public_id']);

        return $result;
    }

    /**
     * لوحة الناخب: ميزانيته الحالية وتوزيعاته ومن يحق له التصويت لهم (للعرض فقط؛ الحسم عند allocate).
     *
     * @return array{state: string, open: bool, total: int, spent: int, remaining: int, allocations: array<int, int>, votable: list<int>}
     */
    public function panel(User $voter, Season $season): array
    {
        $setting = $this->settings->settingFor($season);
        $state = $this->settings->votingState($season, $setting);
        $budget = SeasonWriterVoteBudget::query()->where('season_id', $season->id)->where('user_id', $voter->id)->first(['total_stars', 'spent_stars']);
        $total = (int) ($budget?->total_stars ?? $setting?->writers_stars_per_voter ?? config('seasonal_recognition.writers.stars_per_voter', 10));
        $spent = (int) ($budget?->spent_stars ?? 0);

        return [
            'state' => $state,
            'open' => $this->settings->writersGloballyEnabled() && $state === SeasonRecognitionSettingsService::STATE_OPEN,
            'total' => $total,
            'spent' => $spent,
            'remaining' => max(0, $total - $spent),
            'allocations' => SeasonWriterStarAllocation::query()->where('season_id', $season->id)->where('voter_user_id', $voter->id)->pluck('stars', 'writer_entry_id')->map(fn ($v) => (int) $v)->all(),
            'votable' => $this->eligibility->votableEntryIds($voter, $season),
        ];
    }

    /** @throws SeasonalRecognitionException */
    protected function assertOpen(Season $season, ?SeasonRecognitionSetting $setting): void
    {
        if (! $this->settings->writersGloballyEnabled() || $setting === null || ! $setting->writers_enabled) {
            throw new SeasonalRecognitionException('writers_disabled', 'دوري الكتّاب غير مُفعَّل الآن.');
        }

        match ($this->settings->votingState($season, $setting)) {
            SeasonRecognitionSettingsService::STATE_OPEN => null,
            SeasonRecognitionSettingsService::STATE_FINALIZED => throw new SeasonalRecognitionException('finalized', 'انتهى الدوري وصدر الترتيب النهائي.'),
            SeasonRecognitionSettingsService::STATE_UPCOMING => throw new SeasonalRecognitionException('not_open_yet', 'لم يُفتح التصويت بعد.'),
            SeasonRecognitionSettingsService::STATE_CLOSED => throw new SeasonalRecognitionException('closed', 'أُغلق التصويت.'),
            default => throw new SeasonalRecognitionException('not_configured', 'نافذة التصويت غير مضبوطة بعد.'),
        };
    }

    /** @throws SeasonalRecognitionException */
    protected function assertEligible(User $voter, SeasonWriterEntry $entry): void
    {
        $denial = $this->eligibility->denial($voter, $entry);

        if ($denial !== null) {
            throw new SeasonalRecognitionException($denial['reason'], $denial['message']);
        }
    }
}
