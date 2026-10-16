<?php

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Creators\CreatorVisibilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E28-Y8 - أحجيات المجتمع داخل البحث والاكتشاف (E27): نوع بحث مستقل بوسم «من المجتمع»، وأقسام اكتشاف مستقلة، ورائج بلاعبين مميَّزين موثَّقين،
| وتنوّع المبدعين، ولا تسرّب لمحتوى خاص. كل شيء محلي على SQLite وكاش array.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e28_plays')) {
    /** يكتب $players لاعبًا مميَّزًا (حسابات موثَّقة جديدة) بمحاولة واحدة لكل منهم على عمل منشور؛ أول $solvers يحلّون. @return list<User> */
    function e28_plays(CreatorPuzzle $work, int $players, int $solvers = 0, array $userAttrs = []): array
    {
        $users = [];

        for ($i = 0; $i < $players; $i++) {
            $user = e16User($userAttrs);
            DB::table('community_puzzle_attempts')->insert([
                'user_id' => $user->id,
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $work->published_puzzle_id,
                'attempt_number' => 1,
                'is_correct' => $i < $solvers,
                'created_at' => now()->subHour(),
            ]);
            $users[] = $user;
        }

        Cache::forget('discover.community.trending');

        return $users;
    }

    /** @return list<string> مفاتيح أقسام /discover بترتيب ظهورها. */
    function e28_discover_sections(string $html): array
    {
        return uiAttrs(uiXpath($html), '//section[@data-discover-section]', 'data-discover-section');
    }

    /** @return list<string> عناوين بطاقات قسم اكتشاف. */
    function e28_section_titles(string $html, string $key): array
    {
        $x = uiXpath($html);
        $out = [];

        foreach ($x->query('//section[@data-discover-section="'.$key.'"]//a/h3') as $h3) {
            $out[] = trim($h3->textContent);
        }

        return $out;
    }
}

test('Y8-64 (64): a published community puzzle is searchable by its title', function () {
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'لغز الغيمة المضيئة']);

    $json = e27_suggest($this, 'الغيمة');

    expect(e27_titles($json))->toContain('لغز الغيمة المضيئة')
        ->and(e27_types($json))->toContain('community');

    // وصفحة البحث الكاملة تجدها أيضًا
    $this->get(route('search.index', ['q' => 'الغيمة']))->assertOk()->assertSee('لغز الغيمة المضيئة');
});

test('Y8-65 (65): drafts, pending, changes-requested and rejected works are never searchable', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    e28_draft($creator, 'text', ['title' => 'زمردة مسودة']);
    e28_pending($creator, 'text', ['title' => 'زمردة معلقة']);

    $changes = e28_pending($creator, 'text', ['title' => 'زمردة تعديلات']);
    app(CreatorReviewService::class)->requestChanges($changes, $reviewer, 'يرجى توضيح السؤال أكثر.');

    $rejected = e28_pending($creator, 'text', ['title' => 'زمردة مرفوضة']);
    app(CreatorReviewService::class)->reject($rejected, $reviewer, 'لا يناسب الإرشادات تمامًا.');

    e28_published($creator, 'text', ['title' => 'زمردة منشورة']);

    $titles = e27_titles(e27_suggest($this, 'زمردة'));

    expect($titles)->toBe(['زمردة منشورة']);
});

test('Y8-66 (66): hidden, archived and suspended-creator works are not searchable, and return on restore', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $hidden = e28_published($creator, 'text', ['title' => 'ياقوتة مخفية']);
    $archived = e28_published($creator, 'text', ['title' => 'ياقوتة مؤرشفة']);
    e28_published($creator, 'text', ['title' => 'ياقوتة ظاهرة']);

    app(CreatorVisibilityService::class)->hide($hidden, $reviewer, 'مخالفة للإرشادات بعد المراجعة.');
    app(CreatorPuzzleService::class)->archive($creator, $archived->public_id);

    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe(['ياقوتة ظاهرة']);

    // تعليق المبدع يُخفي كل أعماله من البحث، واستعادته تُعيد المنشور
    $profile = $creator->creatorProfile()->first();
    $admin = e28_reviewer('administrator');
    app(CreatorApplicationService::class)->suspend($profile, $admin, 'مخالفة متكررة للإرشادات.');
    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe([]);

    app(CreatorApplicationService::class)->restore($profile->refresh(), $admin);
    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe(['ياقوتة ظاهرة']);
});

