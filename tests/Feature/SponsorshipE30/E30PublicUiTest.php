<?php

use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM8-76: the supporters page renders for guests, empty and populated', function () {
    $this->get(route('supporters.index'))->assertOk()->assertSee('الداعمون والرعاة')->assertSee('لا داعمين في هذا النطاق بعد');

    $program = e30_program(['title' => 'برنامج ظاهر']);
    e30_confirmed(e30_supporter(['display_name' => 'داعم ظاهر']), $program, '40');

    $this->get(route('supporters.index'))->assertOk()->assertSee('داعم ظاهر')->assertSee('برنامج ظاهر')->assertSee('مستويات الدعم');
    $this->get(route('supporters.index', ['scope' => 'program', 'program' => $program->slug]))->assertOk()->assertSee('داعم ظاهر');
    $this->get(route('supporters.index', ['scope' => 'bogus', 'program' => 'nope']))->assertOk();
    $this->get(route('supporters.index', ['scope' => 'program', 'program' => 'does-not-exist']))->assertOk();
});

test('AM8-77: a supporter page is safe: escaped output, no private data, 404 for unknown slugs', function () {
    $supporter = e30_supporter(['display_name' => 'داعم آمن', 'description' => 'نبذة عادية بلا وسوم']);
    $program = e30_program();
    e30_confirmed($supporter, $program, '15', ['notes' => 'ملاحظة إدارية سرّية', 'reference' => 'REF-SECRET-1']);

    $html = $this->get(route('supporters.show', $supporter->slug))->assertOk()->getContent();

    expect($html)->toContain('داعم آمن')->not->toContain('ملاحظة إدارية سرّية')->not->toContain('REF-SECRET-1')->not->toContain($supporter->public_id);
    $this->get('/supporters/no-such-supporter')->assertNotFound();
    expect(fn () => e30_supporter(['display_name' => '<script>alert(1)</script>']))->toThrow(\App\Services\Sponsorship\SponsorshipException::class);
});

test('AM8-78: exact amounts are hidden by default everywhere', function () {
    $supporter = e30_supporter(['display_name' => 'داعم المبلغ']);
    $program = e30_program(['title' => 'برنامج بلا مبالغ', 'goal_amount' => '1000', 'show_goal_progress' => true]);
    e30_confirmed($supporter, $program, '123.45');

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('supporters.show', $supporter->slug)] as $url) {
        $this->get($url)->assertOk()->assertDontSee('123.45')->assertDontSee('$123')->assertDontSee('12345');
    }
    $this->get(route('supporters.index'))->assertSee('تقدّم الهدف');
});

test('AM8-79: amounts are shown only when the program enables it', function () {
    $supporter = e30_supporter(['display_name' => 'داعم المبلغ']);
    $program = e30_program(['title' => 'برنامج بمبالغ', 'goal_amount' => '1000', 'show_goal_progress' => true, 'show_public_amounts' => true]);
    e30_confirmed($supporter, $program, '123.45');

    $this->get(route('supporters.index', ['scope' => 'program', 'program' => $program->slug]))->assertOk()->assertSee('$123.45');
    $this->get(route('supporters.show', $supporter->slug))->assertOk()->assertSee('$123.45');
    // صفوف لوحة «كل الوقت» العامة لا تكشف مبلغ أي داعم حتى لو فعّل برنامج ذلك (المبلغ يخص لوحة البرنامج وبطاقته فقط).
    $html = $this->get(route('supporters.index', ['scope' => 'all']))->assertOk()->getContent();
    preg_match('/<ol[^>]*aria-label="ترتيب الداعمين".*?<\/ol>/su', $html, $board);
    expect($board[0] ?? '')->not->toBeEmpty()->not->toContain('$123');
});

test('AM8-80: the main sponsor label is shown, and a platform sponsor appears on the supporters page', function () {
    $program = e30_program();
    $company = e30_company(['display_name' => 'راعي المنصة']);
    e30_confirmed($company, $program, '500');
    e30_role($program, $company, 'main_sponsor');

    $this->get(route('supporters.index'))->assertOk()->assertSee('الراعي الرئيسي')->assertSee('راعي المنصة')->assertSee('برعاية');
});

