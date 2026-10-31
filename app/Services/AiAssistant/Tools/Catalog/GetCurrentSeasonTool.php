<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonLifecycleService;

/** E45 - get_current_season: الموسم المباشر الآن والمواسم القادمة المعلنة (أسماء ونوافذ وروابط فقط). */
final class GetCurrentSeasonTool extends AiTool
{
    public function name(): string
    {
        return 'get_current_season';
    }

    public function description(): string
    {
        return 'Get the season that is live right now on Ahjiyat and the upcoming announced seasons (name, state, start/end dates, page link).';
    }

    public function area(): string
    {
        return 'seasons';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $presenter = app(SeasonPresenter::class);
        $lifecycle = app(SeasonLifecycleService::class);
        $seasons = $presenter->active($context->now, 8);

        $live = $seasons->first(fn ($s) => $lifecycle->state($s, $context->now) === SeasonLifecycleService::STATE_LIVE);
        $upcoming = $seasons->filter(fn ($s) => $lifecycle->state($s, $context->now) === SeasonLifecycleService::STATE_SCHEDULED)->take(3);

        if ($live === null && $upcoming->isEmpty()) {
            return $this->ok(['live' => null, 'upcoming' => [], 'note' => 'No live or announced season right now.']);
        }

        return $this->ok(
            [
                'live' => $live ? $presenter->summary($live, $context->now) : null,
                'upcoming' => $upcoming->map(fn ($s) => $presenter->summary($s, $context->now))->values()->all(),
            ],
            array_values(array_filter([$live ? $presenter->source($live) : null, ...$upcoming->take(1)->map(fn ($s) => $presenter->source($s))->all()])),
            ['name', 'tagline'],
        );
    }
}
