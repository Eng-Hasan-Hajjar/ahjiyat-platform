<?php

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Engagement\CollectionService;
use Illuminate\Support\Facades\Cache;

/*
| E35 - مساعدات مشتركة لاختبارات المجموعات العامة والأرفف التحريرية. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. كل التهيئة تمرّ بالخدمات الرسمية (إنشاء/إضافة/ظهور/إخفاء) كي تختبر الاختبارات المسار الحقيقي.
| تعتمد على مساعدات E29/E28/E27/E16.
*/

require_once __DIR__.'/../EngagementE29/_support.php';

if (! function_exists('e35_seed')) {
    function e35_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    /** أعمال مجتمع منشورة من مبدع واحد (أرخص من مبدع لكل عمل). @return list<CreatorPuzzle> */
    function e35_works(int $n, ?User $creator = null): array
    {
        $creator ??= e28_creator();
        $works = [];

        for ($i = 0; $i < $n; $i++) {
            $works[] = e29_work($creator)->fresh();
        }

        return $works;
    }

    /** مجموعة بالخدمات الرسمية: عناصر ثم الظهور المطلوب. المالك ملفه عام (ما لم يُمرَّر غيره). */
    function e35_collection(User $owner, array $works = [], string $visibility = 'private', string $title = 'مجموعة اختبار', ?string $description = null): UserCollection
    {
        $svc = app(CollectionService::class);
        $collection = $svc->create($owner, $title, $description);

        foreach ($works as $work) {
            $svc->add($owner, $collection->public_id, $work->fresh());
        }

        if ($visibility !== 'private') {
            app(CollectionVisibilityService::class)->set($owner, $collection->public_id, $visibility);
        }

        return $collection->fresh();
    }

    /** مجموعة عامة بعدد أعمال ظاهرة. */
    function e35_public(?User $owner = null, int $items = 3, string $title = 'مجموعة عامة'): UserCollection
    {
        $owner ??= e16User();

        return e35_collection($owner, e35_works($items), 'public', $title);
    }

    function e35_moderator(): User
    {
        return e28_reviewer('moderator');
    }

    function e35_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    function e35_manager(): User
    {
        $user = e16User();
        $user->assignRole('content-manager');

        return $user;
    }

    /** معرّفات الأعمال العامة بترتيب المجموعة (المالك). @return list<string> */
    function e35_order(UserCollection $collection): array
    {
        return \Illuminate\Support\Facades\DB::table('user_collection_items as i')->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.user_collection_id', $collection->id)->orderBy('i.position')->orderBy('i.id')->pluck('w.public_id')->all();
    }

    function e35_hide_collection(UserCollection $collection, ?User $moderator = null, string $reason = 'مخالفة تجريبية للاختبار.'): void
    {
        app(\App\Services\Collections\CollectionModerationService::class)->hide($collection->fresh(), $moderator ?? e35_moderator(), $reason);
    }

    /** رف بالخدمة الرسمية. $items = [[type, id], ...]. $activate=false يتركه مسودة. */
    function e35_shelf(array $items, string $key = 'shelf-a', string $placement = 'discover', array $over = [], bool $activate = true, ?User $admin = null): \App\Models\EditorialShelf
    {
        $admin ??= e35_admin();
        $svc = app(\App\Services\Editorial\EditorialShelfService::class);
        $shelf = $svc->create($admin, $over + ['internal_key' => $key, 'title' => 'رف '.$key, 'placement' => $placement]);

        if ($items !== []) {
            $svc->setItems($admin, $shelf, $items);
        }

        return $activate ? $svc->activate($admin, $shelf->fresh()) : $shelf->fresh();
    }

    /** @return array{0: string, 1: int} */
    function e35_ref(string $type, int $id): array
    {
        return [$type, $id];
    }

    /** يقتطع قسم الاكتشاف بوسمه. */
    function e35_discover_section(string $html): string
    {
        return e29_section($html, 'مجموعات من المجتمع');
    }
}
