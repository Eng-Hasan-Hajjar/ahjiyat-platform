<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorVisibilityService;

/*
| E30 (سلاسل المبدعين) - مساعدات مشتركة. كل شيء على SQLite وكاش array وطابور sync؛ لا شبكة. كل التهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات).
| تعتمد على مساعدات E28 (e28_creator/e28_published/e28_reviewer) و E29 (e29_follow) و E27 (e27_queries).
*/

require_once __DIR__.'/../EngagementE29/_support.php';

if (! function_exists('e30s_seed')) {
    function e30s_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** @return list<CreatorPuzzle> $n أعمال منشورة للمبدع. */
    function e30s_works(User $creator, int $n = 3): array
    {
        $works = [];

        for ($i = 1; $i <= $n; $i++) {
            $works[] = e28_published($creator, 'text', ['title' => 'لغز السلسلة '.fake()->unique()->numerify('######')]);
        }

        return $works;
    }

    /** @param list<CreatorPuzzle> $works */
    function e30s_ids(array $works): array
    {
        return array_values(array_map(fn (CreatorPuzzle $w) => $w->public_id, $works));
    }

    function e30s_draft(User $creator, ?array $works = null, array $over = []): CreatorSeriesRevision
    {
        $works ??= e30s_works($creator, 3);

        return app(CreatorSeriesService::class)->createDraft($creator, $over + ['title' => 'سلسلة '.fake()->unique()->numerify('####'), 'description' => 'وصف قصير للسلسلة.', 'items' => e30s_ids($works)]);
    }

    function e30s_pending(User $creator, ?array $works = null, array $over = []): CreatorSeriesRevision
    {
        $revision = e30s_draft($creator, $works, $over);

        return app(CreatorSeriesService::class)->submit($creator, $revision->series()->value('public_id'));
    }

    /** سلسلة منشورة فعلًا (مسودة ← إرسال ← اعتماد). */
    function e30s_published(User $creator, ?array $works = null, array $over = [], ?User $reviewer = null): CreatorSeries
    {
        $revision = e30s_pending($creator, $works, $over);
        app(CreatorSeriesReviewService::class)->approve($revision, $reviewer ?? e28_reviewer());

        return $revision->series()->first();
    }

    /** يحلّ العمل بالإجابة الصحيحة كلاعب مجتمع (الإجابة الافتراضية لمساعد E28 هي «القلم»). */
    function e30s_solve(User $player, CreatorPuzzle $work): array
    {
        return app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'القلم');
    }

    function e30s_wrong(User $player, CreatorPuzzle $work): array
    {
        return app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'جواب خاطئ تمامًا');
    }

    function e30s_hide_work(CreatorPuzzle $work, ?User $moderator = null): void
    {
        app(CreatorVisibilityService::class)->hide($work->fresh(), $moderator ?? e28_reviewer(), 'إخفاء تجريبي لأغراض الاختبار.');
    }

    function e30s_restore_work(CreatorPuzzle $work, ?User $moderator = null): void
    {
        app(CreatorVisibilityService::class)->restore($work->fresh(), $moderator ?? e28_reviewer());
    }

    /** هل السلسلة ظاهرة بالقاعدة المركزية؟ */
    function e30s_visible(CreatorSeries $series): bool
    {
        return app(\App\Services\CreatorSeries\CreatorSeriesVisibilityService::class)->isVisible($series->fresh());
    }
}
