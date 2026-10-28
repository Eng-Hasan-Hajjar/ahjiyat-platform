<?php

namespace App\Services\CreatorFinance;

use App\Services\CreatorPremium\PremiumLockQuery;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * E39 - الوحدات المؤهَّلة لكل مبدع داخل فترة، باستعلام تجميعي واحد على قاعدة البيانات (لا حلقة PHP على المحاولات).
 *
 * الوحدة المؤهَّلة = (لاعب، عمل) إجابة **صحيحة** واحدة على الأقل داخل [start, end)، تُحسب مرة واحدة مهما تكرّرت المحاولات (COUNT DISTINCT).
 * الاستبعادات (كلها في الاستعلام نفسه):
 *   - إجابة خاطئة؛ خارج الفترة؛ لاعب هو صاحب الملف نفسه (لعب ذاتي)؛ حساب لاعب مجمَّد؛ عمل مخفي (إشرافًا أو تعليقًا/تجميدًا) أو لم يُنشر أصلًا.
 *   - سقف لكل (لاعب، مبدع): CASE يقصّ وحدات اللاعب عند السقف؛ raw_units تُحفظ للشفافية.
 *   - E43: محاولات على عمل ضمن سلسلة بعرض بيع مميز (من أول تفعيل حتى الإغلاق) لا تدخل الصندوق (مسار تسييل واحد: البيع المباشر). مستقبلي فقط: ما سبق التفعيل يُحسب كما كان.
 * **لا يدخل في الحساب** عدد المتابعين أو السمعة أو الدعم المالي أو التقييمات أو أي إشارة بلاغ/احتيال آلية. اللاعب المجمَّد لا يُعاقَب هنا بإشارة بل بحالة أُقرّت بشريًا.
 * العمل المؤرشف يعد (أرشفة المبدع لعمله لا تمحو لعبًا مشروعًا حدث داخل الفترة). الاستعلام بربط معاملات (لا تضمين نصوص في SQL).
 */
final class QualifiedUnitsQuery
{
    /**
     * @return list<array{creator_profile_id:int, raw_units:int, qualified_units:int, unique_players:int}>
     */
    public function perCreator(CarbonInterface $start, CarbonInterface $end, int $perUserCap): array
    {
        $cap = max(1, $perUserCap);

        $pairs = DB::table('community_puzzle_attempts as a')
            ->join('creator_puzzles as cp', 'cp.id', '=', 'a.creator_puzzle_id')
            ->join('creator_profiles as pr', 'pr.id', '=', 'cp.creator_profile_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.is_correct', 1)
            ->where('a.created_at', '>=', $start)
            ->where('a.created_at', '<', $end)
            ->whereNull('cp.hidden_at')
            ->whereNotNull('cp.published_puzzle_id')
            ->whereColumn('a.user_id', '<>', 'pr.user_id')
            ->where('u.is_frozen', 0)
            ->tap(fn ($q) => PremiumLockQuery::excludeFromFund($q, 'a.creator_puzzle_id', 'a.created_at')) // E43: المحتوى المميز يُسيَّل بالبيع المباشر لا بصندوق المبدعين (مستقبليًا فقط).
            ->groupBy('cp.creator_profile_id', 'a.user_id')
            ->selectRaw('cp.creator_profile_id as creator_profile_id, a.user_id as player_id, COUNT(DISTINCT a.creator_puzzle_id) as units');

        return DB::query()->fromSub($pairs, 't')
            ->groupBy('t.creator_profile_id')
            ->orderBy('t.creator_profile_id')
            ->selectRaw(
                't.creator_profile_id as creator_profile_id, SUM(t.units) as raw_units, SUM(CASE WHEN t.units > ? THEN ? ELSE t.units END) as qualified_units, COUNT(*) as unique_players',
                [$cap, $cap],
            )
            ->get()
            ->map(fn ($r) => [
                'creator_profile_id' => (int) $r->creator_profile_id,
                'raw_units' => (int) $r->raw_units,
                'qualified_units' => (int) $r->qualified_units,
                'unique_players' => (int) $r->unique_players,
            ])
            ->all();
    }
}
