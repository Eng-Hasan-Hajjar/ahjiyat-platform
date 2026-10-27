<?php

namespace App\Services;

use App\Events\SeasonStarted;
use App\Models\Season;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * المصدر الحقيقي الوحيد لسؤال: "هل الموسم مباشر الآن؟" ولحالته المشتقّة (draft|scheduled|live|ended|archived)، وللانتقال "لم يبدأ ← بدأ".
 *
 * التعريف لا يُكرَّر: بموسم بلا نافذة صريحة (starts_at/ends_at فارغان — كل المواسم القائمة، ومنها أصيل) الموسم مباشر = منشور + حملته متاحة بتعريف
 * CampaignProgressService::isCampaignAvailable نفسه (مفعَّلة، starts_at ليس بالمستقبل، ends_at ليس بالماضي) — السلوك القديم حرفيًا.
 * بنافذة صريحة (E42) تصير تواريخ الموسم هي السلطة لكل طرف مضبوط (الطرف الفارغ يتبع الحملة الأساسية)، ولا يشترط توفّر الحملة لبدء الموسم: قد يكون الموسم
 * مباشرًا وحملته «قريبًا». الحالة لا تُخزَّن؛ تُشتقّ من التواريخ وعلم النشر وarchive_at، ولا يملك العميل أي قرار فيها.
 *
 * الانتقال ذري: UPDATE ... WHERE went_live_at IS NULL. لا يُطلَق SeasonStarted إلا إذا تأثّر صف فعلًا بهذا الاستدعاء
 * (فسباق استدعاءين أو إعادة المحاولة أو التشغيل المتكرر = حدث واحد على الأكثر)، وبعد commit.
 * went_live_at تُكتَب مرة واحدة: إيقاف/تفعيل لاحق لا يعيد الحدث أبدًا.
 *
 * يُستدعى من: خطافات الحفظ (Season: النشر/النافذة/الظهور/الأرشفة، Campaign.is_active/starts_at/ends_at) + الأمر المجدول
 * seasons:sync-live-state لالتقاط البدء الناتج عن مرور الوقت (لا يكتب شيئًا بقاعدة البيانات حينها).
 */
class SeasonLifecycleService
{
    public const STATE_DRAFT = 'draft';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_LIVE = 'live';

    public const STATE_ENDED = 'ended';

    public const STATE_ARCHIVED = 'archived';

    /** مفردات التوفّر القديمة (تُحفَظ كما هي لواجهات اللاعب والاختبارات). */
    public const AVAILABILITY_LIVE = 'live';

    public const AVAILABILITY_UPCOMING = 'upcoming';

    public const AVAILABILITY_FINISHED = 'finished';

    public const AVAILABILITY_INACTIVE = 'inactive';

    public function __construct(protected CampaignProgressService $campaigns) {}

    /** نافذة الموسم صريحة؟ (أي طرف مضبوط). الفارغة تتبع الحملة الأساسية بالكامل. */
    public function hasExplicitWindow(Season $season): bool
    {
        return $season->starts_at !== null || $season->ends_at !== null;
    }

    /** بداية فعلية: تاريخ الموسم إن ضُبط، وإلا بداية حملته الأساسية (قد تكون null = بلا بداية). */
    public function windowStart(Season $season): ?CarbonInterface
    {
        return $season->starts_at ?? $season->campaign?->starts_at;
    }

    public function windowEnd(Season $season): ?CarbonInterface
    {
        return $season->ends_at ?? $season->campaign?->ends_at;
    }

    /** منشور وبلغ موعد ظهوره (announcement_at). الاستعلامات تعكسه بـSeason::scopePubliclyVisible. */
    public function isPubliclyVisible(Season $season, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $season->is_published && ($season->announcement_at === null || $season->announcement_at->lte($now));
    }

