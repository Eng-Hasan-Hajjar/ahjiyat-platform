<?php

namespace App\GameEngine\Validators;

use App\GameEngine\Contracts\GameValidator;
use App\GameEngine\GameResult;
use App\GameEngine\Support\TimedAttemptToken;
use App\Models\Puzzle;

class MemoryMatchValidator implements GameValidator
{
    public function check(Puzzle $puzzle, array $submission): GameResult
    {
        $cards = collect($puzzle->game_config['cards'] ?? []);
        $totalPairs = intdiv($cards->count(), 2);

        $usedIds = [];
        $validPairs = 0;

        foreach ((array) ($submission['matches'] ?? []) as $pair) {
            $pair = array_values((array) $pair);

            if (count($pair) !== 2) {
                continue;
            }

            [$idA, $idB] = array_map('intval', $pair);

            if ($idA === $idB || in_array($idA, $usedIds, true) || in_array($idB, $usedIds, true)) {
                continue; // إعادة استخدام بطاقة بأكثر من زوج - محاولة تلاعب واضحة
            }

            $cardA = $cards->firstWhere('id', $idA);
            $cardB = $cards->firstWhere('id', $idB);

            if ($cardA && $cardB && $cardA['face'] === $cardB['face']) {
                $usedIds[] = $idA;
                $usedIds[] = $idB;
                $validPairs++;
            }
        }

        $solved = $totalPairs > 0 && $validPairs === $totalPairs;

        // فرض الوقت المحدد (إن وُجد) بالاعتماد على الوقت الفعلي المحسوب
        // سيرفريًا عبر رمز البداية الموقّع - وليس على أي رقم من المتصفح.
        if ($solved && $puzzle->time_limit_seconds) {
            $elapsed = TimedAttemptToken::elapsedSeconds(
                $submission['start_token'] ?? null,
                $puzzle,
                $submission['_context']['user_id'] ?? null
            );

            if ($elapsed === null || $elapsed > $puzzle->time_limit_seconds) {
                $solved = false;
            }
        }

        return new GameResult(correct: $solved);
    }
}