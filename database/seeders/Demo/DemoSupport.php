<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * أدوات مشتركة لبذور الديمو. كل شيء حتمي: مستخدمو UserSeeder بمفاتيح قصيرة ثابتة (البريد {key}@ahjiyat.test)، وتواريخ نسبية للآن، وتشغيل الخدمات الرسمية بزمن محاكى
 * (يُعاد الزمن دائمًا بـfinally). لا تعديل مباشر لأي رصيد أو XP أو تقدّم مشتق.
 */
trait DemoSupport
{
    /** @var array<string, User> */
    protected array $demoUsers = [];

    protected function user(string $key): User
    {
        return $this->demoUsers[$key] ??= User::query()->where('email', $key.'@ahjiyat.test')->first()
            ?? throw new \RuntimeException("مستخدم الديمو غير موجود: {$key}. شغّل UserSeeder أولًا (DemoQaSeeder يفعل ذلك).");
    }

    /** يشغّل العمل بزمن محاكى ثم يعيد الزمن السابق مهما حدث. */
    protected function at(CarbonInterface $when, \Closure $work): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($when);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /** يشغّل العمل والموزّع الصامت مربوط (الأحداث تعمل، لكن لا إشعارات). */
    protected function quiet(\Closure $work): mixed
    {
        $previous = app()->bound(NotificationDispatcher::class) ? app(NotificationDispatcher::class) : null;
        app()->instance(NotificationDispatcher::class, new DemoSilentNotificationDispatcher);

        try {
            return $work();
        } finally {
            $previous !== null ? app()->instance(NotificationDispatcher::class, $previous) : app()->forgetInstance(NotificationDispatcher::class);
        }
    }

    /** المراحل الصغيرة تعمل والطابور متزامن: توزيع الجوائز/ترتيب الفرق يجري داخل البذرة نفسها بالخدمات الرسمية لا بانتظار عامل. */
    protected function syncQueue(\Closure $work): mixed
    {
        $previous = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            return $work();
        } finally {
            config(['queue.default' => $previous]);
        }
    }

    protected function say(string $message): void
    {
        if (property_exists($this, 'command') && $this->command !== null) {
            $this->command->info($message);
        }
    }
}
