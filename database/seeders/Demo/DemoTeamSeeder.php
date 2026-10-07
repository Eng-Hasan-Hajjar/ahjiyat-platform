<?php

namespace Database\Seeders\Demo;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Services\Teams\TeamInvitationService;
use App\Services\Teams\TeamJoinRequestService;
use App\Services\Teams\TeamMembershipService;
use App\Services\Teams\TeamNaming;
use App\Services\Teams\TeamService;
use Illuminate\Database\Seeder;

/**
 * سيناريوهات الفرق (D) **بخدمات الفرق الرسمية** وبزمن محاكى (تكوّن الفرق قبل أي منافسة ليُؤخذ لقطات الفريق وقت التسجيل صحيحة). Idempotent: الفريق بالاسم، والعضوية والطلب والدعوة بحالتها.
 *
 *  فرسان الشام  : يوسف مالك، عمر مشرف، ليان/جنى/ياسر أعضاء (انضموا بطلبات مقبولة)، سياسة "طلب"، عام. معلّق: طلب خالد + دعوة كنان.
 *  صقور المعرفة : سارة مالكة، ملاك/وسام/رغد/باسل (انضمام مفتوح)، سياسة "مفتوح"، السعة 6 (قريب من الامتلاء 5/6).
 *  عباقرة الشرق : ريم مالكة، شذى/أنس/دانة (بدعوات مقبولة)، سياسة "بدعوة فقط".
 *  نجوم الأحجيات: نور الدين مالك، إيمان/عدنان (بطلبات مقبولة)، سياسة "طلب"، **خاص**.
 */
class DemoTeamSeeder extends Seeder
{
    use DemoSupport;

    public const TEAMS = [
        'knights' => ['name' => 'فرسان الشام', 'owner' => 'yousef', 'description' => 'فريق الأحجيات الأول بالشام: نحل معًا ونتنافس بروح رياضية.', 'visibility' => 'public', 'join_policy' => 'request'],
        'falcons' => ['name' => 'صقور المعرفة', 'owner' => 'sara', 'description' => 'صقور الثقافة العامة والمنطق. بابنا مفتوح لكل محبي التحدي.', 'visibility' => 'public', 'join_policy' => 'open', 'max_members' => 6],
        'geniuses' => ['name' => 'عباقرة الشرق', 'owner' => 'reem', 'description' => 'نخبة مختارة بالدعوة فقط.', 'visibility' => 'public', 'join_policy' => 'invite_only'],
        'stars' => ['name' => 'نجوم الأحجيات', 'owner' => 'noureddine', 'description' => 'فريق خاص: القائمة لأعضائه فقط.', 'visibility' => 'private', 'join_policy' => 'request'],
    ];

    public function run(): void
    {
        $this->quiet(function () {
            $teams = [];

            foreach (self::TEAMS as $key => $def) {
                $teams[$key] = $this->team($def);
            }

            $this->requests($teams['knights'], 'yousef', ['omar' => 45, 'layan' => 44, 'jana' => 43, 'yaser' => 42]);
            $this->requests($teams['stars'], 'noureddine', ['eman' => 44, 'adnan' => 43]);
            $this->openJoins($teams['falcons'], ['malak' => 45, 'wisam' => 44, 'raghad' => 43, 'basel' => 42]);
            $this->invitations($teams['geniuses'], 'reem', ['shatha' => 45, 'anas' => 44, 'dana' => 43]);

            // عمر مشرف بفريق يوسف (لاختبار إدارة المشرفين).
            $members = app(TeamMembershipService::class);
            $omar = $this->user('omar');

            if ($members->roleIn($omar, $teams['knights']) !== TeamMembership::ROLE_ADMIN) {
                $this->at(now()->subDays(40), fn () => $members->changeRole($this->user('yousef'), $teams['knights'], $omar, TeamMembership::ROLE_ADMIN));
            }

            // معلّق الآن: طلب انضمام خالد لفريق يوسف + دعوة وارد لكنان (مستخدم جديد بلا فريق).
            $this->pendingRequest($teams['knights'], 'khaled', 1);
            $this->pendingInvitation($teams['knights'], 'yousef', 'kenan', 2);
        });

        $this->say('فرق: 4 فرق (طلب/مفتوح/بدعوة/خاص) + أدوار مالك/مشرف/عضو + طلب انضمام معلّق + دعوة معلّقة.');
    }

    protected function team(array $def): Team
    {
        $existing = Team::query()->where('name_key', TeamNaming::key(TeamNaming::normalize($def['name'])))->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->at(now()->subDays(46), fn () => app(TeamService::class)->create($this->user($def['owner']), array_diff_key($def, ['owner' => 1])));
    }

    protected function requests(Team $team, string $owner, array $joiners): void
    {
        $requests = app(TeamJoinRequestService::class);
        $members = app(TeamMembershipService::class);

        foreach ($joiners as $key => $days) {
            $user = $this->user($key);

            if ($members->membershipOf($user) !== null) {
                continue;
            }

            $this->at(now()->subDays($days)->setTime(17, 0), fn () => $requests->create($user, $team));
            $pending = TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $user->id)->where('status', 'pending')->first();
            $pending !== null && $this->at(now()->subDays($days)->setTime(18, 30), fn () => $requests->accept($this->user($owner), $team, $pending));
        }
    }

    protected function openJoins(Team $team, array $joiners): void
    {
        $members = app(TeamMembershipService::class);

        foreach ($joiners as $key => $days) {
            $user = $this->user($key);
            $members->membershipOf($user) === null && $this->at(now()->subDays($days)->setTime(16, 0), fn () => $members->joinOpen($user, $team));
        }
    }

    protected function invitations(Team $team, string $owner, array $invitees): void
    {
        $invites = app(TeamInvitationService::class);
        $members = app(TeamMembershipService::class);

        foreach ($invitees as $key => $days) {
            $user = $this->user($key);

            if ($members->membershipOf($user) !== null) {
                continue;
            }

            $this->at(now()->subDays($days)->setTime(15, 0), fn () => $invites->invite($this->user($owner), $team, $user));
            $pending = TeamInvitation::query()->where('team_id', $team->id)->where('invited_user_id', $user->id)->where('status', 'pending')->first();
            $pending !== null && $this->at(now()->subDays($days)->setTime(16, 20), fn () => $invites->accept($user, $pending));
        }
    }

    protected function pendingRequest(Team $team, string $key, int $days): void
    {
        $user = $this->user($key);

        if (app(TeamMembershipService::class)->membershipOf($user) === null && ! TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $user->id)->where('status', 'pending')->exists()) {
            $this->at(now()->subDays($days)->setTime(21, 0), fn () => app(TeamJoinRequestService::class)->create($user, $team));
        }
    }

    protected function pendingInvitation(Team $team, string $owner, string $key, int $days): void
    {
        $user = $this->user($key);

        if (app(TeamMembershipService::class)->membershipOf($user) === null && ! TeamInvitation::query()->where('team_id', $team->id)->where('invited_user_id', $user->id)->where('status', 'pending')->exists()) {
            $this->at(now()->subDays($days)->setTime(12, 0), fn () => app(TeamInvitationService::class)->invite($this->user($owner), $team, $user));
        }
    }
}
