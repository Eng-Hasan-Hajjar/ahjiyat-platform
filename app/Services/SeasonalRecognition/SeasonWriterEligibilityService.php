<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E44 - من يحق له أن يوزّع نجومًا على كاتب بعينه؟ **قرار خادم فقط** (لا ثقة بما يدّعيه العميل).
 *
 * الترتيب (أول رفض يحسم):
 *   1) حساب مجمَّد أو غير موثَّق البريد ⇒ مرفوض (لا تصويت مجهول).
 *   2) تصويت لنفسه ⇒ مرفوض (يشمل المشرف/المدير إن كان هو الكاتب).
 *   3) طاقم بصلاحية seasonal-recognition.vote (لا أسماء أدوار مضمَّنة، ولا دور «مشرف» جديد) ⇒ مؤهَّل لكل الكتّاب.
 *   4) كاتب نشط في **القائمة نفسها** ⇒ مؤهَّل لكتّاب آخرين.
 *   5) لاعب: دليل لعب فعلي (CommunityPuzzleAttempt صحيحة أو خاطئة؛ مشاهدة الصفحة لا تكفي) على عمل ضمن سلسلة **مربوطة بهذا الكاتب لهذا الموسم**،
 *      داخل النافذة [max(بداية الموسم الفعلية، انضمام الكاتب، وقت ربط السلسلة) ، الآن]. الأهلية خاصة بالكاتب: لعبتَ لـA لا تمنحك التصويت لـB.
 * الصفات المتعددة (كاتب + مشرف...) لا تضاعف شيئًا: الميزانية واحدة للحساب في الموسم (SeasonWriterVotingService)، ووزن نجمة الطاقم مثل غيره.
 * قراءة فقط؛ لا كتابة ولا قفل.
 */
class SeasonWriterEligibilityService
{
    public const CATEGORY_STAFF = 'staff';

    public const CATEGORY_WRITER = 'writer';

    public const CATEGORY_PLAYER = 'player';

    public const STAFF_PERMISSION = 'seasonal-recognition.vote';

    public function __construct(protected SeasonRecognitionSettingsService $settings) {}

    /**
     * سبب الرفض أو null إن كان مؤهَّلًا. لا يفحص نافذة التصويت (تلك على الخدمة).
     *
     * @return array{reason: string, message: string}|null
     */
    public function denial(User $voter, SeasonWriterEntry $entry): ?array
    {
        if ($voter->is_frozen) {
            return ['reason' => 'frozen', 'message' => 'حسابك مقيَّد؛ لا يمكنك التصويت.'];
        }

        if (! $voter->hasVerifiedEmail()) {
            return ['reason' => 'unverified', 'message' => 'وثّق بريدك الإلكتروني أولًا للتصويت.'];
        }

        if ((int) $entry->user_id === (int) $voter->id) {
            return ['reason' => 'self_vote', 'message' => 'لا يمكنك التصويت لنفسك.'];
        }

        if ($this->category($voter, $entry) === null) {
            return ['reason' => 'no_play_evidence', 'message' => 'يمكنك التصويت لهذا الكاتب بعد أن تلعب إحدى سلاسله المرتبطة بهذا الموسم.'];
        }

        return null;
    }

    /** فئة التأهل لهذا الكاتب (staff|writer|player) أو null. الأقوى أولًا. */
    public function category(User $voter, SeasonWriterEntry $entry): ?string
    {
        if ($voter->can(self::STAFF_PERMISSION)) {
            return self::CATEGORY_STAFF;
        }

        if ($this->isActiveWriterIn((int) $entry->season_id, (int) $voter->id)) {
            return self::CATEGORY_WRITER;
        }

        return $this->hasPlayEvidence($voter, $entry) ? self::CATEGORY_PLAYER : null;
    }

    public function isActiveWriterIn(int $seasonId, int $userId): bool
    {
        return SeasonWriterEntry::query()
            ->where('season_id', $seasonId)
            ->where('user_id', $userId)
            ->where('status', SeasonWriterEntry::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * معرّفات الكتّاب النشطين الذين يحق لهذا الناخب التصويت لهم في الموسم (للواجهة: استعلامان بدل استعلام لكل كاتب). الحسم النهائي يبقى على denial() داخل معاملة التصويت.
     *
     * @return list<int>
     */
    public function votableEntryIds(User $voter, Season $season): array
    {
        if ($voter->is_frozen || ! $voter->hasVerifiedEmail()) {
            return [];
        }

        $entries = SeasonWriterEntry::query()->where('season_id', $season->id)->active()->get(['id', 'user_id', 'joined_at']);

        if ($voter->can(self::STAFF_PERMISSION) || $entries->contains(fn (SeasonWriterEntry $e) => (int) $e->user_id === (int) $voter->id)) {
            return $entries->filter(fn (SeasonWriterEntry $e) => (int) $e->user_id !== (int) $voter->id)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        if ($entries->isEmpty()) {
            return [];
        }

        $start = $this->settings->seasonStart($season);

        $query = DB::table('community_puzzle_attempts as a')
            ->join('creator_series_items as i', 'i.creator_puzzle_id', '=', 'a.creator_puzzle_id')
            ->join('creator_series_revisions as r', function ($join) {
                $join->on('r.id', '=', 'i.creator_series_revision_id')->where('r.status', '=', 'approved');
            })
            ->join('season_writer_series as l', 'l.creator_series_id', '=', 'r.creator_series_id')
            ->join('season_writer_entries as e', 'e.id', '=', 'l.writer_entry_id')
            ->where('a.user_id', $voter->id)
            ->whereIn('l.writer_entry_id', $entries->pluck('id')->all())
            ->whereColumn('a.created_at', '>=', 'l.linked_at')
            ->whereColumn('a.created_at', '>=', 'e.joined_at')
            ->where('a.created_at', '<=', now());

        if ($start !== null) {
            $query->where('a.created_at', '>=', $start);
        }

        return $query->distinct()->pluck('l.writer_entry_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * دليل لعب لاعب لسلسلة مربوطة بالكاتب. الاستعلام واحد (EXISTS) ومفهرس: community_puzzle_attempts(creator_puzzle_id, user_id).
     * لا يُعتدّ بالنسخ غير المعتمدة؛ أعمال السلسلة = عناصر نسخها المعتمدة (المنشورة أو التي حلّ محلها إصدار أحدث).
     */
    public function hasPlayEvidence(User $voter, SeasonWriterEntry $entry, ?\DateTimeInterface $at = null): bool
    {
        $season = Season::query()->find($entry->season_id);
        $start = $season !== null ? $this->settings->seasonStart($season) : null;
        $until = $at ?? now();

        $query = DB::table('community_puzzle_attempts as a')
            ->join('creator_series_items as i', 'i.creator_puzzle_id', '=', 'a.creator_puzzle_id')
            ->join('creator_series_revisions as r', function ($join) {
                $join->on('r.id', '=', 'i.creator_series_revision_id')->where('r.status', '=', 'approved');
            })
            ->join('season_writer_series as l', function ($join) use ($entry) {
                $join->on('l.creator_series_id', '=', 'r.creator_series_id')->where('l.writer_entry_id', '=', $entry->id);
            })
            ->where('a.user_id', $voter->id)
            ->whereColumn('a.created_at', '>=', 'l.linked_at')
            ->where('a.created_at', '>=', $entry->joined_at)
            ->where('a.created_at', '<=', $until);

        if ($start !== null) {
            $query->where('a.created_at', '>=', $start);
        }

        return $query->exists();
    }
}
