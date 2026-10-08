<?php

namespace App\Services\Dashboard;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\Chat\ChatUnreadService;
use App\Services\Teams\TeamMembershipService;

/**
 * لوحة اللاعب (E22) - **عرض فقط**: تجمع بيانات موجودة بالأنظمة القائمة (منافسات E17 وفرق E19/E20 وغير مقروء الدردشة E21) باستعلامات قليلة ثابتة العدد (لا N+1، لا حلقات).
 * لا كتابة ولا مصدر حقيقة جديد ولا منطق نقاط/جوائز/تقدّم: كل رقم يُقرأ كما هو. لا محتوى رسائل خاصة، فقط عدّاد غير المقروء الذي تحسبه ChatUnreadService.
 * ما يحتاج انتباهًا الآن (urgent) يُرتَّب أولًا، ثم المعلومات (info).
 */
class PlayerDashboardService
{
    public function __construct(protected TeamMembershipService $members, protected ChatUnreadService $chat) {}

    /**
     * @return array{urgent: list<array>, info: list<array>, team: ?array, unread_chat: int, wallet: int, queries_hint: string}
     */
    public function forUser(User $user): array
    {
        $urgent = [];
        $info = [];

        // ---- المنافسات: حدث حيّ الآن (أو الأقرب القادم) + حالة مشاركتي (استعلامان ثابتان + مشاركتي)
        $now = now();
        $live = CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->orderBy('ends_at')->first();
        $event = $live ?? CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('starts_at', '>', $now)->orderBy('starts_at')->first();

        if ($event !== null) {
            $participant = CompetitiveEventParticipant::query()->where('competitive_event_id', $event->getKey())->where('user_id', $user->getKey())->first();
            $state = $participant === null ? 'open' : ($participant->completed_at !== null ? 'completed' : 'registered');
            $card = [
                'type' => 'competition', 'icon' => 'trophy', 'live' => $live !== null, 'title' => $event->title, 'state' => $state,
                'ends_at' => $event->ends_at, 'starts_at' => $event->starts_at, 'url' => route('competitions.show', $event),
                'cta' => match (true) {
                    $live !== null && $state === 'registered' => 'العب الآن',
                    $live !== null && $state === 'open' => 'شارك الآن',
                    $live !== null => 'عرض النتيجة',
                    $state === 'registered' => 'أنت مسجَّل',
                    default => 'سجِّل الآن',
                },
            ];
            ($live !== null && $state !== 'completed') ? $urgent[] = $card : $info[] = $card;
        }

        // ---- الفريق: عضويتي + مباراة بانتظار لعبي + تحدٍّ وارد (للمدراء) + بطولة جارية
        $membership = $this->members->membershipOf($user);
        $team = null;

        if ($membership !== null) {
            $teamModel = $membership->team()->first(['id', 'name', 'slug']);
            $waiting = TeamChallengeParticipant::query()->where('user_id', $user->getKey())->where('status', TeamChallengeParticipant::STATUS_LOCKED)
                ->whereHas('challenge', fn ($q) => $q->where('status', TeamChallenge::STATUS_ACCEPTED)->where('play_ends_at', '>', $now))->count();
            $incoming = $membership->isManager()
                ? TeamChallenge::query()->where('opponent_team_id', $membership->team_id)->where('status', TeamChallenge::STATUS_PENDING)->where('expires_at', '>', $now)->count() : 0;
            $championship = TeamChampionship::query()->where('status', TeamChampionship::STATUS_PUBLISHED)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->orderBy('ends_at')->first(['id', 'title', 'slug']);

            $team = ['model' => $teamModel, 'url' => $teamModel ? route('teams.show', $teamModel) : route('teams.index'), 'waiting' => $waiting, 'incoming' => $incoming, 'championship' => $championship];

            $waiting > 0 && $urgent[] = ['type' => 'team_play', 'icon' => 'team', 'title' => 'مباراة فريقك بانتظارك', 'body' => "لديك {$waiting} مباراة فريق لم تلعبها بعد. المهلة محدودة.", 'url' => route('teams.challenges.index'), 'cta' => 'العب مباراتك'];
            $incoming > 0 && $urgent[] = ['type' => 'team_incoming', 'icon' => 'bolt', 'title' => 'تحدٍّ وارد لفريقك', 'body' => "{$incoming} تحدٍّ ينتظر قرارك كمشرف للفريق.", 'url' => route('teams.challenges.index'), 'cta' => 'مراجعة التحدّيات'];
            $championship !== null && $info[] = ['type' => 'championship', 'icon' => 'flag', 'title' => $championship->title, 'body' => 'بطولة فرق جارية الآن.', 'url' => route('team-championships.show', $championship), 'cta' => 'عرض البطولة'];
        }

        // ---- غير المقروء (عدّاد فقط). نحفظه بسمة الطلب فتعيد شارة التنقل استعماله بلا حساب ثانٍ.
        $unread = (int) (request()->attributes->get('chat_unread_total') ?? $this->chat->total($user));
        request()->attributes->set('chat_unread_total', $unread);

        return [
            'urgent' => $urgent, 'info' => $info, 'team' => $team, 'unread_chat' => $unread,
            'wallet' => (int) ($user->wallet?->available_balance ?? 0),
            'queries_hint' => 'competition(2-3) + team(4-5) + unread(≤3)',
        ];
    }
}
