<?php

namespace App\Console\Commands;

use App\Services\Notifications\LifecycleReminderService;
use Illuminate\Console\Command;

/**
 * تذكيرات "ينتهي قريبًا" للمواسم والحملات المستقلة. لا منطق هنا: كل القرار بـLifecycleReminderService (مشترك للنوعين).
 * قراءة فقط + إشعارات. Idempotent (المفتاح الدلالي)، ويُجدوَل ساعيًا.
 */
class DispatchLifecycleReminders extends Command
{
    protected $signature = 'notifications:dispatch-lifecycle-reminders';

    protected $description = 'تذكيرات "ينتهي قريبًا" لموسم/حملة مستقلة لمن لديه تقدّم فعلي ولم يكملها. قراءة فقط، Idempotent، ساعيًا.';

    public function handle(LifecycleReminderService $service): int
    {
        $s = $service->run();

        $this->info(sprintf(
            'حملات ضمن النافذة (%d ساعة): %d | مرشَّحون: %d | مكتملون: %d | أُرسل سابقًا: %d | فوق الميزانية: %d | أُنشئ: %d | مُوقَف (إعدادات): %d | فشل: %d',
            $service->windowHours(), $s['campaigns'], $s['candidates'], $s['completed'], $s['already'], $s['over_budget'], $s['created'], $s['suppressed'], $s['failed'],
        ));

        return self::SUCCESS;
    }
}
