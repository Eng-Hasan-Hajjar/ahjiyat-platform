<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\CampaignSnapshot;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_my_current_season_status: الموسم المباشر الآن ونافذته وتقدّم المستخدم في حملته الأساسية (أعداد فقط). */
final class GetMyCurrentSeasonStatusTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_current_season_status';
    }

    public function description(): string
    {
        return "Get the live season and the signed-in member's own progress in the season's main campaign (counts and percentage only).";
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'seasons';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $presenter = app(SeasonPresenter::class);
        $season = $presenter->currentLive($context->now);

        if ($season === null) {
            return $this->ok(['live_season' => null, 'note' => 'There is no live season right now.']);
        }

        $progress = $season->campaign === null ? null : CampaignSnapshot::for($context->user, $season->campaign);

        return $this->ok(
            ['live_season' => $presenter->summary($season, $context->now), 'my_campaign_progress' => $progress],
            array_values(array_filter([$presenter->source($season)])),
            ['live_season'],
        );
    }
}
