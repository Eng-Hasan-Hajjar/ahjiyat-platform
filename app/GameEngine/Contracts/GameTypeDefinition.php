<?php

namespace App\GameEngine\Contracts;

use App\Models\Puzzle;

/**
 * كل نوع لعبة (game_type) ينفّذ هذا العقد بصنف Definition واحد صغير.
 * هذا العقد محايد تمامًا تجاه أي إطار واجهة (Filament أو غيره) - أي شيء
 * يخص التأليف/الفورم يعيش خارج app/GameEngine بالكامل (انظر
 * app/Filament/GameTypeAuthoring). إضافة نوع لعبة جديد لاحقًا يعني إنشاء
 * صنف واحد ينفّذ هذا العقد + سطر تسجيل بـ config/game_types.php - بدون
 * أي تعديل على GameTypeRegistry أو أي Definition أخرى.
 */
interface GameTypeDefinition
{
    /** المفتاح المخزَّن بعمود puzzles.game_type - سلسلة فارغة تمثّل الأنواع الكلاسيكية (game_type = null) */
    public function key(): string;

    public function label(): string;

    /** يُخزَّن بعمود puzzles.validation_type لأغراض العرض/التوثيق بلوحة الإدارة فقط - لا يُستشار وقت التنفيذ */
    public function validationType(): string;

    /** يُخزَّن بعمود puzzles.score_mode لنفس الغرض */
    public function scoreMode(): string;

    /** القيمة الافتراضية لعمود puzzles.renderer - القيمة المخزَّنة بالأحجية نفسها لها الأولوية دائمًا إن وُجدت */
    public function renderer(): string;

    public function validator(): GameValidator;

    public function scorer(): ScoreCalculator;

    /**
     * يُستدعى من Puzzle::booted() (عبر GameTypeRegistry::prepareForSave) عند كل
     * حفظ - يشتق أي بيانات لعب فعلية (مثل ترتيب Sequence الصحيح أو بطاقات
     * Memory المضاعفة) من بيانات التأليف الخام المُدخلة، ويكتبها مباشرة
     * على $puzzle (game_config/solution_data). الأنواع التي لا تحتاج اشتقاقًا
     * (كلاسيكي) تترك هذه الدالة فارغة.
     */
    public function normalizeAuthoringData(Puzzle $puzzle): void;

    /**
     * الحد الأدنى الآمن للعرض لأي Blade/API عام - لا يحتوي أبدًا على
     * solution_data أو أي حل سرّي. غير مُستهلَك بعد بمسارات العرض الحالية
     * (انظر ملاحظة Phase A) لتفادي أي تغيير سلوك غير ضروري بهذه المرحلة.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(Puzzle $puzzle): array;
}