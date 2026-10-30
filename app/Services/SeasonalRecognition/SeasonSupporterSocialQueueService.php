<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupporterStat;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E44 - طابور التنويه الاجتماعي: **سجل إداري يدوي فقط** (eligible → queued → published | skipped). لا ربط بأي واجهة شبكة اجتماعية ولا نشر آلي ولا طلب خارجي.
 * الأهلية الآلية (eligible) تأتي من بطاقة ذهبية/ماسية **غير مجهولة** (SeasonSupporterCardService::refreshStat)؛ المجهول لا يدخل الطابور أبدًا.
 * الانتقالات المسموحة: eligible→queued|skipped، queued→published|skipped، skipped→eligible (إعادة)، published نهائي. الملاحظة اختيارية (حتى 300) وتُدقَّق.
 */
class SeasonSupporterSocialQueueService
{
    public const PERMISSION = 'seasonal-recognition.manage';

    private const TRANSITIONS = [
        SeasonSupporterStat::SOCIAL_ELIGIBLE => [SeasonSupporterStat::SOCIAL_QUEUED, SeasonSupporterStat::SOCIAL_SKIPPED],
        SeasonSupporterStat::SOCIAL_QUEUED => [SeasonSupporterStat::SOCIAL_PUBLISHED, SeasonSupporterStat::SOCIAL_SKIPPED],
        SeasonSupporterStat::SOCIAL_SKIPPED => [SeasonSupporterStat::SOCIAL_ELIGIBLE],
        SeasonSupporterStat::SOCIAL_PUBLISHED => [],
    ];

    public function __construct(protected OperationalAuditService $audit) {}

    /**
     * صفحة الطابور للإدارة (بالاسم العام فقط).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function list(Season $season, ?string $status = null, int $limit = 100)
    {
        return DB::table('season_supporter_stats as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.season_id', $season->id)
            ->whereNotNull('s.social_status')
            ->when($status !== null && in_array($status, SeasonSupporterStat::SOCIAL_STATUSES, true), fn ($q) => $q->where('s.social_status', $status))
            ->orderBy('s.social_updated_at')->orderBy('s.id')
            ->limit(max(1, min(500, $limit)))
            ->get(['s.id', 'u.name', 's.card_tier_key', 's.public_points_total', 's.social_status', 's.social_note', 's.social_updated_at']);
    }

    /** @throws SeasonalRecognitionException */
    public function transition(SeasonSupporterStat $stat, string $to, User $actor, ?string $note = null): SeasonSupporterStat
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية إدارة طابور التنويه.');
        }

        if (! in_array($to, SeasonSupporterStat::SOCIAL_STATUSES, true)) {
            throw new SeasonalRecognitionException('invalid_status', 'حالة غير صالحة.');
        }

        return DB::transaction(function () use ($stat, $to, $actor, $note): SeasonSupporterStat {
            /** @var SeasonSupporterStat $locked */
            $locked = SeasonSupporterStat::query()->whereKey($stat->id)->lockForUpdate()->firstOrFail();
            $from = $locked->social_status;

            if ($from === $to) {
                return $locked;
            }

            if ($from === null || ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new SeasonalRecognitionException('transition_not_allowed', 'هذا الانتقال غير مسموح للحالة الحالية.');
            }

            $limit = max(10, (int) config('seasonal_recognition.limits.note_max', 300));
            $locked->forceFill([
                'social_status' => $to,
                'social_note' => $note !== null ? mb_substr(trim(strip_tags($note)), 0, $limit) : $locked->social_note,
                'social_updated_by' => $actor->id,
                'social_updated_at' => now(),
            ])->save();

            $this->audit->log('seasonal_supporter_social_'.$to, $locked, ['season_id' => $locked->season_id, 'from' => $from], $actor);

            return $locked;
        });
    }
}
