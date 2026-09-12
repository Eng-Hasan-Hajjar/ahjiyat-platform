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

/**
 * "اكتشف الفروق" - Game Type عام بالكامل، لا علاقة له بأي محتوى محدَّد.
 * أي Puzzle جديدة بـ game_type=spot_difference تعمل تلقائياً بنفس الآلية،
 * بدون أي كود إضافي (Controller/Validator/JS جديد) - هذا هو معيار قبول
 * Phase B بالضبط.
 */
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
        // الأدمن يُدخل إحداثيات الفروق مباشرة بصيغتها النهائية (لا مضاعفة
        // ولا اشتقاق كما بـ Sequence/Memory) - فقط نُطبّع الأنواع الرقمية
        // لأن Filament قد يرسلها كنصوص.
        $hotspots = (array) ($puzzle->solution_data['hotspots'] ?? []);

        $puzzle->solution_data = [
            'hotspots' => array_values(array_map(fn ($hotspot) => [
                'x' => (float) ($hotspot['x'] ?? 0),
                'y' => (float) ($hotspot['y'] ?? 0),
                'radius' => (float) ($hotspot['radius'] ?? 0.05),
            ], $hotspots)),
        ];
    }

    /**
     * الحمولة الآمنة الوحيدة المسموح كشفها للمتصفح - صور العرض وعدد
     * الفروق المطلوب فقط. لا إحداثيات، لا نصف قطر، لا أي تلميح موقعي.
     */
    public function publicPayload(Puzzle $puzzle): array
    {
        $config = (array) $puzzle->game_config;

        return [
            'image_before' => $config['image_before'] ?? null,
            'image_after' => $config['image_after'] ?? null,
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
                continue; // مُكتشفة مسبقاً - لا تُحتسب مرتين
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
}