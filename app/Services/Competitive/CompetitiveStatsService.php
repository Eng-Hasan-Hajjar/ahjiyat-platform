<?php

namespace App\Services\Competitive;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\FriendChallenge;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * إحصاءات المنافسة وخزانة الجوائز (E18-C): **مشتقة بالكامل** من نتائج الأحداث المعتمدة ونتائج التحدّيات المكتملة، لا عدّادات تُعدَّل ولا شيء من العميل (C2/C3).
 * استعلامات تجميعية قليلة على جداول مفهرسة، فلا حاجة لجدول مجمَّع (يُعاد حسابه ذاتيًا عند أي قراءة).
 *
 * - "فوز" و"أفضل 3" و"أفضل مركز": نتائج **صحيحة** في أحداث **معتمَدة** فقط (مراكز نهائية). الحية والملغاة لا تُحتسب.
 * - تحدّيات الأصدقاء: فوز/خسارة/تعادل كإحصاء فقط، ولا تغذّي إنجازات ولا جوائز (منع الحصاد بين الأصدقاء).
 * - الخصوصية: ملصقات الجوائز (وصف ما مُنح) للمالك وحده؛ لا معرّفات سجل ولا مبالغ محافظ.
 */
class CompetitiveStatsService
{
    /** @return array{events_participated: int, events_completed: int, events_valid_finalized: int, events_won: int, top3: int, best_rank: ?int} */
    public function eventStats(User $user): array
    {
        $live = fn ($q) => $q->whereIn('status', [CompetitiveEvent::STATUS_PUBLISHED, CompetitiveEvent::STATUS_COMPLETED]);

        $final = CompetitiveEventResult::query()
            ->join('competitive_events as e', 'e.id', '=', 'competitive_event_results.competitive_event_id')
            ->where('e.status', CompetitiveEvent::STATUS_COMPLETED)->where('competitive_event_results.user_id', $user->getKey())
            ->where('competitive_event_results.is_correct', true)
            ->selectRaw('count(*) as valid, sum(case when competitive_event_results.final_rank = 1 then 1 else 0 end) as won, sum(case when competitive_event_results.final_rank <= 3 then 1 else 0 end) as top3, min(competitive_event_results.final_rank) as best')
            ->first();

        return [
            'events_participated' => CompetitiveEventParticipant::query()->where('user_id', $user->getKey())->whereHas('event', $live)->count(),
            'events_completed' => CompetitiveEventResult::query()->where('user_id', $user->getKey())->whereHas('event', $live)->count(),
            'events_valid_finalized' => (int) $final->valid,
            'events_won' => (int) $final->won,
            'top3' => (int) $final->top3,
            'best_rank' => $final->best === null ? null : (int) $final->best,
        ];
    }

    /** @return array{wins: int, losses: int, draws: int} */
    public function challengeStats(User $user): array
    {
        $id = $user->getKey();
        $row = FriendChallenge::query()->involving($id)->where('status', FriendChallenge::STATUS_COMPLETED)
            ->selectRaw('count(*) as total, sum(case when is_draw = 1 then 1 else 0 end) as draws, sum(case when winner_user_id = ? then 1 else 0 end) as wins', [$id])->first();

        $total = (int) $row->total;
        $wins = (int) $row->wins;
        $draws = (int) $row->draws;

        return ['wins' => $wins, 'losses' => $total - $wins - $draws, 'draws' => $draws];
    }

    /** خزانة الجوائز: مراكز 1–3 الصحيحة بأحداث معتمَدة، الأحدث أولًا. وصف الجائزة للمالك فقط. */
    public function trophies(User $user, bool $withRewards, ?int $perPage = null): LengthAwarePaginator
    {
        $page = $this->finalizedResults($user)->where('competitive_event_results.is_correct', true)->where('competitive_event_results.final_rank', '<=', 3)
            ->paginate($perPage ?? (int) config('competitive.trophies_per_page', 6), ['competitive_event_results.*'], 'trophies_page')->withQueryString();

        $rewards = $withRewards && $page->isNotEmpty()
            ? CompetitiveRewardGrant::query()->whereIn('competitive_event_result_id', $page->pluck('id'))->where('status', CompetitiveRewardGrant::STATUS_GRANTED)->pluck('reward_label', 'competitive_event_result_id')
            : collect();

        $page->getCollection()->each(fn (CompetitiveEventResult $r) => $r->setAttribute('reward_label', $rewards->get($r->id)));

        return $page;
    }

    /** تاريخ المنافسات المعتمَدة (مرقَّم). */
    public function history(User $user, ?int $perPage = null): LengthAwarePaginator
    {
        return $this->finalizedResults($user)
            ->paginate($perPage ?? (int) config('competitive.profile_history_per_page', 10), ['competitive_event_results.*'], 'history_page')->withQueryString();
    }

    protected function finalizedResults(User $user)
    {
        return CompetitiveEventResult::query()
            ->join('competitive_events as e', 'e.id', '=', 'competitive_event_results.competitive_event_id')
            ->where('e.status', CompetitiveEvent::STATUS_COMPLETED)->where('competitive_event_results.user_id', $user->getKey())
            ->with('event:id,title,slug,ends_at')
            ->orderByDesc('e.ends_at')->orderBy('competitive_event_results.id');
    }
}
