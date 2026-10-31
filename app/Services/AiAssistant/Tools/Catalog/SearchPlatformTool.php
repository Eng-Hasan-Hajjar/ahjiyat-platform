<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\SearchResult;

/**
 * E45 - search_platform: بحث E27 نفسه (قراءة فقط) بحقول عرض آمنة: النوع والعنوان والعنوان الفرعي والشارة والرابط النسبي.
 * لا يُمرَّر SearchResult::subject أبدًا، ولا تُعرَض نتائج اللاعبين (لا كشف عن أعضاء آخرين). بحث الأحجيات بالعنوان فقط كما صمّمه E27.
 */
final class SearchPlatformTool extends AiTool
{
    private const TYPES = ['puzzles', 'community', 'creator_series', 'collections', 'campaigns', 'seasons', 'competitions', 'championships', 'teams'];

    public function name(): string
    {
        return 'search_platform';
    }

    public function description(): string
    {
        return 'Search public Ahjiyat pages by title (puzzle titles only, seasons, campaigns, competitions, teams, series, collections). Returns titles and internal page links, never solutions. Use to find the right page.';
    }

    public function properties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search words (2-80 chars).', 'maxLength' => 80],
            'type' => ['type' => 'string', 'description' => 'Optional result type filter.', 'enum' => self::TYPES],
        ];
    }

    public function required(): array
    {
        return ['query'];
    }

    public function area(): string
    {
        return 'search';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $found = app(GlobalSearchService::class)->suggest((string) $arguments['query'], $context->user);

        if (($found['state'] ?? 'ok') !== 'ok') {
            return $this->ok(['state' => (string) $found['state'], 'results' => []]);
        }

        $type = $arguments['type'] ?? null;
        $results = [];
        $sources = [];

        foreach ($found['groups'] as $group) {
            if ($group['type'] === 'players' || ($type !== null && $group['type'] !== $type)) {
                continue;
            }

            foreach ($group['results'] as $result) {
                /** @var SearchResult $result */
                $path = SearchResult::relativePath($result->url);

                if (! AiLinkPolicy::isInternalPath($path)) {
                    continue;
                }

                $results[] = [
                    'type' => (string) $group['label'],
                    'title' => Presenter::text($result->title, 120),
                    'subtitle' => Presenter::text($result->subtitle, 120),
                    'badge' => Presenter::text($result->badge, 40),
                    'path' => $path,
                ];

                if (count($sources) < 3) {
                    $sources[] = Presenter::link((string) Presenter::text($result->title, 60), $path);
                }
            }
        }

        if ($results === []) {
            return $this->ok(['state' => 'no_results', 'results' => []]);
        }

        return $this->ok(['state' => 'ok', 'results' => array_slice($results, 0, 9)], Presenter::sources(...$sources), ['title', 'subtitle']);
    }
}
