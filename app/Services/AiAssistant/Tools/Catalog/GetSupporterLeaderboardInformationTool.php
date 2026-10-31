<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\SeasonSupporterPackage;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\RecognitionSeasonResolver;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonSupporterPackageService;
use App\Services\Sponsorship\SponsorshipMoney;

/** E45 - get_supporter_leaderboard_information: لوحة داعمي الموسم العلنية وباقات البطاقات (فضي/ذهبي/ماسي) وأسعارها المعلنة. لا شراء ولا أسماء مجهولة ولا بيانات دفع. */
final class GetSupporterLeaderboardInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_supporter_leaderboard_information';
    }

    public function description(): string
    {
        return 'Get the Season Supporters public leaderboard (top supporters, points, card tier) and the announced card packages with prices for a season. Read-only: cannot buy a card.';
    }

    public function properties(): array
    {
        return ['season' => ['type' => 'string', 'description' => 'Optional season slug.', 'maxLength' => 100]];
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

        $settings = app(SeasonRecognitionSettingsService::class);
        $setting = $settings->settingFor($season);
        $finalized = $setting?->supporters_finalized_at !== null;
        $board = app(SeasonSupporterLeaderboardService::class)->publicBoard($season);
        $canBuy = $settings->supportersActive($season, $setting) && ! $finalized;

        $packages = $canBuy
            ? app(SeasonSupporterPackageService::class)->packagesFor($season, true)->map(fn (SeasonSupporterPackage $p) => [
                'name' => Presenter::text($p->name, 40),
                'price' => SponsorshipMoney::format((int) $p->price_minor, (string) $p->currency),
                'estimated_support_points' => (int) $p->support_points,
            ])->values()->all()
            : [];

        $rows = collect($board['rows'])->take(10)->map(fn (array $r) => [
            'rank' => (int) ($r['rank'] ?? 0),
            'supporter' => Presenter::text((string) ($r['name'] ?? ''), 80),
            'points' => (int) ($r['points'] ?? 0),
            'tier' => $r['tier'] ?? null,
        ])->values()->all();

        $path = Presenter::path('seasonal.supporters.show', ['season' => $season->slug]);

        return $this->ok(
            [
                'available' => true,
                'season' => app(SeasonPresenter::class)->summary($season, $context->now)['name'],
                'finalized' => $finalized,
                'supporters_count' => (int) ($board['supporters_count'] ?? count($rows)),
                'top_supporters' => $rows,
                'card_packages' => $packages,
                'cards_on_sale' => $canBuy,
                'note' => 'Support points are an estimate-only recognition score: not money, gems or XP and they give no gameplay advantage.',
                'path' => $path,
            ],
            array_values(array_filter([Presenter::link('داعمو الموسم', $path)])),
            ['season', 'top_supporters'],
        );
    }
}
