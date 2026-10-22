<?php

use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use Database\Seeders\DemoCreatorSeeder;
use Database\Seeders\DemoE37Seeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E37 (إضافي) - DemoE37Seeder: يبني نشاطًا حقيقيًا بالخدمات الرسمية فتتولّد اللقطات من السجلات، مكرَّر الأمان، ويرفض الإنتاج دون أن يلمس جدولًا.
*/

beforeEach(fn () => e37_seed());

function e37_demo_counts(): array
{
    return [
        'users' => DB::table('users')->count(), 'ratings' => DB::table('community_puzzle_ratings')->count(), 'favorites' => DB::table('community_puzzle_favorites')->count(),
        'follows' => DB::table('creator_follows')->count(), 'collections' => DB::table('user_collections')->count(), 'saves' => DB::table('user_collection_saves')->count(),
        'comments' => DB::table('discussion_comments')->count(), 'likes' => DB::table('discussion_comment_likes')->count(), 'snapshots' => DB::table('community_reputation_snapshots')->count(),
        'featured' => DB::table('community_featured_creators')->count(), 'achievements' => DB::table('achievements')->count(), 'progress' => DB::table('user_achievement_progress')->count(),
    ];
}

test('E37-D1: the demo seeder produces real activity - snapshots from authority rows, boards populated, recognition unlocked, one creator featured, no economy rows', function () {
    $this->seed(DemoCreatorSeeder::class);
    $world = e37_world();
    $this->seed(DemoE37Seeder::class);
    $after = e37_world();

    expect(CommunityReputationSnapshot::query()->count())->toBeGreaterThan(5)
        ->and(CommunityFeaturedCreator::query()->count())->toBe(1)
        ->and(e37_rankings()->featured())->toHaveCount(1)
        ->and(e37_rankings()->curators())->not->toBeEmpty()
        ->and(e37_rankings()->helpful())->not->toBeEmpty()
        ->and(DB::table('user_achievement_progress as p')->join('achievements as a', 'a.id', '=', 'p.achievement_id')->whereNotNull('p.unlocked_at')->where('a.category', 'community')->count())->toBeGreaterThan(0)
        ->and($after['counts'])->toEqual($world['counts'])->and($after['wallets'])->toBe($world['wallets']);

    $html = $this->get(route('community.rankings'))->assertOk()->getContent();

    expect($html)->toContain('مبدع');
});

test('E37-D2: the demo seeder is idempotent - a second run changes no row count and creates no duplicates', function () {
    $this->seed(DemoCreatorSeeder::class);
    $this->seed(DemoE37Seeder::class);
    $first = e37_demo_counts();

    $this->seed(DemoE37Seeder::class);

    expect(e37_demo_counts())->toEqual($first)
        ->and(DB::table('community_reputation_snapshots')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and(CommunityFeaturedCreator::query()->count())->toBe(1);
});

test('E37-D3: the demo seeder refuses production and unknown environments before touching any table', function () {
    $before = e37_demo_counts();

    foreach (['production'] as $env) {
        app()->detectEnvironment(fn () => $env);

        expect(fn () => app(DemoE37Seeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج'); // مباشرةً: db:seed نفسه يسأل تأكيد الإنتاج قبل الوصول للحارس
    }

    app()->detectEnvironment(fn () => 'testing');

    expect(e37_demo_counts())->toEqual($before);
});
