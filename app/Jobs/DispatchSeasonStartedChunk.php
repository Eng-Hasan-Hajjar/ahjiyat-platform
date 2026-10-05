<?php

namespace App\Jobs;

use App\Models\Season;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * دفعة واحدة (chunk_size مستخدمًا) من إشعارات "بدأ الموسم" ثم يُجدوِل الدفعة التالية بمؤشر id - فلا Job ضخمة ولا
 * تحميل لكل المستخدمين. إعادة تشغيل أي دفعة آمنة تمامًا: المفتاح الدلالي season-start:{season}:{user} + القيد
 * الفريد يمنعان التكرار. الجمهور: مستخدمون موثَّقون وغير مجمَّدين فقط (الصندوق لا يظهر لغيرهم). التفضيل والمفتاح
 * الشامل يُفحصان لحظة الإرسال داخل NotificationDispatcher نفسه. لا XP ولا عملة ولا تقدّم ولا سلسلة ولا مكافأة.
 */
class DispatchSeasonStartedChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $seasonId, public int $afterUserId = 0) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $season = Season::query()->with('campaign')->find($this->seasonId);

        // الموسم حُذف، أو لم يبدأ فعلًا (حماية من استدعاء يدوي خاطئ): لا إشعار.
        if ($season === null || $season->went_live_at === null) {
            return;
        }

        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $users = User::query()
            ->where('id', '>', $this->afterUserId)
            ->where('is_frozen', false)
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->limit($size)
            ->get();

        foreach ($users as $user) {
            $dispatcher->dispatch(
                $user,
                NotificationType::SeasonStarted,
                ['name' => $season->campaign?->title ?? $season->code],
                "season-start:{$season->id}:{$user->id}",
                ['season_id' => $season->id],
                ['season' => $season->slug],
            );
        }

        if ($users->count() === $size) {
            self::dispatch($this->seasonId, (int) $users->last()->id);
        }
    }
}
