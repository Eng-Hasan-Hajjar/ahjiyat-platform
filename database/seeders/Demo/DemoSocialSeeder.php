<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Services\Social\BlockService;
use App\Services\Social\FriendRelation;
use App\Services\Social\FriendshipService;
use Illuminate\Database\Seeder;

/**
 * سيناريوهات الاجتماع (A): صداقات وطلبات وحظر وخصوصية، **بالخدمات الرسمية** (FriendshipService/BlockService) وبزمن محاكى نسبي للآن. Idempotent: الحالة تُفحص قبل كل إجراء.
 * الإشعارات التاريخية صامتة (الإشعارات المعروضة تنشئها DemoNotificationSeeder بمجموعة منتقاة).
 *
 * يوسف (الحساب الرئيسي): أصدقاء مقبولون سارة/نور الدين/ليان/جنى/ياسر | وارد: ريم | صادر: خالد | حظر منه: لمى | حظره: فراس | بلا علاقة: عمر | لا يستقبل طلبات: دانة.
 */
class DemoSocialSeeder extends Seeder
{
    use DemoSupport;

    /** ظهور الملف العام (private هو الافتراضي: يبقى للمجمَّدين وغير الموثَّقين). */
    public const VISIBILITY = [
        'public' => ['yousef', 'sara', 'reem', 'omar', 'noureddine', 'layan', 'jana', 'yaser', 'malak', 'wisam', 'raghad', 'basel', 'shatha', 'anas', 'eman', 'adnan', 'khaled', 'dana'],
        'members' => ['kenan', 'lama', 'firas'],
    ];

    /** [أ, ب, قبل كم يومًا قُبلت] */
    public const FRIENDSHIPS = [
        ['yousef', 'sara', 40], ['yousef', 'noureddine', 32], ['yousef', 'yaser', 16], ['yousef', 'layan', 9], ['yousef', 'jana', 7],
        ['sara', 'malak', 22], ['sara', 'wisam', 4], ['noureddine', 'layan', 8], ['reem', 'shatha', 50], ['reem', 'anas', 38],
    ];

    public function run(): void
    {
        $this->quiet(function () {
            $this->visibility();
            $friends = app(FriendshipService::class);

            foreach (self::FRIENDSHIPS as [$a, $b, $days]) {
                $this->befriend($friends, $a, $b, $days);
            }

            // وارد: ريم → يوسف (قبل يومين). صادر: يوسف → خالد (أمس).
            $this->request($friends, 'reem', 'yousef', 2);
            $this->request($friends, 'yousef', 'khaled', 1);

            // حظر: يوسف حظر لمى؛ فراس حظر يوسف. (بعد الصداقات: لا علاقة سابقة بينهم)
            $blocks = app(BlockService::class);
            $this->at(now()->subDays(11), fn () => $blocks->block($this->user('yousef'), $this->user('lama')));
            $this->at(now()->subDays(12), fn () => $blocks->block($this->user('firas'), $this->user('yousef')));

            // لا يستقبل طلبات صداقة (يختبر الرفض بالواجهة).
            User::query()->whereKey($this->user('dana')->getKey())->update(['friend_requests_enabled' => false]);
        });

        $this->say('اجتماعي: صداقات + طلب وارد/صادر + حظر بالاتجاهين + مستخدم لا يستقبل طلبات.');
    }

    protected function visibility(): void
    {
        foreach (self::VISIBILITY as $level => $keys) {
            User::query()->whereIn('email', array_map(fn ($k) => $k.'@ahjiyat.test', $keys))->update(['profile_visibility' => $level]);
        }
    }

    protected function befriend(FriendshipService $friends, string $a, string $b, int $days): void
    {
        $ua = $this->user($a);
        $ub = $this->user($b);

        if ($friends->relationBetween($ua, $ub) === FriendRelation::Friends) {
            return;
        }

        $this->at(now()->subDays($days)->setTime(18, 0), fn () => $friends->sendRequest($ua, $ub));
        $this->at(now()->subDays($days)->setTime(19, 30), fn () => $friends->accept($ub, $ua));
    }

    protected function request(FriendshipService $friends, string $from, string $to, int $days): void
    {
        $uf = $this->user($from);
        $ut = $this->user($to);

        if ($friends->relationBetween($uf, $ut) === FriendRelation::None) {
            $this->at(now()->subDays($days)->setTime(20, 15), fn () => $friends->sendRequest($uf, $ut));
        }
    }
}