test('AM8-81: external links carry rel="noopener noreferrer sponsored" and open safely; logos are plain images', function () {
    $program = e30_program();
    $company = e30_company(['display_name' => 'شركة الرابط', 'website_url' => 'https://example.com/a']);
    e30_confirmed($company, $program, '500');
    e30_role($program, $company, 'main_sponsor');

    $html = $this->get(route('supporters.show', $company->slug))->assertOk()->getContent();
    expect($html)->toContain('rel="noopener noreferrer sponsored"')->toContain('target="_blank"')->toContain('href="https://example.com/a"');
    $strip = $this->get(route('supporters.index'))->getContent();
    expect($strip)->toContain('rel="noopener noreferrer sponsored"')->not->toContain('javascript:');
    expect(preg_match_all('/<a [^>]*target="_blank"[^>]*>/', $html.$strip, $m))->toBeGreaterThan(0);
    foreach ($m[0] as $tag) {
        expect($tag)->toContain('noopener')->toContain('noreferrer');
    }
});

test('AM8-82: the supporter pages are RTL Arabic with accessible landmarks', function () {
    e30_confirmed(e30_supporter(), e30_program(), '40');

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();

    expect($html)->toContain('dir="rtl"')->toContain('lang="ar"')->toContain('aria-label="ترتيب الداعمين"')->toContain('<h1');
    expect($html)->not->toMatch('/\b(text-left|float-left|ml-\d|mr-\d)\b/');
});

test('AM14-136: hidden/private sponsors leak no private data (name, slug, logo, public id) in any public page', function () {
    $program = e30_program();
    $hidden = e30_company(['display_name' => 'جهة مخفية تمامًا', 'is_public' => false, 'website_url' => 'https://hidden.example.com']);
    e30_confirmed($hidden, $program, '900');
    e30_role($program, $hidden, 'main_sponsor');

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug])] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach (['جهة مخفية تمامًا', $hidden->slug, $hidden->public_id, 'hidden.example.com'] as $needle) {
            expect($html)->not->toContain($needle);
        }
    }
    $this->get(route('supporters.show', $hidden->slug))->assertNotFound();

    app(SupporterService::class)->update(e30_admin(), $hidden, ['is_public' => true]);
    $this->get(route('supporters.index'))->assertSee('جهة مخفية تمامًا');
});

test('AM14-130/133: no GET mutates anything and there is no public write route for sponsorship', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '10');
    $before = \Illuminate\Support\Facades\DB::table('sponsorship_contributions')->get()->toArray();

    foreach ([route('supporters.index'), route('supporters.show', $supporter->slug), route('supporters.index', ['confirm' => 1, 'status' => 'confirmed', 'id' => $contribution->id])] as $url) {
        $this->get($url)->assertOk();
    }

    expect(\Illuminate\Support\Facades\DB::table('sponsorship_contributions')->get()->toArray())->toEqual($before);
    // E44 أضاف عمدًا مسار كتابة **موثَّقًا** لطلب بطاقة الداعم (POST seasons/{season}/supporters/cards: auth + verified + account.active + throttle) فلم يعد «لا مسار كتابة مطابقًا» هو الثابت الصحيح.
    // الثابت الأمني الفعلي: لا مسار كتابة **غير موثَّق** للداعمين/الرعاية (وأي مسار كتابة مطابق يجب أن يحمل auth)، ومساحة E30 العامة نفسها (supporters* / sponsorship*) تبقى بلا أي كتابة إطلاقًا.
    $router = app('router');
    $aliases = $router->getMiddleware();
    $middlewareOf = fn ($route) => collect($router->gatherRouteMiddleware($route))->map(fn ($m) => is_string($m) ? \Illuminate\Support\Str::before($m, ':') : '')->all();
    $label = fn ($r) => implode('|', $r->methods()).' '.$r->uri();
    $writes = collect($router->getRoutes()->getRoutes())->filter(fn ($r) => (str_contains($r->uri(), 'supporters') || str_contains($r->uri(), 'sponsorship')) && ! in_array('GET', $r->methods(), true) && ! str_starts_with($r->uri(), 'admin'));

    expect($writes->reject(fn ($r) => in_array($aliases['auth'], $middlewareOf($r), true))->map($label)->values()->all())->toBe([])
        ->and($writes->filter(fn ($r) => str_starts_with($r->uri(), 'supporters') || str_starts_with($r->uri(), 'sponsorship'))->map($label)->values()->all())->toBe([]);

    // مسار E44 المسموح بالاسم الفعلي يبقى محميًا بالثلاثة معًا (لا يكفي وجود auth وحده).
    $card = $router->getRoutes()->getByName('seasonal.supporters.cards.store');
    expect($card)->not->toBeNull()->and($card->methods())->toContain('POST');

    foreach (['auth', 'verified', 'account.active'] as $alias) {
        expect($middlewareOf($card))->toContain($aliases[$alias]);
    }
    $this->post('/supporters', ['display_name' => 'x'])->assertStatus(405);
});

