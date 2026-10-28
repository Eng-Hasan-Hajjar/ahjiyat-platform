<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E43 - **سياسة الوصول المميز الوحيدة** (لا شروط موزَّعة في المتحكمات). تُطبَّق على نقاط المحتوى الفعلية: عرض الأحجية (show)، محاولة الحل (attempt)، التلميح (hint)، ونقاش الأحجية/السلسلة (discuss).
 * ظهور العمل/السلسلة (E28/E30: scopeVisible) شرط سابق لا يُستبدل: هذه طبقة **إضافية**. البيانات الوصفية (العنوان/الغلاف/المبدع/الوصف/العدد/السعر/الشارة) تبقى عامة؛ الحل والتلميح والنقاش لا تُسلَّم لغير المخوَّل أبدًا.
 *
 * القفل مشتق حيًّا (PremiumLockQuery): العمل ضمن النسخة المنشورة لسلسلة بعرض نشط/موقوف. حرّ ⇒ مسموح بلا أي استعلام إضافي (العمود يأتي مع استعلام العمل نفسه).
 *
 * المخوَّلون للمقفول (بالترتيب، أول مطابقة تحسم):
 *   1. المبدع صاحب العمل: view/hint/discuss (المحاولة محجوبة أصلًا في CommunityPlayService: المبدع يعاين ولا يلعب).
 *   2. مشرف بصلاحية creators.review: **معاينة** view/hint فقط — لا attempt (لا محاولات مزيفة تلوّث التحليلات/التأهيل) ولا discuss غير المبدع/المالك إلا ضمن الصلاحية نفسها، ولا ينشئ وصولًا أو شراءً.
 *   3. مالك وصول مدفوع فعّال (غير مسحوب) لسلسلة تحوي العمل في نسخها المنشورة: كل القدرات. المجمَّد يتبع سياسة الحسابات المقيَّدة القائمة (قراءة نعم، والتفاعل تمنعه الطبقات القائمة).
 *   4. تجميد متقادم لكل أحجية: لاعب لديه محاولة سابقة على العمل **قبل** أول تفعيل للعرض: view/attempt/hint (لا discuss). بلا صفوف وصول جماعية؛ مشتق من تاريخ المحاولات.
 * غير ذلك ⇒ مرفوض: login_required (ضيف) | preview_only (مشرف يحاول المحاولة) | premium_locked.
 *
 * لا يكتب شيئًا ولا يعتمد على أي كاش.
 */
class CreatorPremiumAccess
{
    public const VIEW = 'view';

    public const ATTEMPT = 'attempt';

    public const HINT = 'hint';

    public const DISCUSS = 'discuss';

    public const BASIS_FREE = 'free';

    public const BASIS_CREATOR = 'creator';

    public const BASIS_STAFF = 'staff';

    public const BASIS_OWNER = 'owner';

    public const BASIS_GRANDFATHERED = 'grandfathered';

    public function __construct(protected CreatorPremiumPolicy $policy) {}

    /** أقدم وقت تفعيل لعرض قافل لهذا العمل، أو null (حرّ). بلا استعلام إن جاء العمود مع استعلام العمل (scopeWithPremiumLock). */
    public function lockSince(CreatorPuzzle $work): ?CarbonImmutable
    {
        if (array_key_exists('premium_lock_since', $work->getAttributes())) {
            $raw = $work->getAttributes()['premium_lock_since'];
        } else {
            $raw = DB::table('creator_puzzles as cw')
                ->where('cw.id', $work->getKey())
                ->selectSub(PremiumLockQuery::lockSince('cw.id'), 'lock_since')
                ->value('lock_since');
        }

        return $raw === null ? null : CarbonImmutable::parse((string) $raw);
    }

    public function isLocked(CreatorPuzzle $work): bool
    {
        return $this->lockSince($work) !== null;
    }

    public function decide(?User $viewer, CreatorPuzzle $work, string $ability = self::VIEW): PremiumAccessDecision
    {
        $lockSince = $this->lockSince($work);

        if ($lockSince === null) {
            return PremiumAccessDecision::allow(self::BASIS_FREE, false);
        }

        if ($viewer === null) {
            return PremiumAccessDecision::deny('login_required');
        }

        if ($this->creatorUserId($work) === (int) $viewer->getKey()) {
            return $ability === self::ATTEMPT ? PremiumAccessDecision::deny('preview_only') : PremiumAccessDecision::allow(self::BASIS_CREATOR);
        }

        $staff = $viewer->can('creators.review');

        if ($staff && in_array($ability, [self::VIEW, self::HINT, self::DISCUSS], true)) {
            return PremiumAccessDecision::allow(self::BASIS_STAFF);
        }

        if ($this->ownsWork($viewer, (int) $work->getKey())) {
            return PremiumAccessDecision::allow(self::BASIS_OWNER);
        }

        if ($ability !== self::DISCUSS && $this->hadAttemptBefore($viewer, (int) $work->getKey(), $lockSince)) {
            return PremiumAccessDecision::allow(self::BASIS_GRANDFATHERED);
        }

        return PremiumAccessDecision::deny($staff && $ability === self::ATTEMPT ? 'preview_only' : 'premium_locked');
    }

    /** @throws CreatorPremiumException login_required | preview_only | premium_locked */
    public function assert(?User $viewer, CreatorPuzzle $work, string $ability): PremiumAccessDecision
    {
        $decision = $this->decide($viewer, $work, $ability);

        if (! $decision->allowed) {
            throw new CreatorPremiumException($decision->basis, match ($decision->basis) {
                'login_required' => 'سجّل الدخول لمتابعة هذه الأحجية المميزة.',
                'preview_only' => 'المعاينة لا تسجّل محاولات؛ هذه أحجية مميزة.',
                default => 'هذه الأحجية جزء من سلسلة مميزة. احصل على الوصول من صفحة السلسلة لمتابعتها.',
            });
        }

        return $decision;
    }

