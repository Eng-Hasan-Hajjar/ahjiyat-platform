<?php

namespace Database\Seeders\Demo;

use App\Models\TeamChallenge;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Database\Seeder;

/**
 * مركز إشعارات واقعي وغير مزدحم (G): مجموعة **منتقاة** (≈5–10 لكل حساب رئيسي فوق إشعارَي الموسم الموجودين) بالأنواع الحقيقية وبالموزّع الحقيقي (فتُطبَّق التفضيلات والمفتاح الشامل)،
 * بتواريخ نسبية، ومزيج مقروء/غير مقروء، وروابط داخلية آمنة فقط. المفاتيح الدلالية بصيغة `demo-qa:...` فريدة لكل مستخدم: Idempotent، ولا تصطدم بمفاتيح الأحداث الحقيقية.
 * التاريخ التجريبي نفسه بُني بموزّع صامت، فلا تتولد عشرات الإشعارات التلقائية.
 */
class DemoNotificationSeeder extends Seeder
{
    use DemoSupport;

    public function run(): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        foreach ($this->rows() as [$key, $type, $ref, $params, $ago, $read, $route, $routeParams]) {
            $user = $this->user($key);
            $semantic = "demo-qa:{$type->value}:{$ref}";
            $when = now()->sub($ago);

            $this->at($when, fn () => $dispatcher->dispatch($user, $type, $params, $semantic, ['demo' => true], $routeParams, $route));
            $note = $user->notifications()->where('idempotency_key', $semantic)->first();

            if ($note !== null && $read && $note->read_at === null) {
                $note->forceFill(['read_at' => $when->copy()->addHours(3)])->save();
            }
        }

