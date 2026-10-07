<?php

namespace App\Listeners;

use App\Events\TeamChallengeAccepted;
use App\Events\TeamChallengeCompleted;
use App\Events\TeamChallengeCreated;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Support\Collection;

/**
 * إشعارات تحدّيات الفرق (E20-E5..E12): مستمع واحد لثلاثة أحداث، بعد commit فقط. المستلمون **المعنيّون** وحدهم (لا كل أعضاء الفريق):
 * وصول التحدّي ← مالك ومشرفو الفريق الهدف؛ القبول ← مالك ومشرفو الفريق المتحدّي؛ النتيجة ← لاعبو الروستر + مالك ومشرفو الفريقين (بلا تكرار). فئة social وتفضيل social_enabled
 * (كتحدّيات الأصدقاء) والمفتاح الشامل. مفاتيح دلالية لكل مستلم فلا تكرار. حالة السجل تُتحقَّق (لا "قُبل" لمعلّق ولا نتيجة لغير معتمَد). فشله لا يمسّ التحدّي.
 */
class SendTeamChallengeNotifications
{
    public function handleCreated(TeamChallengeCreated $event): void
    {
        $this->run($event->challengeId, TeamChallenge::STATUS_PENDING, function (TeamChallenge $c) {
            $this->send($this->managers($c->opponent_team_id), $c, NotificationType::TeamChallengeReceived, 'team-challenge-received', $c->challenger->name);
        });
    }

    public function handleAccepted(TeamChallengeAccepted $event): void
    {
        $this->run($event->challengeId, TeamChallenge::STATUS_ACCEPTED, function (TeamChallenge $c) {
            $this->send($this->managers($c->challenger_team_id), $c, NotificationType::TeamChallengeAccepted, 'team-challenge-accepted', $c->opponent->name);
        });
    }

    public function handleCompleted(TeamChallengeCompleted $event): void
    {
        $this->run($event->challengeId, TeamChallenge::STATUS_COMPLETED, function (TeamChallenge $c) {
            foreach ([$c->challenger_team_id, $c->opponent_team_id] as $teamId) {
                $other = $teamId === $c->challenger_team_id ? $c->opponent : $c->challenger;
                $outcome = $c->is_draw ? 'انتهت المباراة بالتعادل' : ($c->winner_team_id === $teamId ? 'فاز فريقكم' : 'خسر فريقكم');
                $players = User::query()->whereIn('id', TeamChallengeParticipant::query()->where('team_challenge_id', $c->id)->where('team_id', $teamId)->select('user_id'))->get();

                $this->send($players->concat($this->managers($teamId))->unique('id'), $c, NotificationType::TeamChallengeResultReady, 'team-challenge-result', $other->name, $outcome);
            }
        });
    }

    protected function run(int $challengeId, string $expectedStatus, \Closure $work): void
    {
        try {
            $challenge = TeamChallenge::query()->with(['challenger:id,name,slug', 'opponent:id,name,slug'])->find($challengeId);

            if ($challenge !== null && $challenge->status === $expectedStatus) {
                $work($challenge);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** مالك ومشرفو الفريق. @return Collection<int, User> */
    protected function managers(int $teamId): Collection
    {
        return User::query()->whereIn('id', TeamMembership::query()->where('team_id', $teamId)->whereIn('role', [TeamMembership::ROLE_OWNER, TeamMembership::ROLE_ADMIN])->select('user_id'))->get();
    }

    protected function send(Collection $users, TeamChallenge $challenge, NotificationType $type, string $key, string $otherTeam, ?string $outcome = null): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        foreach ($users as $user) {
            try {
                $dispatcher->dispatch($user, $type, ['team' => $otherTeam, 'outcome' => $outcome], "{$key}:{$challenge->id}:{$user->id}", ['team_challenge_id' => $challenge->id], ['challenge' => $challenge->public_id], 'teams.challenges.show');
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