test('AM14-127/128: mass assignment is blocked on every sponsorship model and a contribution cannot be reached by id', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '10');

    foreach ([\App\Models\SupporterProfile::class, \App\Models\SponsorshipProgram::class, \App\Models\SponsorshipContribution::class, \App\Models\SponsorshipProgramRole::class, \App\Models\SponsorshipRewardBonus::class, \App\Models\SponsorshipEventRewardPlan::class] as $model) {
        expect(fn () => (new $model)->fill(['status' => 'confirmed', 'accounting_amount_minor' => 99, 'role' => 'main_sponsor', 'bonus_amount' => 5]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
    }
    expect(fn () => $contribution->fill(['status' => 'confirmed']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);

    $player = e16User();
    $this->actingAs($player)->get('/admin/sponsorship-contributions')->assertForbidden();
    $this->get('/supporters/'.$contribution->public_id)->assertNotFound();
    $this->get('/supporters/'.$contribution->id)->assertNotFound();
});

test('AM14-131: the logo upload is MIME/content checked (not by extension) and the stored name is server generated', function () {
    \Illuminate\Support\Facades\Storage::fake(config('sponsorship.logo.disk'));
    $service = app(\App\Services\Sponsorship\SupporterLogoService::class);

    $php = \Illuminate\Http\UploadedFile::fake()->createWithContent('shell.png', "<?php system('id');");
    $html = \Illuminate\Http\UploadedFile::fake()->createWithContent('page.jpg', '<html><script>alert(1)</script></html>');
    foreach ([$php, $html] as $file) {
        expect(fn () => $service->store($file))->toThrow(\App\Services\Sponsorship\SponsorshipException::class);
    }

    $path = $service->store(\Illuminate\Http\UploadedFile::fake()->image('../../evil name.png', 50, 50));
    expect($path)->toStartWith(config('sponsorship.logo.directory').'/')->not->toContain('evil')->not->toContain('..')->toMatch('/\.(jpg|jpeg|png|webp)$/');
});

test('AM15-137/138/139: the public pages and the sponsor strip are query-bounded regardless of the data volume', function () {
    $season = e30_season();
    $seasonProgram = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $platform = e30_program();

    $seed = function (int $n) use ($seasonProgram, $platform) {
        for ($i = 0; $i < $n; $i++) {
            $s = e30_company();
            e30_confirmed($s, $i % 2 ? $seasonProgram : $platform, (string) (10 + $i));
            e30_role($i % 2 ? $seasonProgram : $platform, $s, 'sponsor');
        }
    };
    $measure = fn () => [
        'index' => (function () { Cache::flush(); return e27_queries(fn () => $this->get(route('supporters.index'))->assertOk()); })->call($this),
        'season' => (function () use ($season) { Cache::flush(); return e27_queries(fn () => $this->get(route('seasons.show', $season))->assertOk()); })->call($this),
        'show' => (function () { Cache::flush(); $p = \App\Models\SupporterProfile::query()->first(); return e27_queries(fn () => $this->get(route('supporters.show', $p->slug))->assertOk()); })->call($this),
    ];

    $seed(3);
    $few = $measure();
    $seed(12);
    $many = $measure();

    expect($many['index'])->toBe($few['index'])->and($many['season'])->toBe($few['season'])->and($many['show'])->toBe($few['show'])
        ->and($many['index'])->toBeLessThanOrEqual(30)->and($many['season'])->toBeLessThanOrEqual(45)->and($many['show'])->toBeLessThanOrEqual(25);
});

test('AM15: the display and the board are cached (warm read is cheaper) and the cache is never an authority', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $company = e30_company(['display_name' => 'شركة الكاش']);
    e30_confirmed($company, $program, '100');
    e30_role($program, $company, 'main_sponsor');

    Cache::flush();
    $cold = e27_queries(fn () => $this->get(route('supporters.index'))->assertOk());
    $warm = e27_queries(fn () => $this->get(route('supporters.index'))->assertOk());
    expect($warm)->toBeLessThan($cold);

    // التمويل الذي يحسب التعزيز يُقرأ حيًّا: استرداد بعد تسخين الكاش يُبطل الفوز فورًا.
    $boosted = e30_program();
    e30_boost($boosted, '10', 100, 500);
    $c = e30_confirmed(e30_supporter(), $boosted, '20');
    Cache::flush();
    $ctx = \App\GameEngine\Support\AttemptContext::none();
    $svc = app(\App\Services\Sponsorship\SponsorshipRewardService::class);
    expect($svc->resolveForSolve($ctx)['bps'])->toBe(200);
    e30_refund($c);
    expect($svc->resolveForSolve($ctx))->toBeNull();
});
