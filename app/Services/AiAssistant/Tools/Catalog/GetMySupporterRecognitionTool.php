<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterStat;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\RecognitionSeasonResolver;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;

/** E45 - get_my_supporter_recognition: نقاط دعم المستخدم نفسه ومستواه وترتيبه العلني وحالة بطاقاته (مستوى/حالة/تاريخ). لا شراء ولا مرجع دفع ولا إثبات ولا بيانات مصرفية. */
final class GetMySupporterRecognitionTool extends AiTool
{
    private const CARD_STATUS = [
        SeasonSupporterCard::STATUS_PENDING => 'قيد التحقق من الدفع',
        SeasonSupporterCard::STATUS_ACTIVE => 'مفعَّلة',
        SeasonSupporterCard::STATUS_VOID => 'ملغاة',
        SeasonSupporterCard::STATUS_REFUNDED => 'مستردة',
    ];

    public function name(): string
    {
        return 'get_my_supporter_recognition';
    }

    public function description(): string
    {
        return "Get the signed-in member's own Season Supporter recognition: support points, card tier, public rank, and the status of their own cards. Read-only: cannot buy a card.";
    }

    public function properties(): array
    {
        return ['season' => ['type' => 'string', 'description' => 'Optional season slug.', 'maxLength' => 100]];
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'supporters';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $season = app(RecognitionSeasonResolver::class)->resolve($arguments['season'] ?? null, $context->now, RecognitionSeasonResolver::SUPPORTERS);

        if ($season === null) {
            return $this->ok(['available' => false, 'note' => 'No Season Supporters feature is available for the requested season.']);
        }

        $userId = (int) $context->user->getKey();
        $stat = SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $userId)->first(['points_total', 'card_tier_key', 'final_rank']);
        $rank = app(SeasonSupporterLeaderboardService::class)->rankMap($season)[$userId] ?? null;
        $cards = SeasonSupporterCard::query()->where('season_id', $season->id)->where('user_id', $userId)->orderByDesc('id')->limit(5)
            ->get(['tier_key', 'status', 'is_anonymous', 'activated_at'])
            ->map(fn (SeasonSupporterCard $c) => [
                'tier' => (string) $c->tier_key,
                'status' => self::CARD_STATUS[$c->status] ?? (string) $c->status,
                'anonymous' => (bool) $c->is_anonymous,
                'activated_at' => Presenter::date($c->activated_at, false),
            ])->values()->all();

        $path = Presenter::path('seasonal.supporters.show', ['season' => $season->slug]);

        return $this->ok(
            [
                'available' => true,
                'support_points' => (int) ($stat?->points_total ?? 0),
                'card_tier' => $stat?->card_tier_key,
                'public_rank' => $rank,
                'final_rank' => $stat?->final_rank,
                'my_cards' => $cards,
                'note' => 'Support points are an estimate-only recognition score, not money, gems or XP.',
                'path' => $path,
            ],
            array_values(array_filter([Presenter::link('داعمو الموسم', $path)])),
        );
    }
}
