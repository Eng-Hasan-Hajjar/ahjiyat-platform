<?php

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchBackend;
use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\Sources\CampaignSource;
use App\Services\Search\Sources\ChampionshipSource;
use App\Services\Search\Sources\CompetitionSource;
use App\Services\Search\Sources\PlayerSource;
use App\Services\Search\Sources\PuzzleSource;
use App\Services\Search\Sources\SeasonSource;
use App\Services\Search\Sources\TeamSource;
use Illuminate\Support\Collection;

/**
 * E27: التنفيذ الحالي لـSearchBackend: مصادر Eloquent فوق قاعدة البيانات الحالية. لا محرك خارجي ولا Scout ولا ذكاء اصطناعي ولا بحث متجهي.
 */
final class DatabaseSearchBackend implements SearchBackend
{
    /** @var array<string, SearchSource> */
    private array $sources = [];

    public function __construct(PuzzleSource $puzzles, CampaignSource $campaigns, SeasonSource $seasons, CompetitionSource $competitions, ChampionshipSource $championships, TeamSource $teams, PlayerSource $players)
    {
        foreach ([$puzzles, $campaigns, $seasons, $competitions, $championships, $teams, $players] as $source) {
            $this->sources[$source->type()] = $source;
        }
    }

    public function types(): array
    {
        $configured = array_values(array_filter((array) config('search.types', []), fn ($t) => is_string($t) && isset($this->sources[$t])));

        return $configured !== [] ? $configured : array_keys($this->sources);
    }

    public function label(string $type): string
    {
        return $this->source($type)->label();
    }

    public function minLength(string $type, SearchContext $context): int
    {
        return $this->source($type)->minLength($context);
    }

    public function searchType(string $type, SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection
    {
        return $this->source($type)->search($query, $context, $limit, $offset);
    }

    private function source(string $type): SearchSource
    {
        return $this->sources[$type] ?? throw new \InvalidArgumentException('Unknown search type.');
    }
}
