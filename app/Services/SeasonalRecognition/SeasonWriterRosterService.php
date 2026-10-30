<?php

namespace App\Services\SeasonalRecognition;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterSeries;
use App\Models\User;
use App\Services\Creators\CreatorEligibility;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E44 - قائمة كتّاب الموسم وربط سلاسلهم (إدارة فقط، صلاحية seasonal-recognition.manage داخل الخدمة).
 *
 * الكاتب = ملف مبدع **معتمد** اختير لموسم بعينه (حالة مشاركة موسمية لا دور عام). القواعد (كلها على الخادم، تحت قفل صف إعدادات الموسم الحصري الذي يسلسل التعديلات المتزامنة):
 *  - السعة الافتراضية 20 وتُرفع من الإعدادات، والحدّ الصلب المطلق **100** لا يتجاوزه موسم أبدًا (ويفرضه النموذج وقاعدة المطابقة أيضًا).
 *  - مبدع واحد مرة واحدة في قائمة الموسم (فرادة قاعدة البيانات خط الدفاع الأخير).
 *  - ملف معتمد + حساب سليم (CreatorEligibility) + غير مستبعد بقاعدة التدوير (الموسم التالي المباشر فقط).
 *  - تُقفل القائمة عند فتح التصويت: لا إضافة بعد ذلك؛ الحذف قبل الفتح (بلا نجوم) والسحب (withdrawn) بعده - بلا إعادة كتابة تاريخ.
 *  - ربط السلاسل: سلسلة المبدع نفسه، منشورة وغير مخفية/مؤرشفة؛ يُسمح بالربط حتى الإغلاق ولا يُلغى بعد فتح التصويت (حتى لا تُبطل أهلية ناخبين).
 * كل تغيير يُدقَّق (OperationalAuditService). تعطيل المفتاح يمنع التعديلات الجديدة فقط.
 */