    /**
     * السلسلة التي تقفل هذا العمل ويمكن شراؤها (لزر «احصل على الوصول» في صفحة الأحجية المقفلة). عامة البيانات الوصفية فقط. null إن لم توجد سلسلة ظاهرة قابلة للعرض.
     *
     * @return array{public_id: string, title: string, price_minor: int, currency: string, sellable: bool}|null
     */
    public function lockingSeriesCard(CreatorPuzzle $work): ?array
    {
        $row = DB::table('creator_series_items as i')
            ->join('creator_series_revisions as r', 'r.id', '=', 'i.creator_series_revision_id')
            ->join('creator_series as s', function ($j) {
                $j->on('s.id', '=', 'r.creator_series_id')->on('s.published_revision_id', '=', 'r.id');
            })
            ->join('creator_series_sale_offers as o', 'o.creator_series_id', '=', 's.id')
            ->where('i.creator_puzzle_id', $work->getKey())
            ->whereIn('o.status', CreatorSeriesSaleOffer::LOCKING_STATUSES)
            ->whereNull('s.hidden_at')->whereNull('s.archived_at')
            ->orderBy('o.id')
            ->first(['s.public_id', 's.published_title', 'o.price_minor', 'o.currency', 'o.status']);

        if ($row === null) {
            return null;
        }

        return [
            'public_id' => (string) $row->public_id,
            'title' => (string) $row->published_title,
            'price_minor' => (int) $row->price_minor,
            'currency' => (string) $row->currency,
            'sellable' => $row->status === CreatorSeriesSaleOffer::STATUS_ACTIVE && $this->policy->enabled(),
        ];
    }

    /** وصول مدفوع فعّال لهذه السلسلة (استعلام مفهرس واحد: unique(user_id, creator_series_id)). */
    public function ownsSeries(?User $viewer, CreatorSeries|int $series): bool
    {
        if ($viewer === null) {
            return false;
        }

        return DB::table('creator_series_accesses')
            ->where('user_id', $viewer->getKey())
            ->where('creator_series_id', $series instanceof CreatorSeries ? $series->getKey() : $series)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * أرقام السلاسل (من القائمة) التي يملكها المشاهد: استعلام واحد لقائمة كاملة (لا N+1 في بطاقات السلاسل).
     *
     * @param  list<int>  $seriesIds
     * @return list<int>
     */
    public function ownedSeriesIds(?User $viewer, array $seriesIds): array
    {
        if ($viewer === null || $seriesIds === []) {
            return [];
        }

        return DB::table('creator_series_accesses')
            ->where('user_id', $viewer->getKey())->whereNull('revoked_at')->whereIn('creator_series_id', $seriesIds)
            ->pluck('creator_series_id')->map(fn ($id) => (int) $id)->all();
    }

    /** نقاش السلسلة نفسها: المبدع/مشرف/مالك فقط إن كانت السلسلة مميزة (قفل = عرض نشط/موقوف). */
    public function decideSeriesDiscussion(?User $viewer, CreatorSeries $series, bool $premium): PremiumAccessDecision
    {
        if (! $premium) {
            return PremiumAccessDecision::allow(self::BASIS_FREE, false);
        }

        if ($viewer === null) {
            return PremiumAccessDecision::deny('login_required');
        }

        $creatorUserId = $series->relationLoaded('creator') && $series->creator !== null
            ? (int) $series->creator->user_id
            : (int) CreatorProfile::query()->whereKey($series->creator_profile_id)->value('user_id');

        if ($creatorUserId === (int) $viewer->getKey()) {
            return PremiumAccessDecision::allow(self::BASIS_CREATOR);
        }

        if ($viewer->can('creators.review')) {
            return PremiumAccessDecision::allow(self::BASIS_STAFF);
        }

        return $this->ownsSeries($viewer, $series) ? PremiumAccessDecision::allow(self::BASIS_OWNER) : PremiumAccessDecision::deny('premium_locked');
    }

    /** هل لهذه السلسلة عرض قافل (نشط/موقوف) الآن؟ */
    public function seriesIsPremium(CreatorSeries|int $series): bool
    {
        return DB::table('creator_series_sale_offers')
            ->where('creator_series_id', $series instanceof CreatorSeries ? $series->getKey() : $series)
            ->whereIn('status', CreatorSeriesSaleOffer::LOCKING_STATUSES)
            ->exists();
    }

    protected function ownsWork(User $viewer, int $workId): bool
    {
        return DB::table('creator_series_accesses as a')
            ->join('creator_series as s', 's.id', '=', 'a.creator_series_id')
            ->join('creator_series_items as i', function ($j) use ($workId) {
                $j->on('i.creator_series_revision_id', '=', 's.published_revision_id')->where('i.creator_puzzle_id', '=', $workId);
            })
            ->where('a.user_id', $viewer->getKey())
            ->whereNull('a.revoked_at')
            ->exists();
    }

    protected function hadAttemptBefore(User $viewer, int $workId, CarbonImmutable $lockSince): bool
    {
        return DB::table('community_puzzle_attempts')
            ->where('user_id', $viewer->getKey())
            ->where('creator_puzzle_id', $workId)
            ->where('created_at', '<', $lockSince)
            ->exists();
    }

    protected function creatorUserId(CreatorPuzzle $work): int
    {
        if ($work->relationLoaded('creator') && $work->creator !== null) {
            return (int) $work->creator->user_id;
        }

        return (int) CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
    }
}
