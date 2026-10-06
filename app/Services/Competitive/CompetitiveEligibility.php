<?php

namespace App\Services\Competitive;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;

/**
 * أي أحجية تصلح هدفًا تنافسيًا؟ قاعدتان من التدقيق: (1) بلا تلميح: التلميح يُشترى بعملة فيصير ميزة مدفوعة (Pay-to-Win)؛ (2) إجابة منفردة
 * مُتحقَّق منها بالسيرفر (exact_string/memory_match/sequence_match). أنواع الجلسات التفاعلية (spot_difference) خارج النطاق: مسارها القياسي
 * يمنح مكافآت ويحتاج تصميمًا مستقلًا.
 */
class CompetitiveEligibility
{
    public const SUPPORTED_VALIDATION_TYPES = ['exact_string', 'memory_match', 'sequence_match'];

    public function __construct(protected GameTypeRegistry $games) {}

    /** @return string|null سبب الرفض بالعربية، أو null إن كانت صالحة */
    public function reasonIfIneligible(Puzzle $puzzle, bool $requireActive = true): ?string
    {
        if ($requireActive && ! $puzzle->is_active) {
            return 'هذه الأحجية غير مفعَّلة.';
        }

        if (filled($puzzle->hint)) {
            return 'الأحجيات ذات التلميح لا تصلح للمنافسة (التلميح يُشترى بعملة).';
        }

        $definition = $this->games->definitionFor($puzzle->game_type);

        if ($definition instanceof GameSessionHandler || ! in_array($definition->validationType(), self::SUPPORTED_VALIDATION_TYPES, true)) {
            return 'نوع هذه الأحجية غير مدعوم في المنافسات حاليًا.';
        }

        return null;
    }

    /**
     * استعلام الأحجيات المؤهَّلة لتحدّيات الأصدقاء (مفعَّلة، بلا تلميح، إجابة منفردة): مرآة SQL لـreasonIfIneligible للبحث بالترقيم. المرجع
     * عند الإنشاء هو reasonIfIneligible دائمًا (اختبار يثبت اتساق الاثنين).
     */
    public function eligiblePuzzlesQuery(bool $requireActive = true)
    {
        return Puzzle::query()->when($requireActive, fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('hint')->orWhere('hint', ''))
            ->where(fn ($q) => $q->whereNull('game_type')->orWhereIn('game_type', ['memory', 'sequence']));
    }

    public function isEligible(Puzzle $puzzle, bool $requireActive = true): bool
    {
        return $this->reasonIfIneligible($puzzle, $requireActive) === null;
    }
}