test('Y8-67 (67): community results carry their own group, label and badge - never mixed into the official puzzles group', function () {
    $category = e27_category('أحاجي');
    e27_puzzle('لؤلؤة رسمية', ['puzzle_category_id' => $category->id]);
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'لؤلؤة مجتمع', 'difficulty' => 'hard']);

    $json = e27_suggest($this, 'لؤلؤة');
    $community = collect($json['groups'])->firstWhere('type', 'community');
    $official = collect($json['groups'])->firstWhere('type', 'puzzles');

    expect($community)->not->toBeNull()
        ->and($community['label'])->toBe('أحجيات المجتمع')
        ->and(collect($community['items'])->pluck('title')->all())->toBe(['لؤلؤة مجتمع'])
        ->and($community['items'][0]['badge'])->toContain('من المجتمع')->toContain('صعب')
        ->and($community['items'][0]['url'])->toStartWith('/community/puzzles/')
        ->and(collect($official['items'])->pluck('title')->all())->toBe(['لؤلؤة رسمية'])
        ->and(collect($official['items'])->pluck('badge')->implode(' '))->not->toContain('من المجتمع');

    $this->get(route('search.index', ['q' => 'لؤلؤة']))->assertOk()->assertSee('أحجيات المجتمع')->assertSee('من المجتمع');
});

test('Y8-68 (68): the Discover hub has community sections labelled as community, for guests and members', function () {
    $author = e28_creator();
    e28_published($author, 'text', ['title' => 'عقد الحكاية']);

    foreach ([null, e16User()] as $viewer) {
        $caller = $viewer ? $this->actingAs($viewer) : $this;
        $html = $caller->get(route('discover.index'))->assertOk()->getContent();

        expect(e28_discover_sections($html))->toContain('community-new')
            ->and(e28_section_titles($html, 'community-new'))->toBe(['عقد الحكاية'])
            ->and(uiXpath($html)->query('//section[@data-discover-section="community-new"]')->item(0)->textContent)->toContain('من المجتمع')->toContain('بقلم');
        auth()->logout();
    }
});

test('Y8-69 (69): trending counts unique verified, unfrozen players - not raw attempts, not unverified or frozen accounts', function () {
    $author = e28_creator();
    $spammed = e28_published($author, 'text', ['title' => 'لغز مكرر محاولاته']);
    $popular = e28_published($author, 'text', ['title' => 'لغز لاعبوه كثر']);
    $tainted = e28_published($author, 'text', ['title' => 'لغز لاعبوه مشبوهون']);

    // لاعب واحد بعشر محاولات: لاعب مميَّز واحد = تحت الحد الأدنى
    $one = e16User();
    foreach (range(1, 10) as $n) {
        DB::table('community_puzzle_attempts')->insert(['user_id' => $one->id, 'creator_puzzle_id' => $spammed->id, 'puzzle_id' => $spammed->published_puzzle_id, 'attempt_number' => min($n, 3), 'is_correct' => false, 'created_at' => now()->subHour()]);
    }

    e28_plays($popular, 3, 1);
    e28_plays($tainted, 3, 0, ['email_verified_at' => null]);
    foreach (e28_plays($tainted, 2) as $user) {
        $user->forceFill(['is_frozen' => true])->save();
    }
    Cache::flush();

    $trending = e28_section_titles($this->get(route('discover.index'))->assertOk()->getContent(), 'community-trending');

    expect($trending)->toBe(['لغز لاعبوه كثر'])
        ->and(array_keys(app(\App\Services\Creators\CommunityDiscovery::class)->trendingScores()))->toBe([$popular->id]);
});