        $this->say('إشعارات: مجموعة منتقاة (مقروء/غير مقروء) لأهم الحسابات بالأنواع الحقيقية.');
    }

    /** @return list<array{0: string, 1: NotificationType, 2: string, 3: array, 4: \DateInterval, 5: bool, 6: ?string, 7: array}> */
    protected function rows(): array
    {
        $pending = TeamChallenge::query()->where('status', 'pending')->first();
        $completed = TeamChallenge::query()->where('status', 'completed')->orderBy('id')->get();
        $h = fn (int $n) => new \DateInterval("PT{$n}H");
        $d = fn (int $n) => new \DateInterval("P{$n}D");
        $champ = fn (string $slug) => ['championship' => $slug];
        $tc = fn (?TeamChallenge $c) => $c ? ['challenge' => $c->public_id] : [];

        $rows = [
            // ---- يوسف (الحساب الرئيسي): 9 + إشعارا الموسم الموجودان
            ['yousef', NotificationType::FriendRequestReceived, 'reem', ['name' => 'ريم الشامي'], $d(2), false, null, []],
            ['yousef', NotificationType::TeamJoinRequestReceived, 'khaled', ['name' => 'خالد النجار', 'team' => 'فرسان الشام'], $d(1), false, null, []],
            ['yousef', NotificationType::FriendChallengeReceived, 'sara-c10', ['name' => 'سارة القيسي'], $h(3), false, null, []],
            ['yousef', NotificationType::TeamChallengeReceived, 'falcons', ['team' => 'صقور المعرفة'], $h(5), false, $pending ? 'teams.challenges.show' : null, $tc($pending)],
            ['yousef', NotificationType::TeamChampionshipResultReady, 'autumn', ['title' => 'بطولة الفرق: كأس الخريف'], $d(2), false, 'team-championships.show', $champ('demo-championship-autumn')],
            ['yousef', NotificationType::CompetitiveEventResultReady, 'cup-4', ['title' => 'كأس الألغاز - الجولة 4', 'rank' => 1, 'placement' => 1], $d(4), true, null, []],
            ['yousef', NotificationType::CompetitiveRewardGranted, 'cup-3', ['title' => 'كأس الألغاز - الجولة 3', 'reward' => '60 هلال رمضان'], $d(10), true, null, []],
            ['yousef', NotificationType::TeamChallengeResultReady, 'win-stars', ['team' => 'نجوم الأحجيات', 'outcome' => 'فاز فريقكم'], $d(6), true, $completed->get(0) ? 'teams.challenges.show' : null, $tc($completed->get(0))],
            ['yousef', NotificationType::AchievementUnlocked, 'first-win', ['title' => 'أول فوز'], $d(4), true, null, []],

            // ---- سارة
            ['sara', NotificationType::FriendRequestAccepted, 'yousef', ['name' => 'يوسف الحمدان'], $d(40), true, null, []],
            ['sara', NotificationType::CompetitiveEventResultReady, 'cup-2', ['title' => 'كأس الألغاز - الجولة 2', 'rank' => 1, 'placement' => 1], $d(17), true, null, []],
            ['sara', NotificationType::TeamChampionshipResultReady, 'spring', ['title' => 'بطولة الفرق: كأس الربيع'], $d(14), false, 'team-championships.show', $champ('demo-championship-spring')],
            ['sara', NotificationType::TeamChallengeResultReady, 'win-knights', ['team' => 'فرسان الشام', 'outcome' => 'فاز فريقكم'], $d(3), false, $completed->get(1) ? 'teams.challenges.show' : null, $tc($completed->get(1))],
            ['sara', NotificationType::CampaignCompleted, 'aseel', ['campaign' => 'أصيل — الفتى الذي يسمع أكثر مما ينبغي'], $d(12), true, null, []],

            // ---- ريم
            ['reem', NotificationType::TeamInvitationAccepted, 'shatha', ['name' => 'شذى مراد', 'team' => 'عباقرة الشرق'], $d(45), true, null, []],
            ['reem', NotificationType::CompetitiveEventResultReady, 'cup-1', ['title' => 'كأس الألغاز - الجولة 1', 'rank' => 1, 'placement' => 1], $d(24), true, null, []],
            ['reem', NotificationType::CompetitiveRewardGranted, 'cup-3', ['title' => 'كأس الألغاز - الجولة 3', 'reward' => '150 هلال رمضان'], $d(10), false, null, []],
            ['reem', NotificationType::TeamChallengeResultReady, 'draw-knights', ['team' => 'فرسان الشام', 'outcome' => 'انتهت المباراة بالتعادل'], $d(1), false, $completed->get(2) ? 'teams.challenges.show' : null, $tc($completed->get(2))],
            ['reem', NotificationType::TeamChampionshipStarted, 'winter', ['title' => 'بطولة الفرق: كأس الشتاء (جارية)'], $d(6), true, 'team-championships.show', $champ('demo-championship-winter')],

            // ---- عمر (مشرف فريق يوسف)
            ['omar', NotificationType::TeamChallengeResultReady, 'win-stars', ['team' => 'نجوم الأحجيات', 'outcome' => 'فاز فريقكم'], $d(6), true, $completed->get(0) ? 'teams.challenges.show' : null, $tc($completed->get(0))],
            ['omar', NotificationType::TeamChampionshipResultReady, 'autumn', ['title' => 'بطولة الفرق: كأس الخريف'], $d(2), false, 'team-championships.show', $champ('demo-championship-autumn')],
            ['omar', NotificationType::CompetitiveEventResultReady, 'cup-4', ['title' => 'كأس الألغاز - الجولة 4', 'rank' => 3, 'placement' => 3], $d(4), false, null, []],

            // ---- كنان (مستخدم جديد بدعوة معلّقة) وخالد (طلب صداقة وارد)
            ['kenan', NotificationType::TeamInvitationReceived, 'knights', ['team' => 'فرسان الشام'], $d(2), false, 'teams.invitations', []],
            ['khaled', NotificationType::FriendRequestReceived, 'yousef', ['name' => 'يوسف الحمدان'], $d(1), false, null, []],

            // ---- نور الدين (مالك الفريق الخاص)
            ['noureddine', NotificationType::TeamChallengeReceived, 'knights', ['team' => 'فرسان الشام'], $d(6), true, $completed->get(0) ? 'teams.challenges.show' : null, $tc($completed->get(0))],
            ['noureddine', NotificationType::FriendChallengeReceived, 'yousef-c09', ['name' => 'يوسف الحمدان'], $h(20), true, null, []],

            // ---- أعضاء فرسان الشام الذين انضموا بطلبات
            ['layan', NotificationType::TeamJoinRequestAccepted, 'knights', ['team' => 'فرسان الشام'], $d(44), true, null, []],
            ['layan', NotificationType::FriendRequestAccepted, 'yousef', ['name' => 'يوسف الحمدان'], $d(9), true, null, []],
            ['jana', NotificationType::TeamJoinRequestAccepted, 'knights', ['team' => 'فرسان الشام'], $d(43), true, null, []],
        ];

        return array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7]], $rows);
    }
}