class SeasonWriterRosterService
{
    public const PERMISSION = 'seasonal-recognition.manage';

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonWriterRotationService $rotation,
        protected CreatorEligibility $eligibility,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * @throws SeasonalRecognitionException
     */
    public function addWriter(Season $season, CreatorProfile $profile, User $actor): SeasonWriterEntry
    {
        $this->authorize($actor);

        $this->settings->ensure($season);

        try {
            $entry = DB::transaction(function () use ($season, $profile, $actor) {
                $setting = $this->lockSetting($season);
                $this->assertEditable($season, $setting);

                $state = $this->settings->votingState($season, $setting);

                if (in_array($state, [SeasonRecognitionSettingsService::STATE_OPEN, SeasonRecognitionSettingsService::STATE_CLOSED], true)) {
                    throw new SeasonalRecognitionException('roster_locked', 'القائمة مقفلة بعد فتح التصويت.');
                }

                $owner = User::query()->find($profile->user_id);
                $denial = $owner === null ? 'حساب المبدع غير موجود.' : $this->eligibility->studioDenial($owner);

                if ($profile->status !== CreatorProfile::STATUS_APPROVED || $denial !== null) {
                    throw new SeasonalRecognitionException('creator_not_eligible', 'لا يُضاف إلا مبدع معتمد بحساب سليم'.($denial !== null ? ' ('.$denial.')' : '').'.');
                }

                if ($this->rotation->isExcluded($season, $profile)) {
                    throw new SeasonalRecognitionException('rotation_excluded', 'هذا المبدع مستبعد من قائمة هذا الموسم وفق قاعدة الدوري (الموسم التالي فقط).');
                }

                $count = SeasonWriterEntry::query()->where('season_id', $season->id)->count();
                $limit = min(SeasonRecognitionSetting::HARD_MAX_CAPACITY, (int) $setting->writers_capacity);

                if ($count >= $limit) {
                    throw new SeasonalRecognitionException('capacity_full', $limit >= SeasonRecognitionSetting::HARD_MAX_CAPACITY
                        ? 'بلغت القائمة الحد الأقصى المطلق (100 كاتب).'
                        : "امتلأت قائمة الموسم ({$limit}). ارفع السعة من الإعدادات أولًا.");
                }

                $entry = new SeasonWriterEntry;
                $entry->forceFill([
                    'season_id' => $season->id,
                    'creator_profile_id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'status' => SeasonWriterEntry::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'added_by' => $actor->id,
                ])->save();

                $this->audit->log('seasonal_writer_added', $entry, ['season' => $season->slug, 'creator' => $profile->slug], $actor);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SeasonalRecognitionException('duplicate', 'هذا المبدع موجود في قائمة الموسم أصلًا.');
        }

        SeasonalRecognitionCache::bump($season->id, SeasonalRecognitionCache::SCOPE_WRITERS);

        return $entry;
    }

    /**
     * قبل فتح التصويت وبلا نجوم: حذف حقيقي (مع روابط سلاسله). بعده: سحب (withdrawn) يُبقي التاريخ ويُخرج الكاتب من الترتيب العلني والإنهاء.
     *
     * @throws SeasonalRecognitionException
     */
    public function removeWriter(SeasonWriterEntry $entry, User $actor): string
    {
        $this->authorize($actor);

        $season = Season::query()->findOrFail($entry->season_id);

        $result = DB::transaction(function () use ($season, $entry, $actor) {
            $setting = $this->lockSetting($season);
            $this->assertEditable($season, $setting);

            $locked = SeasonWriterEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $state = $this->settings->votingState($season, $setting);
            $started = in_array($state, [SeasonRecognitionSettingsService::STATE_OPEN, SeasonRecognitionSettingsService::STATE_CLOSED], true);

            if (! $started && (int) $locked->stars_total === 0 && (int) $locked->voters_count === 0) {
                $this->audit->log('seasonal_writer_removed', $locked, ['season' => $season->slug, 'creator_profile_id' => $locked->creator_profile_id], $actor);
                SeasonWriterSeries::query()->where('writer_entry_id', $locked->id)->delete();
                $locked->delete();

                return 'removed';
            }

            if ($locked->status === SeasonWriterEntry::STATUS_WITHDRAWN) {
                return 'withdrawn';
            }

            $locked->forceFill(['status' => SeasonWriterEntry::STATUS_WITHDRAWN])->save();
            $this->audit->log('seasonal_writer_withdrawn', $locked, ['season' => $season->slug, 'stars_total' => $locked->stars_total], $actor);

            return 'withdrawn';
        });

        SeasonalRecognitionCache::bump($season->id, SeasonalRecognitionCache::SCOPE_WRITERS);

        return $result;
    }

    /**
     * @throws SeasonalRecognitionException
     */
    public function linkSeries(SeasonWriterEntry $entry, CreatorSeries $series, User $actor): SeasonWriterSeries
    {
        $this->authorize($actor);

        $season = Season::query()->findOrFail($entry->season_id);

        try {
            $link = DB::transaction(function () use ($season, $entry, $series, $actor) {
                $setting = $this->lockSetting($season);
                $this->assertEditable($season, $setting);

                if ($this->settings->votingState($season, $setting) === SeasonRecognitionSettingsService::STATE_CLOSED) {
                    throw new SeasonalRecognitionException('voting_closed', 'أُغلق التصويت؛ لا تُربط سلاسل جديدة.');
                }

                $lockedEntry = SeasonWriterEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

                if ($lockedEntry->status !== SeasonWriterEntry::STATUS_ACTIVE) {
                    throw new SeasonalRecognitionException('entry_inactive', 'الكاتب مسحوب من القائمة.');
                }

                if ((int) $series->creator_profile_id !== (int) $lockedEntry->creator_profile_id) {
                    throw new SeasonalRecognitionException('series_not_owned', 'السلسلة ليست للكاتب نفسه.');
                }

                if ($series->published_revision_id === null || $series->hidden_at !== null || $series->archived_at !== null) {
                    throw new SeasonalRecognitionException('series_not_public', 'تُربط سلسلة منشورة وغير مخفية أو مؤرشفة فقط.');
                }

                $linked = SeasonWriterSeries::query()->where('writer_entry_id', $lockedEntry->id)->count();

                if ($linked >= max(1, (int) config('seasonal_recognition.writers.max_linked_series', 10))) {
                    throw new SeasonalRecognitionException('too_many_series', 'بلغ الكاتب الحد الأقصى للسلاسل المربوطة بالموسم.');
                }

                $link = new SeasonWriterSeries;
                $link->forceFill([
                    'writer_entry_id' => $lockedEntry->id,
                    'creator_series_id' => $series->id,
                    'linked_by' => $actor->id,
                    'linked_at' => now(),
                ])->save();

                $this->audit->log('seasonal_writer_series_linked', $lockedEntry, ['season' => $season->slug, 'series' => $series->public_id], $actor);

                return $link;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SeasonalRecognitionException('duplicate_link', 'هذه السلسلة مربوطة بالكاتب أصلًا.');
        }

        SeasonalRecognitionCache::bump($season->id, SeasonalRecognitionCache::SCOPE_WRITERS);

        return $link;
    }

    /**
     * @throws SeasonalRecognitionException
     */
    public function unlinkSeries(SeasonWriterEntry $entry, CreatorSeries $series, User $actor): void
    {
        $this->authorize($actor);

        $season = Season::query()->findOrFail($entry->season_id);

        DB::transaction(function () use ($season, $entry, $series, $actor) {
            $setting = $this->lockSetting($season);
            $this->assertEditable($season, $setting);

            $state = $this->settings->votingState($season, $setting);

            if (in_array($state, [SeasonRecognitionSettingsService::STATE_OPEN, SeasonRecognitionSettingsService::STATE_CLOSED], true)) {
                throw new SeasonalRecognitionException('unlink_locked', 'لا يُلغى ربط سلسلة بعد فتح التصويت (حفاظًا على أهلية الناخبين).');
            }

            $deleted = SeasonWriterSeries::query()->where('writer_entry_id', $entry->id)->where('creator_series_id', $series->id)->get();

            foreach ($deleted as $link) {
                $link->delete();
            }

            if ($deleted->isNotEmpty()) {
                $this->audit->log('seasonal_writer_series_unlinked', $entry, ['season' => $season->slug, 'series' => $series->public_id], $actor);
            }
        });

        SeasonalRecognitionCache::bump($season->id, SeasonalRecognitionCache::SCOPE_WRITERS);
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة قائمة الكتّاب.');
        }
    }

    protected function lockSetting(Season $season): SeasonRecognitionSetting
    {
        return SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->firstOrFail();
    }

    /** المفتاح العام ومفتاح الموسم يسمحان بإجراءات جديدة، والدوري المُنهى لا يتغير. */
    protected function assertEditable(Season $season, SeasonRecognitionSetting $setting): void
    {
        if ($setting->writers_finalized_at !== null) {
            throw new SeasonalRecognitionException('finalized', 'انتهى ترتيب الدوري؛ القائمة مجمَّدة.');
        }

        if (! $this->settings->writersActive($season, $setting)) {
            throw new SeasonalRecognitionException('writers_disabled', 'دوري الكتّاب غير مُفعَّل لهذا الموسم.');
        }
    }
}
