<?php

namespace App\GameEngine\Contracts;

use App\Models\GameSession;
use App\Models\Puzzle;

/**
 * عقد إضافي (وليس بديلاً عن GameTypeDefinition) لأي نوع لعبة يحتاج تفاعلاً
 * وسيطاً عبر GameSession قبل الحسم النهائي - بعكس الأنواع البسيطة (نصية/
 * Sequence/Memory) التي ترسل حلاً نهائياً واحداً دفعة واحدة. صنف Definition
 * لنوع Stateful ينفّذ هذا العقد بالإضافة لـ GameTypeDefinition العادي.
 */
interface GameSessionHandler
{
    /** الحالة الابتدائية لـ server_state عند إنشاء الجلسة (مثلاً: قائمة اكتشافات فارغة) */
    public function initialServerState(Puzzle $puzzle): array;

    /**
     * يُستدعى من GameSessionService::reveal() بعد قفل صف الجلسة - يقارن
     * النقطة المُرسَلة بـ solution_data (لا يُكشف عنها أبداً للعميل) ويُرجع
     * نتيجة التفاعل الواحد هذا فقط.
     *
     * @return array{hit: bool, found_indices: array, found_count: int, required_count: int, all_found: bool}
     */
    public function reveal(Puzzle $puzzle, GameSession $session, float $x, float $y): array;
}