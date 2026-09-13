<?php

namespace App\GameEngine\Contracts;

use App\Models\GameSession;
use App\Models\Puzzle;

interface GameSessionHandler
{
    public function initialServerState(Puzzle $puzzle): array;

    /**
     * @return array{hit: bool, found_indices: array, found_count: int, required_count: int, all_found: bool}
     */
    public function reveal(Puzzle $puzzle, GameSession $session, float $x, float $y): array;

    /**
     * يفحص بشكل مستقل تمامًا (بغض النظر عمّن استدعى finalize() ولماذا) هل
     * server_state المخزَّنة تمثّل فعلاً حلاً كاملاً صحيحًا. GameSessionService
     * يعتمد على هذه الدالة وحدها لتقرير هل الجلسة "مكتملة" فعلاً - لا يكفي
     * أن تُستدعى finalize() ليصبح الناتج نجاحًا (Invariant حقيقي، لا Trust
     * بالمسار الذي استدعاها).
     */
    public function isServerStateComplete(Puzzle $puzzle, array $serverState): bool;
}