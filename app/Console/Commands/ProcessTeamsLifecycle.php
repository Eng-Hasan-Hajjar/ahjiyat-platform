<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Teams\TeamCompetitiveRankingService;
use App\Services\Teams\TeamInvitationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** E19: صيانة الفرق الساعية (Idempotent): انتهاء الدعوات، إصلاح عدّادات الأعضاء (حذف مستخدم يحذف العضوية بلا إنقاص)، وتخزين ترتيب الفرق المتأخر. غيابه لا يكسر شيئًا. */
class ProcessTeamsLifecycle extends Command
{
    protected $signature = 'teams:process-lifecycle';

    protected $description = 'E19: انتهاء دعوات الفرق، إصلاح عدّادات الأعضاء، وتخزين ترتيب الفرق المتأخر (ساعي، Idempotent)';

    public function handle(TeamInvitationService $invitations, TeamCompetitiveRankingService $ranking): int
    {
        $expired = $invitations->expireStale();

        $fixed = 0;
        Team::query()->select('id', 'members_count')->chunkById(200, function ($teams) use (&$fixed) {
            foreach ($teams as $team) {
                $actual = DB::table('team_memberships')->where('team_id', $team->id)->count();

                if ($actual !== (int) $team->members_count) {
                    DB::table('teams')->where('id', $team->id)->update(['members_count' => $actual]);
                    $fixed++;
                }
            }
        });

        $finalized = $ranking->finalizePending();

        $this->info("الفرق: دعوات منتهية {$expired} | عدّادات مُصلَحة {$fixed} | أحداث رُتِّبت فرقها {$finalized}");

        return self::SUCCESS;
    }
}
