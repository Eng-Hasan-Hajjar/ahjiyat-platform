<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use Illuminate\Support\Facades\DB;

/**
 * E45 - get_my_progress_summary: المستوى والخبرة والمستوى التالي ورصيد الجواهر (المتاح والمعلّق) وعدد الإنجازات المفتوحة. قراءة خالصة:
 * لا firstOrCreate لسجل التقدّم ولا للمحفظة (خدمات المستوى/المحفظة تنشئ صفوفًا عند القراءة فلا تُستعمل هنا). لا سجل معاملات ولا مراجع دفع.
 */
final class GetMyProgressSummaryTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_progress_summary';
    }

    public function description(): string
    {
        return "Get the signed-in member's own progression: level, XP, XP needed for the next level, gem balances (available and pending), and unlocked achievements count.";
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'progression';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $user = $context->user;
        $progression = PlayerProgression::query()->where('user_id', $user->getKey())->first(['total_xp', 'current_level']);
        $xp = (int) ($progression?->total_xp ?? 0);
        $levelNumber = (int) ($progression?->current_level ?? 1);

        $levels = LevelDefinition::query()->where('is_active', true)->orderBy('level_number')->get(['level_number', 'name', 'xp_required_total']);
        $current = $levels->firstWhere('level_number', $levelNumber) ?? $levels->first();
        $next = $levels->first(fn ($l) => $l->level_number > $levelNumber);

        $percent = 100;

        if ($next !== null) {
            $range = (int) $next->xp_required_total - (int) ($current?->xp_required_total ?? 0);
            $percent = $range > 0 ? (int) max(0, min(100, round(($xp - (int) ($current?->xp_required_total ?? 0)) / $range * 100))) : 100;
        }

        $wallets = $user->wallets()->with('currency:id,name,code')->get(['id', 'user_id', 'currency_id', 'available_balance', 'pending_balance'])
            ->map(fn ($w) => ['currency' => Presenter::text($w->currency?->name, 40), 'available' => (int) $w->available_balance, 'pending' => (int) $w->pending_balance])->values()->all();

        $unlocked = (int) DB::table('user_achievement_progress')->where('user_id', $user->getKey())->whereNotNull('unlocked_at')->count();
        $totalAchievements = (int) DB::table('achievements')->where('is_active', true)->count();

        return $this->ok(
            [
                'level' => $levelNumber,
                'level_name' => Presenter::text($current?->name, 60),
                'total_xp' => $xp,
                'next_level' => $next?->level_number,
                'xp_to_next_level' => $next === null ? null : max(0, (int) $next->xp_required_total - $xp),
                'progress_to_next_percent' => $percent,
                'wallets' => $wallets,
                'achievements_unlocked' => $unlocked,
                'achievements_total' => $totalAchievements,
            ],
            array_values(array_filter([Presenter::link('تقدّمي', Presenter::path('progress.show')), Presenter::link('محفظتي', Presenter::path('wallet.index'))])),
        );
    }
}
