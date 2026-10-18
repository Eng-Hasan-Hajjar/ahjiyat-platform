<?php

use App\Models\Campaign;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\User;
use App\Services\Discovery\RecommendationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E27 - مساعدات مشتركة لاختبارات البحث والاكتشاف. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| كل شيء محلي على قاعدة SQLite وكاش array؛ لا شبكة ولا محرك خارجي.
*/

require_once __DIR__.'/../SecurityE26/_support.php';
require_once __DIR__.'/../UiE22/UiTestHelpers.php';

if (! function_exists('e27_user')) {
    function e27_user(array $attrs = []): User
    {
        return e16User($attrs);
    }

    function e27_puzzle(string $title, array $attrs = []): Puzzle
    {
        return Puzzle::factory()->create($attrs + ['title' => $title]);
    }

    /** يكتب محاولة مباشرة بوقت معلوم (يتجاوز خدمة المحاولات عمدًا: لا مكافآت ولا أحداث). */
    function e27_attempt(User|int $user, Puzzle|int $puzzle, bool $correct = false, $at = null, int $number = 1, ?string $context = null): int
    {
        $at = $at ?? now();

        return (int) DB::table('puzzle_attempts')->insertGetId([
            'user_id' => $user instanceof User ? $user->id : $user,
            'puzzle_id' => $puzzle instanceof Puzzle ? $puzzle->id : $puzzle,
            'attempt_number' => (($number - 1) % 250) + 1,   // عمود TINYINT UNSIGNED: MySQL الصارم يرفض > 255 (SQLite لا يفرض). التكرار مسموح: القيد الفريد يضم context NULL.
            'is_correct' => $correct,
            'used_hint' => false,
            'context_type' => $context,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    function e27_campaign(string $title, array $attrs = []): Campaign
    {
        return Campaign::factory()->create($attrs + ['title' => $title, 'is_active' => true]);
    }

    function e27_season(string $title, array $attrs = [], array $campaign = []): Season
    {
        $c = e27_campaign($title, $campaign);

        return Season::factory()->create($attrs + ['campaign_id' => $c->id, 'is_published' => true]);
    }

    /** يبدأ المستخدم خطوة داخل حملة (صف تقدّم بلا إكمال) عبر المصانع: مرحلة ← بوابة ← خطوة. @return int معرّف الخطوة */
    function e27_progress(User $user, Campaign $campaign, bool $complete = false): int
    {
        $stage = \App\Models\CampaignStage::factory()->create(['campaign_id' => $campaign->id]);
        $gate = \App\Models\CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
        $step = \App\Models\CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);
        \App\Models\UserCampaignProgress::factory()->create(['user_id' => $user->id, 'campaign_step_id' => $step->id]);

        if ($complete) {
            DB::table('user_campaign_completions')->insert(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $step->id;
    }

    /** @return array<string, mixed> */
    function e27_suggest($test, string $q, ?User $as = null): array
    {
        $caller = $as ? $test->actingAs($as) : $test;

        return $caller->getJson(route('search.suggest', ['q' => $q]))->assertOk()->json();
    }

    /** @return list<string> كل عناوين الاقتراحات بترتيب ظهورها. */
    function e27_titles(array $json): array
    {
        return collect($json['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->values()->all();
    }

    /** @return list<string> عناوين مجموعة نوع واحد. */
    function e27_group(array $json, string $type): array
    {
        return collect($json['groups'])->firstWhere('type', $type)['items'] ?? [] ? collect(collect($json['groups'])->firstWhere('type', $type)['items'])->pluck('title')->all() : [];
    }

    /** @return list<string> */
    function e27_types(array $json): array
    {
        return collect($json['groups'])->pluck('type')->all();
    }

    /**
     * عدد استعلامات التطبيق أثناء $run، **بدون** تتبّع الأجهزة (device_sightings): وسيط قائم (E8/E24) يكتب بصمة الجهاز مرة بالدقيقة لكل طلب، وليس من البحث.
     * يعيد القائمة كاملة لمن يريد فحص النصوص (writes/selects).
     *
     * @return list<string>
     */
    function e27_sql(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_values(array_map(fn ($q) => $q['query'], array_filter($log, fn ($q) => ! str_contains($q['query'], 'device_sightings'))));
    }

    function e27_queries(callable $run): int
    {
        return count(e27_sql($run));
    }

    function e27_trending(): \App\Services\Discovery\TrendingService
    {
        return app(\App\Services\Discovery\TrendingService::class);
    }

    /** @return list<User> $players لاعبًا مميَّزًا (حسابات جديدة) يحاول كلٌّ منهم الأحجية مرة؛ أول $solvers منهم يحلّونها. */
    function e27_play(Puzzle $puzzle, int $players, $at = null, int $solvers = 0): array
    {
        $users = [];

        for ($i = 0; $i < $players; $i++) {
            $user = e27_user();
            e27_attempt($user, $puzzle, $i < $solvers, $at ?? now()->subHour());
            $users[] = $user;
        }

        return $users;
    }

    /**
     * مشكلات وصولية صفحة (فارغة = سليمة). نسخة E27 المستقلة من فحص E22 (لا نعتمد على ملف اختبار آخر): لغة/اتجاه، main، رابط التخطي أول عنصر، h1 واحد، عناصر بلا اسم، صور بلا alt، حقول بلا تسمية.
     *
     * @return list<string>
     */
    function e27_a11y_problems(string $html): array
    {
        $x = uiXpath($html);
        $problems = [];

        if ($x->query('/html[@lang="ar" and @dir="rtl"]')->length !== 1) {
            $problems[] = 'html lang/dir';
        }
        if ($x->query('//main[@id="main-content"]')->length !== 1) {
            $problems[] = 'main#main-content';
        }
        $skip = $x->query('(//a | //button | //input | //select | //textarea)[1]')->item(0);
        if (! $skip || $skip->getAttribute('href') !== '#main-content') {
            $problems[] = 'skip link is not the first focusable element';
        }
        if ($x->query('//h1')->length !== 1) {
            $problems[] = 'h1 count = '.$x->query('//h1')->length;
        }
        foreach (uiUnnamedControls($x, '/html/body') as $bad) {
            $problems[] = 'unnamed control: '.$bad;
        }
        foreach ($x->query('//img[not(@alt)]') as $img) {
            $problems[] = 'img without alt: '.$img->getAttribute('src');
        }
        foreach ($x->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="checkbox" or @type="radio")] | //select | //textarea') as $field) {
            $id = $field->getAttribute('id');
            $named = $field->getAttribute('aria-label') !== '' || $field->getAttribute('aria-labelledby') !== '' || $field->getAttribute('title') !== ''
                || ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
                || $x->query('ancestor::label', $field)->length > 0;

            if (! $named) {
                $problems[] = 'field without a label: '.($field->getAttribute('name') ?: $field->nodeName);
            }
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    function e27_reco(User $user): array
    {
        return app(RecommendationService::class)->forUser($user);
    }

    /** @return list<string> */
    function e27_reco_titles(User $user): array
    {
        return array_map(fn (array $r) => $r['puzzle']->title, e27_reco($user)['puzzles']);
    }

    function e27_category(string $name): PuzzleCategory
    {
        return PuzzleCategory::factory()->create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(4)]);
    }

    /** @return list<Puzzle> $n أحجية فعّالة في تصنيف بعنوان "<prefix>-<i>". */
    function e27_pool(PuzzleCategory $category, int $n, string $prefix, string $difficulty = 'easy', array $attrs = []): array
    {
        $out = [];

        for ($i = 1; $i <= $n; $i++) {
            $out[] = Puzzle::factory()->create($attrs + ['puzzle_category_id' => $category->id, 'title' => $prefix.'-'.$i, 'difficulty' => $difficulty]);
        }

        return $out;
    }

    /** يحلّ المستخدم الأحجية (محاولة صحيحة واحدة، مستقلة). */
    function e27_solve(User $user, Puzzle $puzzle, $at = null): void
    {
        e27_attempt($user, $puzzle, true, $at ?? now()->subDay());
    }

    /** يمسح كاش اللقطات (الرائج، مجسّ حجم الجدول) بين مرحلتين من اختبار واحد. */
    function e27_cold(): void
    {
        Cache::flush();
    }
}
