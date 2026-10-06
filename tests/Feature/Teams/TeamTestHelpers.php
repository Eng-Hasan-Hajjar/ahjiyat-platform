<?php

require_once __DIR__.'/../CompetitiveRewards/RewardTestHelpers.php';

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\TeamInvitationService;
use App\Services\Teams\TeamJoinRequestService;
use App\Services\Teams\TeamMembershipService;
use App\Services\Teams\TeamService;
use Illuminate\Support\Str;

/** مساعدات E19: كلها عبر الخدمات الحقيقية (لا إدخال خام) إلا حين يُختبر الحارس عمدًا. */
if (! function_exists('e19Teams')) {
    function e19Teams(): TeamService
    {
        return app(TeamService::class);
    }

    function e19Members(): TeamMembershipService
    {
        return app(TeamMembershipService::class);
    }

    function e19Invites(): TeamInvitationService
    {
        return app(TeamInvitationService::class);
    }

    function e19Requests(): TeamJoinRequestService
    {
        return app(TeamJoinRequestService::class);
    }

    /** فريق بمالك (المنشئ). افتراضيًا عام ومفتوح الانضمام. */
    function e19Team(?User $owner = null, array $attrs = []): Team
    {
        return e19Teams()->create($owner ?? e16User(), $attrs + ['name' => 'Team '.Str::random(6), 'join_policy' => 'open']);
    }

    /** عضو بدور معيّن (انضمام مباشر ثم رفع الدور بالخدمة لو لزم). */
    function e19Member(Team $team, ?User $user = null, string $role = 'member'): User
    {
        $user ??= e16User();
        e19Members()->addMember($team, $user);

        if ($role !== 'member') {
            e19Members()->changeRole($team->owner()->first(), $team, $user, $role);
        }

        return $user;
    }

    function e19Role(Team $team, User $user): ?string
    {
        return TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->value('role');
    }

    function e19Count(Team $team): int
    {
        return TeamMembership::where('team_id', $team->id)->count();
    }
}

if (! function_exists('e19Result')) {
    /**
     * نتيجة معتمَدة بدرجة/مدة معلومتين وبلقطة فريق معلومة (للتحكم الكامل بالتعادلات). لا يمر بتسجيل E17؛ يملأ الجداول مباشرة كما لو سُجِّل اللاعب وقتها.
     */
    function e19Result(\App\Models\CompetitiveEvent $event, User $user, ?Team $team, int $score, int $durationMs, bool $correct = true): \App\Models\CompetitiveEventResult
    {
        $session = \App\Models\GameSession::create([
            'user_id' => $user->id, 'puzzle_id' => $event->puzzle_id, 'context_type' => 'competitive_event', 'context_id' => $event->id,
            'status' => 'completed', 'started_at' => now(), 'completed_at' => now(), 'server_state' => ['competitive' => true],
        ]);
        \App\Models\CompetitiveEventParticipant::create(['competitive_event_id' => $event->id, 'user_id' => $user->id, 'team_id_snapshot' => $team?->id, 'status' => 'completed', 'registered_at' => now()->subDay()]);

        return \App\Models\CompetitiveEventResult::create([
            'competitive_event_id' => $event->id, 'user_id' => $user->id, 'game_session_id' => $session->id, 'is_correct' => $correct,
            'score' => $score, 'duration_ms' => $durationMs, 'completed_at' => now()->subHour(), 'final_rank' => null,
        ]);
    }

    /** يجعل الحدث معتمَدًا (للاختبارات التي تتحكم بالنتائج مباشرة). */
    function e19Complete(\App\Models\CompetitiveEvent $event): \App\Models\CompetitiveEvent
    {
        $event->forceFill(['status' => 'completed', 'finalized_at' => now()])->save();

        return $event->refresh();
    }

    function e19Ranking(): \App\Services\Teams\TeamCompetitiveRankingService
    {
        return app(\App\Services\Teams\TeamCompetitiveRankingService::class);
    }
}

if (! function_exists('e19Code')) {
    /** كود الملف بلا تعليقات (للتدقيق الثابت). */
    function e19Code(string $file): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', file_get_contents($file));
    }
}
