<?php

namespace App\Services\Cinematic;

use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;
use Illuminate\Support\Facades\Storage;

/**
 * CIN-1 - ربط مشهد بأحجية موجودة **للقراءة فقط**. التحقق يمر بـGameTypeRegistry::validatorFor (محرك الأحجيات نفسه، بلا نسخ منطق)،
 * ولا يمر أبدًا بـPuzzleAttemptService: لا PuzzleAttempt ولا جواهر ولا XP ولا تقدّم حملات ولا رعاية (E30/E43/اقتصاد محفوظ كما هو).
 * في الإصدار 1 تُدعم الأحجيات الكلاسيكية (نصية/اختيار/صورة: game_type فارغ). الأنواع التفاعلية الأخرى (تسلسل/ذاكرة/اختلافات) تحتاج عارضها
 * الخاص وهي خارج النطاق الآن؛ تُرفض بوضوح عند الربط وعند فحص الجاهزية.
 */
class CinematicPuzzleBridge
{
    public function __construct(protected GameTypeRegistry $games) {}

    /** سبب عدم صلاحية الأحجية للربط، أو null إن صلحت. */
    public function ineligibleReason(?Puzzle $puzzle): ?string
    {
        if ($puzzle === null) {
            return 'الأحجية غير موجودة.';
        }

        if ($puzzle->isCommunity()) {
            return 'أحجيات المجتمع لا تُربط بالسلاسل السينمائية.';
        }

        if (! $puzzle->is_active) {
            return 'الأحجية غير مفعّلة.';
        }

        if (filled($puzzle->game_type)) {
            return 'نوع لعبة الأحجية غير مدعوم داخل المشاهد في هذا الإصدار (الكلاسيكية فقط).';
        }

        if (blank($puzzle->answer_hash)) {
            return 'الأحجية بلا إجابة معتمدة.';
        }

        return null;
    }

    /**
     * استعلام الأحجيات الصالحة للربط: يطابق ineligibleReason() بالضبط (رسمية، مفعّلة، كلاسيكية بلا game_type، بإجابة معتمدة).
     * مصدر واحد للقاعدة يخدم بحث محدّد الأحجية على الخادم؛ والتكافؤ بين الاثنين مُثبَت بالاختبار.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Puzzle>
     */
    public function eligibleQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Puzzle::query()->official()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('game_type')->orWhere('game_type', ''))
            ->whereNotNull('answer_hash')->where('answer_hash', '!=', '');
    }

    public function check(Puzzle $puzzle, string $answer): bool
    {
        return $this->games->validatorFor($puzzle)->check($puzzle, ['answer' => mb_substr($answer, 0, 500)])->correct;
    }

    /** @return array<string, mixed> الحقول العامة فقط (لا إجابة ولا تلميح مدفوع ولا هاش). */
    public function publicView(Puzzle $puzzle): array
    {
        $choices = is_array($puzzle->choices) ? array_values(array_map(fn ($c) => (string) $c, $puzzle->choices)) : [];
        $image = filled($puzzle->image_path) ? Storage::disk('public')->url((string) $puzzle->image_path) : null;

        return [
            'title' => (string) $puzzle->title,
            'prompt' => (string) $puzzle->prompt,
            'type' => (string) $puzzle->type,
            'choices' => $choices,
            'image_url' => $image,
            'max_attempts' => (int) $puzzle->max_attempts,
        ];
    }
}
