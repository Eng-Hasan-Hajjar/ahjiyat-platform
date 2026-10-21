<?php

namespace App\Services\LiveOps;

use App\Models\PlayerStreak;
use App\Models\User;

/**
 * E34 - سجل مغلق لقواعد أهلية مجموعات المهام. لا قاعدة تأتي من نص إداري حر ولا من العميل،
 * وكلها تقرأ حقائق خادمية فقط: عمر الحساب، المستوى، وآخر نشاط **لعب رسمي** (لا تسجيل دخول).
 * الإشارات المستبعدة عمدًا: الدردشة، المدفوعات، مبالغ الرعاية، أعلام الاحتيال، البريد، الملف الخاص.
 */
enum MissionEligibilityRule: string
{
    case All = 'all';
    case NewPlayer = 'new_player';
    case Returning = 'returning';
    case Experienced = 'experienced';

    public function label(): string
    {
        return match ($this) {
            self::All => 'الجميع (المجموعة القياسية)',
            self::NewPlayer => 'لاعب جديد',
            self::Returning => 'لاعب عائد بعد غياب',
            self::Experienced => 'لاعب متمرّس',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @param array{level?:int,last_active_date?:?string} $facts */
    public function check(User $user, array $facts): bool
    {
        return match ($this) {
            self::All => true,
            self::NewPlayer => $user->created_at !== null
                && $user->created_at->gte(now()->subDays(max(1, (int) config('liveops.pools.new_player_days', 14)))),
            self::Returning => ComebackService::isInactiveFor(
                $facts['last_active_date'] ?? null,
                max(1, (int) config('liveops.comeback.min_inactive_days', 7)),
            ),
            self::Experienced => (int) ($facts['level'] ?? 1) >= max(1, (int) config('liveops.pools.experienced_level', 5)),
        };
    }
}
