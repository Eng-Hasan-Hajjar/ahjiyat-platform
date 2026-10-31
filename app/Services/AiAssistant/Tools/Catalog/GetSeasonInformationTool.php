<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\SeasonPhase;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonalRecognition\SeasonalRecognitionVisibility;

/** E45 - get_season_information: تفاصيل موسم ظاهر علنًا (مراحله، نافذته، توفر دوري الكتّاب ودعم الموسم). لا شيء غير منشور. */
final class GetSeasonInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_season_information';
    }

    public function description(): string
    {
        return 'Get public details of one publicly visible season by its slug: state, dates, phases, and whether the writers league and supporter recognition are available.';
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Season slug (as in /seasons/{slug}).', 'maxLength' => 100]];
    }

    public function required(): array
    {
        return ['slug'];
    }

    public function area(): string
    {
        return 'seasons';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $presenter = app(SeasonPresenter::class);
        $season = $presenter->visibleBySlug((string) $arguments['slug'], $context->now);

        if ($season === null) {
            return $this->notFound('لم أجد موسمًا بهذا المعرّف.');
        }

        $visibility = app(SeasonalRecognitionVisibility::class);
        $phases = $season->phases()->get()->take(12)->map(fn (SeasonPhase $p) => [
            'name' => Presenter::text($p->name, 80),
            'starts_at' => Presenter::date($p->starts_at),
            'ends_at' => Presenter::date($p->ends_at),
            'state' => $p->stateAt($context->now),
        ])->values()->all();

        $data = $presenter->summary($season, $context->now) + [
            'prize_description' => Presenter::text($season->grand_prize_description, 240),
            'phases' => $phases,
            'writers_league_available' => $visibility->writersAvailable($season, null),
            'supporter_recognition_available' => $visibility->supportersAvailable($season, null),
        ];

        return $this->ok($data, array_values(array_filter([$presenter->source($season)])), ['name', 'tagline', 'prize_description', 'phases']);
    }
}
