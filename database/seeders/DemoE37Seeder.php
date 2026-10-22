<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionSaveService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionSubjectRegistry;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\FeaturedCreatorService;
use App\Services\Reputation\ReputationRankings;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E37 - بيانات Demo للسمعة المجتمعية.   php artisan db:seed --class=DemoE37Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق reputation.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من DemoQaSeeder.
 * - تعتمد على أعمال مجتمع منشورة (تستدعي DemoCreatorSeeder إن لم توجد) وتبني حولها نشاطًا حقيقيًا **بالخدمات الرسمية فقط** (تقييم/مفضلة/متابعة/مجموعة عامة وحفظ/تعليق وإعجاب):
 *   فتولَّد اللقطات من سجلات السلطة نفسها لا بإدخال درجات يدويًا. لا جواهر ولا XP ولا مهام ولا أي أثر اقتصادي.
 * - تُبذر تعريفات الاعتراف (CommunityRecognitionSeeder) ثم تحسب اللقطات فتُفتح إنجازات الاعتراف المستحقة، وتختار المبدع «مختارًا» عبر الخدمة المدقَّقة.
 * - Idempotent: التقييم upsert، والباقي يتجاهل المكرَّر؛ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE37Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-reputation-admin@reputation.test';

    public const CURATOR_EMAIL = 'demo-curator@reputation.test';

    public const HELPER_EMAIL = 'demo-helper@reputation.test';

    public const RATERS = 10;

    public function run(): void
    {
        $this->refuseInProduction('DemoE37Seeder');
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(CommunityRecognitionSeeder::class);

        if (! CreatorPuzzle::query()->visible()->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        $works = CreatorPuzzle::query()->visible()->orderBy('id')->get();
        $profile = CreatorProfile::query()->approved()->withCount(['puzzles as visible_works_count' => fn ($q) => $q->where('is_visible', true)])
            ->orderByDesc('visible_works_count')->first();

        if ($profile === null || $works->count() < 3) {
            $this->command?->warn('DemoE37Seeder: لا أعمال مجتمع منشورة كافية (3 على الأقل).');

            return;
        }

        $mine = $works->where('creator_profile_id', $profile->id)->values();
        $raters = [];

        for ($i = 1; $i <= self::RATERS; $i++) {
            $raters[] = $this->user("demo-rater-{$i}@reputation.test", "مقيِّم {$i} (تجريبي)");
        }

        $this->engage($profile, $mine, $raters);
        $curator = $this->curate($works, $raters);
        $helper = $this->discuss($mine->first() ?? $works->first(), $raters);

        $ids = array_map(fn (User $u) => (int) $u->id, array_merge([$profile->user, $curator, $helper], $raters));
        $result = app(CommunityReputationService::class)->refreshUsers($ids);
        app(ReputationRankings::class)->flush();

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير السمعة التجريبي');
        $admin->syncRoles(['content-manager']);

        $this->tryDo(fn () => app(FeaturedCreatorService::class)->feature($profile, $admin, 'اختيار تجريبي لعرض الإبراز التحريري.'));

        $this->command?->info(sprintf('DemoE37Seeder: لقطات محسوبة=%d. مبدع=%s، منسّق=%s، مساهم=%s. كلمة المرور: %s', $result['processed'], $profile->slug, self::CURATOR_EMAIL, self::HELPER_EMAIL, self::PASSWORD));
    }

    /** @param \Illuminate\Support\Collection<int, CreatorPuzzle> $mine @param list<User> $raters */
    protected function engage(CreatorProfile $profile, $mine, array $raters): void
    {
        $ratings = [5, 4, 5, 4, 5, 4, 5, 4, 5, 5];

        foreach ($mine as $work) {
            foreach ($raters as $i => $rater) {
                $this->tryDo(fn () => app(CommunityRatingService::class)->set($rater, $work, $ratings[$i % count($ratings)]));

                if ($i < 6) {
                    $this->tryDo(fn () => app(CommunityFavoriteService::class)->add($rater, $work));
                }
            }
        }

        foreach (array_slice($raters, 0, 7) as $rater) {
            $this->tryDo(fn () => app(CreatorFollowService::class)->follow($rater, $profile));
        }
    }

    /** @param \Illuminate\Support\Collection<int, CreatorPuzzle> $works @param list<User> $raters */
    protected function curate($works, array $raters): User
    {
        $curator = $this->user(self::CURATOR_EMAIL, 'منسّق تجريبي', true);
        $svc = app(CollectionService::class);

        foreach ([['مجموعة السمعة الأولى', $works->take(3)], ['مجموعة السمعة الثانية', $works->slice(1, 3)]] as [$title, $set]) {
            if ($set->count() < 3) {
                continue;
            }

            $collection = UserCollection::query()->where('user_id', $curator->id)->where('title', $title)->first() ?? $svc->create($curator, $title, 'مجموعة تجريبية لعرض سمعة المنسّقين.');

            foreach ($set as $work) {
                $this->tryDo(fn () => $svc->add($curator, (string) $collection->public_id, $work));
            }

            $this->tryDo(fn () => app(CollectionVisibilityService::class)->set($curator, (string) $collection->public_id, UserCollection::VISIBILITY_PUBLIC));

            foreach (array_slice($raters, 0, 6) as $saver) {
                $this->tryDo(fn () => app(CollectionSaveService::class)->save($saver, (string) $collection->public_id));
            }
        }

        return $curator;
    }

    /** @param list<User> $raters */
    protected function discuss(CreatorPuzzle $work, array $raters): User
    {
        $helper = $this->user(self::HELPER_EMAIL, 'مساهم تجريبي', true);
        $subject = app(DiscussionSubjectRegistry::class)->resolve(DiscussionThread::TYPE_COMMUNITY_PUZZLE, (string) $work->public_id, null);

        if ($subject === null) {
            return $helper;
        }

        $bodies = ['تحليل مفيد: ابدأ من القرائن الأسهل.', 'نصيحة: لا تستعجل التلميح.', 'ملاحظة: الصياغة تحمل خدعة لطيفة.', 'اقتراح: جرّب الحل من النهاية.', 'خلاصة: أحجية متوازنة وممتعة.'];

        foreach ($bodies as $body) {
            $comment = DiscussionComment::query()->where('user_id', $helper->id)->where('body', $body)->first();

            if ($comment === null) {
                try {
                    $comment = app(DiscussionCommentService::class)->create($helper, $subject, $body, null);
                } catch (\Throwable) {
                    continue;
                }
            }

            foreach ($raters as $rater) {
                $this->tryDo(fn () => app(DiscussionLikeService::class)->like($rater, (string) $comment->public_id));
            }
        }

        return $helper;
    }

    protected function user(string $email, string $name, bool $publicProfile = false): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()] + ($publicProfile ? ['profile_visibility' => User::VISIBILITY_PUBLIC] : []))->save();

        return $user->fresh();
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
            // مكرَّر أو غير مؤهَّل: يُتجاهل (Demo فقط).
        }
    }
}
