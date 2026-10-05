<?php

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\UserQuestProgress;
use App\Models\PlayerStreak;
use App\Models\StorePurchase;
use App\Services\Social\FriendshipService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/** مساعدات E16 (تُضمَّن بـrequire_once من كل ملف اختبار). */
if (! function_exists('e16User')) {
    /** لاعب قابل للاكتشاف (ملف public، موثَّق، غير مجمَّد) ما لم يُحدَّد غير ذلك. */
    function e16User(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['profile_visibility' => User::VISIBILITY_PUBLIC]); // ما يمرّره الاختبار يغلب الافتراضي
    }

    function e16Svc(): FriendshipService
    {
        return app(FriendshipService::class);
    }

    function e16Rows(User $a, User $b)
    {
        return Friendship::query()->forPair($a->id, $b->id)->get();
    }

    function e16Befriend(User $a, User $b): void
    {
        e16Svc()->sendRequest($a, $b);
        e16Svc()->accept($b, $a);
    }

    function e16Notes(string $type, ?User $user = null)
    {
        return DatabaseNotification::where('type_key', $type)->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
    }

    /** لقطة لكل ما لا يجوز أن تمسّه الصداقة: XP، عملة، مهام، سلسلة، إنجازات، مشتريات، أرصدة المحافظ. */
    function e16Snapshot(): array
    {
        return [
            DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(), PlayerStreak::count(),
            UserAchievementProgress::count(), StorePurchase::count(),
            (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
            (int) DB::table('player_progressions')->sum('total_xp'),
        ];
    }
}
