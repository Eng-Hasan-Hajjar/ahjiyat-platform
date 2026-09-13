<?php

namespace App\GameEngine\Definitions;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\Contracts\GameTypeDefinition;
use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\Contracts\ScoreCalculator;
use App\GameEngine\Scoring\FlatScoreCalculator;
use App\GameEngine\Validators\SpotDifferenceValidator;
use App\Models\GameSession;
use App\Models\Puzzle;
use Illuminate\Support\Facades\Storage;

class SpotDifferenceGameTypeDefinition implements GameTypeDefinition, GameSessionHandler
{
    public function key(): string
    {
        return 'spot_difference';
    }

    public function label(): string
    {
        return 'اكتشف الفروق (Spot Difference)';
    }

    public function validationType(): string
    {
        return 'spot_difference_match';
    }

    public function scoreMode(): string
    {
        return 'flat';
    }

    public function renderer(): string
    {
        return 'games.spot-difference';
    }

    public function validator(): GameValidator
    {
        return app(SpotDifferenceValidator::class);
    }

    public function scorer(): ScoreCalculator
    {
        return app(FlatScoreCalculator::class);
    }

    public function normalizeAuthoringData(Puzzle $puzzle): void
    {
        $hotspots = (array) ($puzzle->solution_data['hotspots'] ?? []);

        $puzzle->solution_data = [
            'hotspots' => array_values(array_map(fn ($hotspot) => [
                'x' => (float) ($hotspot['x'] ?? 0),
                'y' => (float) ($hotspot['y'] ?? 0),
                'radius' => (float) ($hotspot['radius'] ?? 0.05),
            ], $hotspots)),
        ];
    }

    public function publicPayload(Puzzle $puzzle): array
    {
        $config = (array) $puzzle->game_config;

        return [
            'image_before' => $this->resolveImageUrl($config['image_before'] ?? null),
            'image_after' => $this->resolveImageUrl($config['image_after'] ?? null),
            'required_differences' => count((array) ($puzzle->solution_data['hotspots'] ?? [])),
        ];
    }

    public function initialServerState(Puzzle $puzzle): array
    {
        return ['found_indices' => []];
    }

    public function reveal(Puzzle $puzzle, GameSession $session, float $x, float $y): array
    {
        $hotspots = (array) ($puzzle->solution_data['hotspots'] ?? []);
        $found = (array) ($session->server_state['found_indices'] ?? []);

        $hit = false;

        foreach ($hotspots as $index => $hotspot) {
            if (in_array($index, $found, true)) {
                continue;
            }

            $dx = $x - (float) $hotspot['x'];
            $dy = $y - (float) $hotspot['y'];
            $radius = (float) ($hotspot['radius'] ?? 0.05);

            if (($dx * $dx + $dy * $dy) <= ($radius * $radius)) {
                $hit = true;
                $found[] = $index;
                break;
            }
        }

        return [
            'hit' => $hit,
            'found_indices' => array_values($found),
            'found_count' => count($found),
            'required_count' => count($hotspots),
            'all_found' => count($hotspots) > 0 && count($found) === count($hotspots),
        ];
    }

    public function isServerStateComplete(Puzzle $puzzle, array $serverState): bool
    {
        $required = count((array) ($puzzle->solution_data['hotspots'] ?? []));
        $found = array_unique((array) ($serverState['found_indices'] ?? []));

        return $required > 0 && count($found) === $required;
    }

    protected function resolveImageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::url($path);
    }
}