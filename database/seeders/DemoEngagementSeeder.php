<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E29 - بيانات Demo للتفاعل المجتمعي.   php artisan db:seed --class=DemoEngagementSeeder
 *
 * - اختيارية وترفض الإنتاج. **تعتمد على DemoCreatorSeeder** (تستدعيه إن لم يوجد مبدعون) ولا تُستدعى من DemoQaSeeder: أرقام Demo المثبَّتة بالاختبارات لا تتغير.
 * - **بالخدمات الرسمية فقط** (لعب مجتمع، تقييم، مفضلة، متابعة، مجموعات): لا كتابة مباشرة بالجداول ولا بالعدّادات، فتبقى العدّادات مطابقة للصفوف.
 *   لا محافظ ولا XP ولا محاولات رسمية (puzzle_attempts) ولا أي أثر على الصدارة/المهام: التفاعل بلا سلطة اقتصادية بالبناء.
 * - **Idempotent**: تُعاد دون تضاعف (كل خدمة تتجاهل المكرَّر؛ المجموعات بالعنوان لكل مستخدم).
 * - حسابات تجريبية بكلمة المرور المعروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoEngagementSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->refuseInProduction('DemoEngagementSeeder');

        if (! CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        $works = CreatorPuzzle::query()->visible()->orderBy('id')->limit(6)->get();

        if ($works->isEmpty()) {
            $this->command?->warn('DemoEngagementSeeder: لا أعمال مجتمع منشورة للتفاعل معها.');

            return;
        }

        $creators = CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->orderBy('id')->get();

        $players = collect(['a' => 'متفاعل أ', 'b' => 'متفاعل ب', 'c' => 'متفاعل ج', 'd' => 'متفاعل د', 'e' => 'متفاعل هـ', 'f' => 'متفاعل و'])
            ->map(fn (string $name, string $key) => $this->user("demo-engager-{$key}@engagement.test", $name))->values();

        $play = app(CommunityPlayService::class);
        $ratings = app(CommunityRatingService::class);
        $favorites = app(CommunityFavoriteService::class);
        $follows = app(CreatorFollowService::class);
        $collections = app(CollectionService::class);

        foreach ($players as $i => $player) {
            foreach ($works as $j => $work) {
                $work = $work->fresh();

                if (! $work->is_visible || (int) $work->creator->user_id === $player->id) {
                    continue;
                }

                // محاولة واحدة على الأقل (شرط أهلية التقييم). أنواع لا تقبل إجابة نصية خام قد ترمي استثناءً: لا يهم للعرض.
                try {
                    $play->attempt($player, $work, '__غير صحيحة__', []);
                } catch (\Throwable) {
                }

                // أنماط تقييم متفاوتة ومتكررة حتميًا (بلا عشوائية) فيتضح الفرق بين المتوسط الخام والدرجة المنكمشة.
                $value = [5, 4, 5, 3, 4, 5][($i + $j) % 6];

                try {
                    $ratings->set($player, $work, $j === 0 ? 5 : $value);
                } catch (\Throwable) {
                }
            }

            foreach ($works->take(2 + ($i % 2)) as $work) {
                try {
                    $favorites->add($player, $work->fresh());
                } catch (\Throwable) {
                }
            }

            foreach ($creators as $creator) {
                try {
                    $follows->follow($player, $creator);
                } catch (\Throwable) {
                }
            }
        }

        // 2–3 مجموعات خاصة لأول مستخدمين (بالعنوان: idempotent).
        foreach ([[$players[0], 'ألغاز المساء', $works->take(3)], [$players[0], 'للتحدي مع الأصدقاء', $works->slice(3, 2)], [$players[1], 'مفضلات هادئة', $works->take(2)]] as [$owner, $title, $items]) {
            $collection = \App\Models\UserCollection::query()->where('user_id', $owner->id)->where('title', $title)->first() ?? $collections->create($owner, $title, 'مجموعة تجريبية خاصة بصاحبها.');

            foreach ($items as $work) {
                try {
                    $collections->add($owner, $collection->public_id, $work->fresh());
                } catch (\Throwable) {
                }
            }
        }

        $this->command?->info('DemoEngagementSeeder: لاعبون ('.$players->count().')، تقييمات، مفضلة، متابعات، مجموعات. كلمة المرور: '.self::PASSWORD);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
