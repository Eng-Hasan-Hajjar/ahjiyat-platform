<?php

namespace App\Services;

use App\Models\CampaignGate;
use App\Models\CampaignStep;
use App\Models\User;
use App\Services\Qualification\FirstNQualificationRule;
use App\Services\Qualification\QualificationRule;

/**
 * الموزّع الوحيد بين قيمة gate.qualification_rule وصنف الـRule الفعلي.
 * null أو أي قيمة غير معروفة = لا Qualification خاصة (C6.9) - لا نجعل
 * first_n افتراضياً لأي بوابة أبداً. تُستدعى من نقاط اكتمال الخطوة
 * الثلاث (narrative/puzzle stateless/GameSession) - وليس من GameSessionService
 * نفسها (تبقى عامة تماماً)، بل من الـController العام الذي يعرف Context فقط.
 */
class QualificationService
{
    public function __construct(protected CampaignProgressService $progress) {}

    public function ruleFor(CampaignGate $gate): ?QualificationRule
    {
        return match ($gate->qualification_rule) {
            'first_n' => app(FirstNQualificationRule::class),
            default => null,
        };
    }

    /**
     * آمنة الاستدعاء دائماً وبأي عدد من المرات بعد أي إكمال خطوة - تفحص
     * أولاً هل الـGate الحاوية أصبحت مكتملة فعلياً (Derived)، وإن كانت
     * ولها Rule، تُشغّلها (Idempotent بذاتها). لا شيء يحدث خلاف ذلك.
     */
    public function afterStepCompletion(User $user, CampaignStep $step): void
    {
        $gate = $step->gate;
        $rule = $this->ruleFor($gate);

        if ($rule === null) {
            return;
        }

        if (! $this->progress->isGateCompleted($user, $gate)) {
            return;
        }

        $rule->qualify($user, $gate);
    }

    public function isUserQualified(User $user, CampaignGate $gate): bool
    {
        $rule = $this->ruleFor($gate);

        // لا Rule = تأهّل غير مشروط بمجرد الاكتمال (نفس فلسفة C2/C6.9)
        return $rule === null || $rule->isQualified($user, $gate);
    }
}