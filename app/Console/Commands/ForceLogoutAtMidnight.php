<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserSession;
use Carbon\Carbon;

class ForceLogoutAtMidnight extends Command
{
    protected $signature = 'sessions:force-logout';
    protected $description = 'Force logout all open sessions at midnight';

    public function handle()
    {
        $midnight = Carbon::today(); // 00:00:00 لليوم الجديد

        UserSession::whereNull('logout_at')
            ->update([
                'logout_at' => $midnight,
                'last_activity' => $midnight,
            ]);

        $this->info('All users logged out automatically at midnight.');
    }
}