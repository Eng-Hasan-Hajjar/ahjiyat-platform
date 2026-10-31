<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\RecognitionSeasonResolver;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonWriterLeaderboardService;

/** E45 - get_writer_league_information: دوري الكتّاب لموسم (حالة التصويت ونافذته ونجوم الناخب الواحد واللوحة العلنية). لا تصويت ولا ميزانية أحد ولا أصوات فردية. */
final class GetWriterLeagueInformationTool extends AiTool
{
    private const STATES = [
        SeasonRecognitionSettingsService::STATE_DISABLED => 'غير مفعَّل',
        SeasonRecognitionSettingsService::STATE_NOT_CONFIGURED => 'لم تُضبط نافذته بعد',
        SeasonRecognitionSettingsService::STATE_UPCOMING => 'التصويت لم يبدأ بعد',
        SeasonRecognitionSettingsService::STATE_OPEN => 'التصويت مفتوح الآن',
        SeasonRecognitionSettingsService::STATE_CLOSED => 'أُغلق التصويت',
        SeasonRecognitionSettingsService::STATE_FINALIZED => 'انتهى وصدر الترتيب النهائي',
    ];

    public function name(): string
    {
        return 'get_writer_league_information';
    }

    public function description(): string
    {
        return 'Get the Seasonal Writers League for a season (default: the relevant current season): voting state and window, stars each voter has, and the current public ranking. Read-only: cannot vote.';
    }

    public function properties(): array
    {
        return ['season' => ['type' => 'string', 'description' => 'Optional season slug.', 'maxLength' => 100]];
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

        $settings = app(SeasonRecognitionSettingsService::class);
        $setting = $settings->settingFor($season);
        $board = app(SeasonWriterLeaderboardService::class)->publicBoard($season);
        $state = (string) $board['state'];
        $rows = collect($board['rows'])->take(10)->map(fn (array $r) => [
            'rank' => (int) $r['rank'],
            'writer' => Presenter::text((string) $r['name'], 80),
            'stars' => (int) $r['stars'],
            'voters' => (int) $r['voters'],
            'outcome' => $r['outcome'] ?? null,
            'path' => Presenter::path('creators.show', ['creator' => (string) $r['slug']]),
        ])->values()->all();

        $seasons = app(SeasonPresenter::class);
        $path = Presenter::path('seasonal.writers.show', ['season' => $season->slug]);

        return $this->ok(
            [
                'available' => true,
                'season' => $seasons->summary($season, $context->now)['name'],
                'state' => $state,
                'state_label' => self::STATES[$state] ?? $state,
                'voting_opens_at' => Presenter::date($setting?->writers_voting_opens_at),
                'voting_closes_at' => Presenter::date($settings->effectiveClose($season, $setting)),
                'stars_per_voter' => (int) ($setting?->writers_stars_per_voter ?? config('seasonal_recognition.writers.stars_per_voter', 10)),
                'writers_count' => (int) $board['writers_count'],
                'finalized' => (bool) $board['finalized'],
                'top_writers' => $rows,
                'path' => $path,
            ],
            array_values(array_filter([Presenter::link('دوري الكتّاب', $path)])),
            ['season', 'top_writers'],
        );
    }
}