    /**
     * live | upcoming | finished | inactive (المفردات القديمة). inactive = حملة موقوفة بموسم بلا نافذة صريحة (السلوك القديم: «غير متاحة»).
     * مرآة SQL للمنتهي: Season::scopeEnded / scopeNotEnded (يضمن التكافؤ اختبار مصفوفة).
     */
    public function availability(Season $season, ?CarbonInterface $now = null): string
    {
        $campaign = $season->campaign;

        if ($campaign === null) {
            return self::AVAILABILITY_INACTIVE;
        }

        if (! $this->hasExplicitWindow($season)) {
            if ($now === null && $this->campaigns->isCampaignAvailable($campaign)) {
                return self::AVAILABILITY_LIVE; // التعريف الأصلي للتوفّر بلا نسخ
            }

            $now ??= now();

            return match (true) {
                ! $campaign->is_active => self::AVAILABILITY_INACTIVE,
                $campaign->starts_at !== null && $campaign->starts_at->gt($now) => self::AVAILABILITY_UPCOMING,
                $campaign->ends_at !== null && $campaign->ends_at->lt($now) => self::AVAILABILITY_FINISHED,
                default => self::AVAILABILITY_LIVE,
            };
        }

        $now ??= now();
        $start = $this->windowStart($season);
        $end = $this->windowEnd($season);

        return match (true) {
            $start !== null && $start->gt($now) => self::AVAILABILITY_UPCOMING,
            $end !== null && $end->lt($now) => self::AVAILABILITY_FINISHED,
            default => self::AVAILABILITY_LIVE,
        };
    }

    /** draft | scheduled | live | ended | archived — مشتقّة بالكامل، لا حالة مخزَّنة. */
    public function state(Season $season, ?CarbonInterface $now = null): string
    {
        if (! $season->is_published) {
            return self::STATE_DRAFT;
        }

        if ($season->archive_at !== null && $season->archive_at->lte($now ?? now())) {
            return self::STATE_ARCHIVED;
        }

        if (! $this->isPubliclyVisible($season, $now)) {
            return self::STATE_SCHEDULED;
        }

        return match ($this->availability($season, $now)) {
            self::AVAILABILITY_LIVE => self::STATE_LIVE,
            self::AVAILABILITY_FINISHED => self::STATE_ENDED,
            default => self::STATE_SCHEDULED,
        };
    }

    public function isLive(Season $season): bool
    {
        return $this->isPubliclyVisible($season)
            && ($season->archive_at === null || $season->archive_at->gt(now()))
            && $this->availability($season) === self::AVAILABILITY_LIVE;
    }

    /** منتهٍ أو مؤرشف (يُعرض في الأرشيف). */
    public function isPast(Season $season, ?CarbonInterface $now = null): bool
    {
        return in_array($this->state($season, $now), [self::STATE_ENDED, self::STATE_ARCHIVED], true);
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_DRAFT => 'مسودة',
            self::STATE_SCHEDULED => 'قريبًا',
            self::STATE_LIVE => 'مباشر الآن',
            self::STATE_ENDED => 'انتهى',
            self::STATE_ARCHIVED => 'في الأرشيف',
            default => $state,
        };
    }

    /** @return bool true إن حدث الانتقال فعلًا بهذا الاستدعاء تحديدًا. */
    public function syncLiveState(Season|int $season): bool
    {
        $fresh = Season::query()->with('campaign')->find($season instanceof Season ? $season->getKey() : $season);

        if ($fresh === null || $fresh->went_live_at !== null || ! $this->isLive($fresh)) {
            return false;
        }

        $affected = Season::query()->whereKey($fresh->getKey())->whereNull('went_live_at')->update(['went_live_at' => now()]);

        if ($affected !== 1) {
            return false; // سبقنا استدعاء آخر
        }

        $id = $fresh->getKey();

        DB::afterCommit(function () use ($id) {
            try {
                $started = Season::query()->with('campaign')->find($id);

                if ($started !== null) {
                    event(new SeasonStarted($started));
                }
            } catch (\Throwable $e) {
                report($e); // الحدث ثانوي: فشله لا يمسّ حفظ الموسم/الحملة ولا went_live_at.
            }
        });

        return true;
    }

    /**
     * نقطة دخول الخطافات: لا تكسر أي حفظ إداري أبدًا - حتى لو فشل إنشاء الخدمة نفسه من الحاوية أو لم يُشغَّل
     * الترحيل بعد. المجدوِل (seasons:sync-live-state) شبكة الأمان لما فات الخطاف.
     */
    public static function syncFromHook(Season|int $season): void
    {
        try {
            app(self::class)->syncLiveState($season);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
