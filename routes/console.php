<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Services\GemWalletService;
use Illuminate\Support\Facades\Schedule;

// تحويل الجواهر المعلقة إلى متاحة يومياً بعد انتهاء فترة التعليق (config('gems.pending_hold_days'))
Schedule::call(function () {
    $walletService = app(GemWalletService::class);
    $holdDays = config('gems.pending_hold_days');

    User::whereHas('wallet', fn ($q) => $q->where('pending_balance', '>', 0))
        ->with('wallet')
        ->chunk(200, function ($users) use ($walletService, $holdDays) {
            foreach ($users as $user) {
                $releasable = GemTransaction::where('user_id', $user->id)
                    ->where('type', GemTransaction::TYPE_EARN_PENDING)
                    ->where('created_at', '<=', now()->subDays($holdDays))
                    ->sum('amount');

                $alreadyReleased = GemTransaction::where('user_id', $user->id)
                    ->where('type', GemTransaction::TYPE_RELEASE_AVAILABLE)
                    ->sum('amount');

                $toRelease = $releasable - $alreadyReleased;

                if ($toRelease > 0) {
                    $walletService->releasePendingToAvailable($user, (int) $toRelease, 'pending_hold_expired');
                }
            }
        });
})->daily()->name('release-pending-gems')->withoutOverlapping();

// تذكيرات "ينتهي قريبًا" للمواسم والحملات المستقلة (قراءة فقط) - ساعيًا، وقبل تذكيرات العودة بنفس الساعة (ترتيب التسجيل)
// كي يسبق ينتهي-قريبًا تذكير المهام الأدنى أولوية. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
Schedule::command('notifications:dispatch-lifecycle-reminders')->hourly()->name('notifications-lifecycle-reminders')->withoutOverlapping();

// E15: تذكيرات العودة (تحذير السلسلة + مهام اليوم) - ساعيًا. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
Schedule::command('notifications:dispatch-reengagement')->hourly()->name('notifications-reengagement')->withoutOverlapping();

// E15: تنظيف المقروء القديم يوميًا - صحة النظام لا تعتمد عليه.
Schedule::command('notifications:prune')->daily()->name('notifications-prune')->withoutOverlapping();

// التقاط بدء المواسم الناتج عن مرور الوقت. غيابه لا يكسر شيئًا: البدء بالحفظ الإداري يُلتقَط فورًا بخطافات النماذج.
Schedule::command('seasons:sync-live-state')->everyFiveMinutes()->name('seasons-sync-live-state')->withoutOverlapping();

// التقاط إتاحة الحملات الناتجة عن مرور الوقت. غيابه لا يكسر شيئًا: الإتاحة بالحفظ الإداري تُلتقَط فورًا بخطاف النموذج.
Schedule::command('campaigns:sync-availability')->everyFiveMinutes()->name('campaigns-sync-availability')->withoutOverlapping();
