<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\RecognitionSeasonResolver;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonalRecognition\SeasonWriterEligibilityService;
use App\Services\SeasonalRecognition\SeasonWriterVotingService;

/** E45 - get_my_writer_star_budget: ميزانية نجوم المستخدم نفسه في دوري الكتّاب (الكلي/المصروف/المتبقي) وحالة التصويت وعدد الكتّاب الذين يحق له التصويت لهم. لا يصوّت ولا يكشف لمن صوّت. */
final class GetMyWriterStarBudgetTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_writer_star_budget';
    }

    public function description(): string
    {
        return "Get the signed-in member's own star budget in the Writers League (total, spent, remaining), whether voting is open, and how many writers they are eligible to vote for. Read-only: cannot vote.";
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
        return 'writer_league';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $season = app(RecognitionSeasonResolver::class)->resolve($arguments['season'] ?? null, $context->now, RecognitionSeasonResolver::WRITERS);

        if ($season === null) {
            return $this->ok(['available' => false, 'note' => 'No Writers League is available for the requested season.']);
        }

        $panel = app(SeasonWriterVotingService::class)->panel($context->user, $season);
        $path = Presenter::path('seasonal.writers.show', ['season' => $season->slug]);

        return $this->ok(
            [
                'available' => true,
                'voting_state' => (string) $panel['state'],
                'voting_open' => (bool) $panel['open'],
                'stars_total' => (int) $panel['total'],
                'stars_spent' => (int) $panel['spent'],
                'stars_remaining' => (int) $panel['remaining'],
                'writers_i_can_vote_for' => count($panel['votable']),
                'writers_i_gave_stars_to' => count(array_filter($panel['allocations'], fn ($stars) => $stars > 0)),
                'i_am_a_writer_in_this_league' => app(SeasonWriterEligibilityService::class)->isActiveWriterIn((int) $season->id, (int) $context->user->getKey()),
                'path' => $path,
            ],
            array_values(array_filter([Presenter::link('دوري الكتّاب', $path)])),
        );
    }
}