test('Y8-70 (70): recommendations include an eligible community puzzle, and exclude own, already-played and already-shown works', function () {
    // أقدم ← أحدث. القسم «جديد» يأخذ أحدث عملين لكل مبدع (السقف)، و«مقترحة لك» ما بقي بعد استبعاد المعروض والملعوب والمملوك.
    $author = e28_creator();
    e28_published($author, 'text', ['title' => 'مقترحة قديمة']);
    $played = e28_published($author, 'text', ['title' => 'مقترحة لعبتها']);
    e28_published($author, 'text', ['title' => 'مقترحة حديثة ١']);
    e28_published($author, 'text', ['title' => 'مقترحة حديثة ٢']);

    $mine = e28_creator();
    foreach (['١', '٢', '٣'] as $n) {
        e28_published($mine, 'text', ['title' => 'من أعمالي '.$n]);
    }

    $player = e28_plays($played, 1)[0];

    $html = $this->actingAs($player)->get(route('discover.index'))->assertOk()->getContent();
    $forYou = e28_section_titles($html, 'community-for-you');
    expect($forYou)->toContain('مقترحة قديمة')->not->toContain('مقترحة لعبتها')
        ->and(array_intersect($forYou, e28_section_titles($html, 'community-new')))->toBe([]);   // لا تكرار بين القسمين
    auth()->logout();

    $own = $this->actingAs($mine)->get(route('discover.index'))->assertOk()->getContent();
    $ownForYou = e28_section_titles($own, 'community-for-you');
    expect($ownForYou)->toContain('مقترحة قديمة');

    foreach ($ownForYou as $title) {
        expect($title)->not->toStartWith('من أعمالي');
    }
    auth()->logout();

    // الضيف: لا قسم شخصي
    expect(e28_discover_sections($this->get(route('discover.index'))->getContent()))->not->toContain('community-for-you');
});

test('Y8-71 (71): the per-creator cap keeps one prolific creator from owning the community sections', function () {
    $prolific = e28_creator();
    foreach (range(1, 5) as $i) {
        e28_published($prolific, 'text', ['title' => 'غزير-'.$i]);
    }
    $other = e28_creator();
    e28_published($other, 'text', ['title' => 'آخر-1']);

    $titles = e28_section_titles($this->get(route('discover.index'))->assertOk()->getContent(), 'community-new');
    $fromProlific = array_filter($titles, fn ($t) => str_starts_with($t, 'غزير-'));

    expect(count($fromProlific))->toBeLessThanOrEqual((int) config('creators.discovery.per_creator_cap'))
        ->and($titles)->toContain('آخر-1');
});

test('Y8-72 (72): search and Discover never leak private content - answers, hints, drafts, emails, internal ids', function () {
    $creator = e28_creator(e16User(['name' => 'صانعة السر', 'email' => 'secret-maker@example.test']));
    e28_published($creator, 'text', ['title' => 'لغز المفتاح الخفي', 'answer' => 'جوابسريجدا', 'hint' => 'تلميحسريجدا']);
    e28_draft($creator, 'text', ['title' => 'لغز مسودة خفي', 'answer' => 'مسودةجواب']);

    $suggest = $this->getJson(route('search.suggest', ['q' => 'لغز']))->assertOk()->getContent();
    $page = $this->get(route('search.index', ['q' => 'لغز']))->assertOk()->getContent();
    $discover = $this->get(route('discover.index'))->assertOk()->getContent();
    $work = CreatorPuzzle::query()->firstOrFail();

    foreach ([$suggest, $page, $discover] as $body) {
        e28_assert_no_secrets($body, ['جوابسريجدا', 'تلميحسريجدا', 'مسودةجواب', 'secret-maker@example.test']);
        expect(str_contains($body, 'لغز مسودة خفي'))->toBeFalse();
    }

    // البحث بالجواب أو التلميح لا يجد شيئًا (البحث بعنوان المنشور فقط)
    expect(e27_titles(e27_suggest($this, 'جوابسريجدا')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'تلميحسريجدا')))->toBe([])
        ->and(json_decode($suggest, true)['groups'][0]['items'][0])->not->toHaveKeys(['id', 'creator_id', 'user_id', 'email', 'answer'])
        ->and($suggest)->toContain($work->public_id);
});
