<?php

namespace App\Services;

use App\Models\User;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E24: لوحة الصدارة (عرض فقط). المعيار كما كان بالضبط: عدد المحاولات الصحيحة (COUNT لصفوف puzzle_attempts حيث is_correct) تنازليًا، أعلى 50.
 *
 * ما تغيّر (الأداء فقط):
 *  - يُجمَّع puzzle_attempts أولًا بـ GROUP BY user_id مع الحدّ، ثم يُضمّ لجدول users للخمسين فقط (كان يضمّ ويجمّع على كل الصفوف ثم يرتّب أعمدة users الأربعة).
 *    مكافئ تمامًا لأن لكل محاولة مستخدمًا موجودًا (FK ON DELETE CASCADE) ولا SoftDeletes على User. أضيف ترتيب ثانوي بالمعرّف لتثبيت التعادلات (كان غير محدد).
 *  - النطاق العام (كل اللاعبين) يُخزَّن Top-N لمدة قصيرة (config performance.cache.leaderboard_ttl، الافتراضي 60 ثانية) بحماية من التدافع، لأنه O(كل المحاولات) في كل زيارة.
 *    القيمة المخزّنة لا تتضمن شيئًا خاصًا بالمشاهد (اسم/معرّف عام/ظهور الملف + العدد)؛ ما يخص المشاهد (رابط الملف، التجهيزات) يُحسب حيًّا في المتحكّم.
 *  - نطاق الأصدقاء لا يُخزَّن (محدود بمجموعة الأصدقاء ويعتمد على المشاهد).
 * الإبطال: بالمدة فقط (تأخّر أقصاه TTL ثانية في ظهور حلٍّ جديد بالترتيب). لا يوجد حدث يُبطله عمدًا كي لا يُضاف عمل إلى مسار كل محاولة حلّ.
 */
class LeaderboardService
{
    public const CACHE_KEY = 'leaderboard.top_solvers.v1';

    /**
     * @param  list<int>|null  $onlyUserIds  null = كل اللاعبين (يُخزَّن)، مصفوفة = نطاق الأصدقاء (لا يُخزَّن)
     * @return Collection<int, User> نماذج User بحقول (id, name, public_id, profile_visibility) + solved_count
     */
    public function topSolvers(?array $onlyUserIds = null, int $limit = 50): Collection
    {
        if ($onlyUserIds !== null) {
            return User::hydrate($this->rows($onlyUserIds, $limit));
        }

        $rows = CachedSnapshot::remember(
            self::CACHE_KEY.'.'.$limit,
            (int) config('performance.cache.leaderboard_ttl', 60),
            fn () => $this->rows(null, $limit),
        );

        return User::hydrate($rows);
    }

    /** @return list<array<string, mixed>> */
    protected function rows(?array $onlyUserIds, int $limit): array
    {
        $solved = DB::table('puzzle_attempts')
            ->select('user_id')
            ->selectRaw('COUNT(*) as solved_count')
            ->where('is_correct', true)
            ->when($onlyUserIds !== null, fn ($q) => $q->whereIn('user_id', $onlyUserIds))
            ->groupBy('user_id')
            ->orderByDesc('solved_count')
            ->orderBy('user_id')
            ->limit($limit);

        return User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility', 'top.solved_count')
            ->joinSub($solved, 'top', 'top.user_id', '=', 'users.id')
            ->orderByDesc('top.solved_count')
            ->orderBy('users.id')
            ->get()
            ->map(fn (User $u) => $u->getAttributes())
            ->all();
    }
}
