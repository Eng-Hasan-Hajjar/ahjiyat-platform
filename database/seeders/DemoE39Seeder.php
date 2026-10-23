<?php

namespace Database\Seeders;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorRevenuePeriod;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorFinance\CreatorPayoutService;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E39 - بيانات Demo لمالية المبدعين.   php artisan db:seed --class=DemoE39Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق finance.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من أي seeder آخر.
 * - مستقلة: تبني مبدعَين تجريبيين بأعمال نصية بإجابات معروفة (طلب انضمام/مسودة/إرسال/اعتماد عبر خدمات E28)، ولاعبين يحلّونها عبر CommunityPlayService بإجابات صحيحة حقيقية،
 *   ثم تفعيل التسييل والفترات والإقفال وطلب الدفعة عبر خدمات E39. لا إدخال مباشر في جداول الدفتر.
 *   الاستثناء الوحيد والموثَّق: نقل **وقت** محاولات اللعب التجريبية داخل نافذة الفترة (بياناتُ مصدر، لا مالية)، إذ لا يمكن لعبُ الماضي.
 * - المشهد: فترة مُقفلة بمجمّع وهمي تُوزَّع على مبدعين مؤهلين، وطلب دفعة مفتوح لأحدهم، وفترة مسودة ثانية. لا جواهر ولا XP ولا أي أثر اقتصادي، ولا وجهة تحويل.
 * - Idempotent: علامة العرض في اسم الفترة؛ إعادة التشغيل لا تضاعف فترة ولا دفتر ولا طلب.
 */
class DemoE39Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-finance-admin@finance.test';

    public const MARK = 'عرض تجريبي E39';

    public function run(): void
    {
        $this->refuseInProduction('DemoE39Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير مالية المبدعين التجريبي', 'administrator');

        if (CreatorRevenuePeriod::query()->where('label', 'like', self::MARK.'%')->exists()) {
            $this->command?->info('DemoE39Seeder: بيانات العرض موجودة بالفعل (لا تكرار).');

            return;
        }

        $profiles = $this->creators();

        if ($profiles === []) {
            $this->command?->warn('DemoE39Seeder: تعذّر بناء مبدعين تجريبيين بأعمال منشورة.');

            return;
        }

        $monetization = app(CreatorMonetizationService::class);

        foreach ($profiles as $profile) {
            $this->tryDo(fn () => $monetization->enable($profile, $admin, self::MARK));
        }

        $this->playAsDemoPlayers(array_map(fn (CreatorProfile $p) => (int) $p->id, $profiles));

        $periods = app(CreatorRevenuePeriodService::class);
        $first = null;

        $this->tryDo(function () use ($periods, $admin, &$first) {
            $draft = $periods->create($admin, [
                'label' => self::MARK.' — فترة مُقفلة',
                'starts_at' => now()->subDays(12)->startOfDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->subDays(3)->startOfDay()->format('Y-m-d H:i:s'),
                'pool' => '1000.00',
                'note' => 'مجمّع وهمي لأغراض العرض فقط.',
            ]);
            $first = $periods->finalize($periods->submitForReview($draft, $admin), $admin);
        });

        $this->tryDo(function () use ($periods, $admin) {
            $periods->create($admin, [
                'label' => self::MARK.' — مسودة',
                'starts_at' => now()->subDays(3)->startOfDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->subDays(1)->startOfDay()->format('Y-m-d H:i:s'),
                'pool' => '250.00',
            ]);
        });

        if ($first !== null) {
            $this->tryDo(function () use ($profiles) {
                $owner = User::query()->find($profiles[0]->user_id);

                if ($owner instanceof User) {
                    app(CreatorPayoutService::class)->request($owner, '100.00', 'bank_transfer');
                }
            });
        }

        $this->command?->info('DemoE39Seeder: أُنشئت فترة مُقفلة وفترة مسودة وطلب دفعة مفتوح. الدخول: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    /** مبدعان تجريبيان بعملين نصيين منشورين لكلٍّ (إجابتهما «القلم»). @return list<CreatorProfile> */
    protected function creators(): array
    {
        $category = PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? PuzzleCategory::query()->create(['name' => 'متنوعة', 'slug' => 'misc', 'sort_order' => 99, 'is_active' => true]);
        $reviewer = $this->user('demo-finance-reviewer@finance.test', 'مراجع مالية تجريبي', 'moderator');
        $applications = app(CreatorApplicationService::class);
        $puzzles = app(CreatorPuzzleService::class);
        $review = app(CreatorReviewService::class);
        $profiles = [];

        for ($i = 1; $i <= 2; $i++) {
            $user = $this->user("demo-finance-creator-{$i}@finance.test", "مبدع مالية {$i}");
            $profile = CreatorProfile::query()->where('user_id', $user->id)->first() ?? $applications->apply($user, 'مبدع تجريبي لعرض مالية المبدعين؛ أصنع ألغازًا قصيرة.');

            if ($profile->status === CreatorProfile::STATUS_PENDING) {
                $applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
                $profile = $profile->fresh();
            }

            for ($n = 1; $n <= 2; $n++) {
                $this->tryDo(function () use ($user, $puzzles, $review, $reviewer, $category, $i, $n) {
                    $draft = $puzzles->createDraft($user, [
                        'kind' => 'text', 'difficulty' => 'easy', 'title' => "لغز مالية {$i}-{$n}", 'puzzle_category_id' => $category->id,
                        'prompt' => 'ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده على المكتب', 'answer' => 'القلم',
                    ]);
                    $review->approve($puzzles->submit($user, $draft->work->public_id), $reviewer, 'معتمدة للعرض التجريبي.');
                });
            }

            if ($profile->isApproved()) {
                $profiles[] = $profile;
            }
        }

        return array_values(array_filter($profiles, fn (CreatorProfile $p) => CreatorPuzzle::query()->visible()->where('creator_profile_id', $p->id)->exists()));
    }

    /** ستة لاعبين تجريبيين يحلّون كل أعمال المبدعَين بالإجابة الصحيحة عبر خدمة اللعب الرسمية. @param list<int> $profileIds */
    protected function playAsDemoPlayers(array $profileIds): void
    {
        $players = [];

        for ($i = 1; $i <= 6; $i++) {
            $players[] = $this->user("demo-finance-player-{$i}@finance.test", "لاعب مالية {$i}");
        }

        $works = CreatorPuzzle::query()->visible()->whereIn('creator_profile_id', $profileIds)->orderBy('id')->get();
        $play = app(CommunityPlayService::class);

        foreach ($works as $work) {
            foreach ($players as $player) {
                $this->tryDo(fn () => $play->attempt($player, $work->fresh(), 'القلم', []));
            }
        }

        // محاولات اللعب تُنشأ بوقت «الآن»؛ تُنقل لداخل نافذة الفترة التجريبية (بيانات مصدر فقط، لا مالية).
        DB::table((new CommunityPuzzleAttempt)->getTable())->whereIn('user_id', collect($players)->pluck('id'))->update(['created_at' => now()->subDays(6)]);
    }

    protected function user(string $email, string $name, ?string $role = null): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE39Seeder: تخطّي خطوة - '.$e->getMessage());
        }
    }
}
