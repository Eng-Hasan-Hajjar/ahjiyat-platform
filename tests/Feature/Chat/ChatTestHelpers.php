<?php

require_once __DIR__.'/../Teams/TeamTestHelpers.php';

use App\Models\ChatThread;
use App\Models\User;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatMuteService;
use App\Services\Chat\ChatThreadService;
use App\Services\Social\BlockService;

/** مساعدات E21: كلها عبر الخدمات الحقيقية. الصداقة/الحظر/الفرق بخدمات E16/E19 نفسها. */
if (! function_exists('chatMessages')) {
    function chatMessages(): ChatMessageService
    {
        return app(ChatMessageService::class);
    }

    function chatThreads(): ChatThreadService
    {
        return app(ChatThreadService::class);
    }

    function chatMutes(): ChatMuteService
    {
        return app(ChatMuteService::class);
    }

    function chatBlocks(): BlockService
    {
        return app(BlockService::class);
    }

    /** صديقان مقبولان. @return array{0: User, 1: User} */
    function chatFriends(): array
    {
        $a = e16User(['name' => 'أحمد']);
        $b = e16User(['name' => 'بسمة']);
        e16Befriend($a, $b);

        return [$a, $b];
    }

    /** يرسل رسالة مباشرة عبر HTTP (JSON) من $from إلى $to. */
    function chatDm($test, User $from, User $to, string $body = 'مرحبا')
    {
        return $test->actingAs($from)->postJson(route('messages.direct.send', $to), ['body' => $body]);
    }

    /** مشرف بالصلاحيات الفعلية (يحتاج Seeder الأدوار لإنشاء الصلاحيات). */
    function chatModerator(array $permissions = ['chat.moderate', 'chat.reports.view']): User
    {
        return e16User(['name' => 'مشرف'])->givePermissionTo($permissions);
    }

    /** غرفة فريق جاهزة بمالك وعضو ومشرف. @return array{0: \App\Models\Team, 1: User, 2: User, 3: User} */
    function chatTeam(): array
    {
        $team = e19Team(null, ['name' => 'فريق الدردشة '.\Illuminate\Support\Str::random(4)]);
        $admin = e19Member($team, null, 'admin');
        $member = e19Member($team);

        return [$team->refresh(), $team->owner, $admin, $member];
    }

    function chatGlobal(): ChatThread
    {
        return chatThreads()->global();
    }

    /** يتحقق من تخويل قناة خاصة بنفس مسار البثّ (callback مسجَّل بـroutes/channels.php على السائق الافتراضي). */
    function chatChannelAllows(?User $user, string $channel): bool
    {
        if ($user === null) {
            return false;
        }

        $driver = \Illuminate\Support\Facades\Broadcast::driver();
        $request = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST');
        $request->setUserResolver(fn () => $user);
        $method = new \ReflectionMethod(\Illuminate\Broadcasting\Broadcasters\Broadcaster::class, 'verifyUserCanAccessChannel');

        try {
            $method->invoke($driver, $request, $channel);     // بلا استثناء = مسموح (NullBroadcaster يعيد null عند النجاح)؛ الرفض = AccessDeniedHttpException

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            return false;
        }
    }
}
