<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorVisibilityService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;

/*
| E29 - مساعدات مشتركة لاختبارات التفاعل المجتمعي. كل شيء على SQLite وكاش array وطابور sync؛ لا شبكة.
| كل التهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات) كي تختبر الاختبارات المسار الحقيقي.
| تعتمد على مساعدات E28 (e28_creator/e28_published/...) و E27 (e27_queries/e27_cold).
*/

require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e29_seed')) {
    /** يبذر الأدوار (مطلوب لمراجع E28). يُستدعى من beforeEach بكل ملف. */
    function e29_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** عمل مجتمع منشور لمبدع جديد (أو معطى). */
    function e29_work(?User $creator = null, array $over = []): CreatorPuzzle
    {
        return e28_published($creator ?? e28_creator(), 'text', $over);
    }

    /** لاعب موثَّق جرّب العمل مرة بإجابة خاطئة (يكفي لأهلية التقييم: المحاولة لا الحل). */
    function e29_player_who_played(CreatorPuzzle $work, ?User $user = null): User
    {
        $user ??= e16User();
        app(CommunityPlayService::class)->attempt($user, $work->fresh(), 'إجابة خاطئة تمامًا');

        return $user;
    }

    function e29_rate(User $user, CreatorPuzzle $work, int $rating): array
    {
        return app(CommunityRatingService::class)->set($user, $work->fresh(), $rating);
    }

    /** عدد من اللاعبين يقيّم كلٌّ منهم العمل بقيمة من المصفوفة (يجرّبون ثم يقيّمون). @return list<User> */
    function e29_ratings(CreatorPuzzle $work, array $values): array
    {
        $users = [];

        foreach ($values as $value) {
            $user = e29_player_who_played($work);
            e29_rate($user, $work, $value);
            $users[] = $user;
        }

        return $users;
    }

    function e29_favorite(User $user, CreatorPuzzle $work): array
    {
        return app(CommunityFavoriteService::class)->add($user, $work->fresh());
    }

    function e29_follow(User $user, User $creator): array
    {
        return app(CreatorFollowService::class)->follow($user, $creator->creatorProfile()->first());
    }

    function e29_collection(User $user, string $title = 'مجموعتي المفضلة'): \App\Models\UserCollection
    {
        return app(CollectionService::class)->create($user, $title);
    }

    function e29_profile(User $creator): CreatorProfile
    {
        return $creator->creatorProfile()->first();
    }

    /** يصالح ظهور أعمال مستخدم (كما يفعل التجميد/الاستعادة). */
    function e29_reconcile_visibility(User $creator): void
    {
        app(CreatorVisibilityService::class)->reconcileForUser($creator->fresh());
    }

    /** لقطة خام لعدّادات عمل/مبدع. */
    function e29_counters(CreatorPuzzle $work): array
    {
        $row = CreatorPuzzle::query()->whereKey($work->id)->first(['ratings_count', 'ratings_sum', 'favorites_count']);

        return ['count' => (int) $row->ratings_count, 'sum' => (int) $row->ratings_sum, 'favorites' => (int) $row->favorites_count];
    }

    function e29_followers(User $creator): int
    {
        return (int) CreatorProfile::query()->whereKey(e29_profile($creator)->id)->value('followers_count');
    }

    /** إخفاء إشرافي رسمي لعمل. */
    function e29_hide(CreatorPuzzle $work, ?User $moderator = null): void
    {
        $moderator ??= e28_reviewer();
        app(CreatorVisibilityService::class)->hide($work->fresh(), $moderator, 'مخالفة تجريبية للاختبار.');
    }

    function e29_unhide(CreatorPuzzle $work, ?User $moderator = null): void
    {
        $moderator ??= e28_reviewer();
        app(CreatorVisibilityService::class)->restore($work->fresh(), $moderator);
    }

    /** يستخرج قسمًا من الصفحة بوسمه (aria-label) كي لا تلتبس عناوين بطاقاته بنص جرس الإشعارات الذي قد يحمل العناوين نفسها. '' إن لم يوجد. */
    function e29_section(string $html, string $label): string
    {
        return preg_match('/<section aria-label="'.preg_quote($label, '/').'".*?<\/section>/su', $html, $m) === 1 ? $m[0] : '';
    }

    /** قسم الخلاصة. */
    function e29_feed_section(string $html): string
    {
        return e29_section($html, 'أحدث أحجيات المتابَعين');
    }
}
