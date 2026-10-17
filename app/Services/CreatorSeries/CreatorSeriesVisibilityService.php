<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E30 - مصدر الحقيقة الوحيد لظهور السلاسل للعامة (البحث/الاكتشاف/الصفحة/ملف المبدع/الإشعارات كلها تمرّ من هنا).
 *
 * السلسلة ظاهرة ⇔ كل ما يلي معًا، **محسوبًا حيًّا** في كل قراءة (لا عمود is_visible مخزَّن ولا مصالحة):
 *   - لها نسخة منشورة (معتمَدة) وعدد عناصر > 0
 *   - غير مخفية إشرافيًا وغير مؤرشفة
 *   - مبدعها معتمَد وحسابه غير مجمَّد
 *   - **كل** عناصر النسخة المنشورة أعمال ظاهرة الآن (is_visible)
 * فإخفاء أحجية واحدة أو تعليق المبدع يخفي السلسلة فورًا في كل مكان، وإعادة الأحجية/رفع التعليق تستعيدها فورًا بلا أي خطوة صيانة.
 * (الصفحة تقرأ من DB دائمًا؛ الكاش لا يكون سلطة ظهور أبدًا.)
 */
class CreatorSeriesVisibilityService
{
    /** شرط الظهور على استعلام CreatorSeries (استعلام فرعي EXISTS/NOT EXISTS مفهرس، بلا ضمّ ولا N+1). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNotNull('creator_series.published_revision_id')
            ->where('creator_series.published_items_count', '>', 0)
            ->whereNull('creator_series.hidden_at')
            ->whereNull('creator_series.archived_at')
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('creator_profiles')
                    ->whereColumn('creator_profiles.id', 'creator_series.creator_profile_id')
                    ->where('creator_profiles.status', CreatorProfile::STATUS_APPROVED)
                    ->whereExists(function ($u) {
                        $u->selectRaw('1')->from('users')
                            ->whereColumn('users.id', 'creator_profiles.user_id')
                            ->where('users.is_frozen', false);
                    });
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('creator_series_items as csi')
                    ->leftJoin('creator_puzzles as csw', 'csw.id', '=', 'csi.creator_puzzle_id')
                    ->whereColumn('csi.creator_series_revision_id', 'creator_series.published_revision_id')
                    ->where(function ($x) {
                        $x->whereNull('csw.id')->orWhere('csw.is_visible', false);
                    });
            });
    }

    public function visibleQuery(): Builder
    {
        return $this->scopeVisible(CreatorSeries::query());
    }

    public function isVisible(CreatorSeries $series): bool
    {
        return $this->visibleQuery()->whereKey($series->getKey())->exists();
    }

    /**
     * أسباب عدم ظهور السلسلة الآن بالعربية (فارغة = ظاهرة). للمبدع ("لماذا لا تظهر سلسلتي؟") وللمراجع.
     *
     * @return list<string>
     */
    public function blockers(CreatorSeries $series): array
    {
        $reasons = [];

        if ($series->published_revision_id === null) {
            return ['لم تُنشر بعد.'];
        }

        if ($series->hidden_at !== null) {
            $reasons[] = 'أخفاها المراجع.';
        }

        if ($series->archived_at !== null) {
            $reasons[] = 'مؤرشفة.';
        }

        $profile = CreatorProfile::query()->with('user:id,is_frozen')->find($series->creator_profile_id, ['id', 'user_id', 'status']);

        if ($profile === null || ! $profile->isApproved()) {
            $reasons[] = 'صلاحيات المبدع معلَّقة.';
        } elseif ($profile->user === null || (bool) $profile->user->is_frozen) {
            $reasons[] = 'حساب المبدع مقيَّد.';
        }

        $hiddenItems = $this->hiddenItemCount((int) $series->published_revision_id);

        if ($hiddenItems > 0) {
            $reasons[] = "تحتوي {$hiddenItems} أحجية غير ظاهرة حاليًا (مخفية أو مؤرشفة أو غير منشورة).";
        }

        return $reasons;
    }

    /** عناصر نسخة منشورة بحالة ظهور كل عمل (للوحة المراجعة: ظهور كل عنصر على حدة). @return list<array{position:int, work_id:int, public_id:string, title:string, visible:bool}> */
    public function itemStates(int $revisionId): array
    {
        return DB::table('creator_series_items as i')
            ->leftJoin('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $revisionId)
            ->orderBy('i.position')
            ->get(['i.position', 'w.id as work_id', 'w.public_id', 'w.published_title', 'w.is_visible'])
            ->map(fn ($r) => [
                'position' => (int) $r->position,
                'work_id' => (int) $r->work_id,
                'public_id' => (string) $r->public_id,
                'title' => (string) $r->published_title,
                'visible' => (bool) $r->is_visible,
            ])->all();
    }

    public function hiddenItemCount(int $revisionId): int
    {
        return (int) DB::table('creator_series_items as i')
            ->leftJoin('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $revisionId)
            ->where(function ($x) {
                $x->whereNull('w.id')->orWhere('w.is_visible', false);
            })->count();
    }
}
