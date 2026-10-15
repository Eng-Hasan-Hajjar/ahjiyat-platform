<?php

require_once __DIR__.'/_support.php';

use App\Models\ChatMessage;
use App\Models\PuzzleCategory;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\Team;
use App\Models\User;
use App\Services\Advertising\UrlSafetyGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

const E26_XSS = '<script>alert(1)</script><img src=x onerror=alert(2)>';

function e26_assert_escaped(string $html): void
{
    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
}

test('27: a script payload in a chat message is stored as text and escaped on every page that renders it', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, E26_XSS)->assertCreated();
    $this->actingAs($a)->postJson(route('community.chat.send'), ['body' => E26_XSS])->assertCreated();

    expect(ChatMessage::query()->pluck('body')->unique()->all())->toBe([E26_XSS]);
    foreach ([route('messages.direct', $a), route('community.chat'), route('messages.index')] as $url) {
        e26_assert_escaped($this->actingAs($b)->get($url)->assertOk()->getContent());
    }
});

test('28: a script payload in a team name is escaped on the directory, leaderboard and team pages', function () {
    $team = e19Team(null, ['name' => 'فريق عادي']);
    DB::table('teams')->where('id', $team->id)->update(['name' => E26_XSS, 'description' => E26_XSS]);   // تجاوز التحقق عمدًا: دفاع بالعمق عند العرض
    $viewer = e26_user();

    foreach ([route('teams.index'), route('teams.show', $team), route('teams.leaderboard')] as $url) {
        e26_assert_escaped($this->actingAs($viewer)->get($url)->assertOk()->getContent());
    }
});

test('29: a script payload in a player name is escaped on profile, leaderboard, search and the account page', function () {
    $evil = e26_user(['name' => E26_XSS]);
    $viewer = e26_user();
    $evil->wallet->update(['lifetime_earned' => 500]);

    foreach ([route('players.show', $evil), route('leaderboard.index'), route('friends.search', ['q' => 'script'])] as $url) {
        e26_assert_escaped($this->actingAs($viewer)->get($url)->assertOk()->getContent());
    }
    e26_assert_escaped($this->actingAs($evil)->get(route('profile.edit'))->assertOk()->getContent());
});

test('30: SQL-injection-shaped search input is inert - pages answer normally, no table is touched, LIKE wildcards are escaped', function () {
    $viewer = e26_user();
    $victim = e26_user(['name' => 'Ordinary Person']);
    e19Team(null, ['name' => 'Ordinary Team']);
    $users = User::count();
    $payloads = ["' OR '1'='1", "'; DROP TABLE users; --", '" OR ""="', '%', '_', '\\', '%%', "Robert'); DROP TABLE teams;--", '١٢٣٪', "\0", str_repeat('a', 40)];

    foreach ($payloads as $payload) {
        $this->actingAs($viewer)->get(route('friends.search', ['q' => $payload]))->assertSuccessful();
        $this->actingAs($viewer)->get(route('teams.index', ['q' => $payload]))->assertSuccessful();
        $this->actingAs($viewer)->get(route('competitions.hall-of-fame', ['q' => $payload]))->assertSuccessful();
        expect($this->actingAs($viewer)->get(route('teams.challenges.index', ['q' => $payload]))->status())->toBeIn([200, 302]);
    }

    expect(User::count())->toBe($users)->and(Team::count())->toBe(1);
    // % و _ حرفيان: لا يطابقان كل شيء
    $this->actingAs($viewer)->get(route('friends.search', ['q' => '%']))->assertDontSee('Ordinary Person');
    $this->actingAs($viewer)->get(route('teams.index', ['q' => '%']))->assertDontSee('Ordinary Team');
});

test('31: sort/order parameters are never used as column names - hostile values change nothing and no controller feeds the request into orderBy', function () {
    $viewer = e26_user();

    foreach (['/puzzles', '/leaderboard', '/teams', '/challenges', '/campaigns', '/store', '/competitions'] as $path) {
        foreach (['sort' => 'password', 'order' => 'desc;drop table users', 'orderBy' => 'email', 'dir' => 'x'] as $key => $value) {
            $this->actingAs($viewer)->get($path.'?'.http_build_query([$key => $value]))->assertSuccessful();
        }
    }

    foreach (e26_php_files(['app/Http', 'app/Services']) as $file) {
        $code = e26_php_code($file);
        expect(preg_match('/order(?:By|ByDesc)\(\s*\$request|order(?:By|ByDesc)\(\s*request\(|order(?:By|ByDesc)\(\s*\$(?:sort|column|field)\b/i', $code))->toBe(0, e26_relative($file));
    }
});

test('32: javascript:, data:, http: and credential-bearing URLs are rejected for external destinations; the ad redirect only follows a stored https URL', function () {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'http://example.test/a', '//evil.test/x', 'https://user:pass@evil.test/x', 'https:///nohost', 'ftp://example.test', '', ' ', 'vbscript:msgbox(1)'] as $bad) {
        expect(UrlSafetyGuard::isSafe($bad))->toBeFalse("must reject: {$bad}");
    }
    expect(UrlSafetyGuard::isSafe('https://example.test/landing?x=1'))->toBeTrue();

    $campaign = SponsorCampaign::factory()->create();
    $unsafe = SponsorCreative::factory()->create(['sponsor_campaign_id' => $campaign->id]);
    DB::table('sponsor_creatives')->where('id', $unsafe->id)->update(['destination_url' => 'javascript:alert(1)']);    // تجاوز حارس الكتابة عمدًا
    $this->get(route('ads.click', $unsafe))->assertNotFound();

    $safe = SponsorCreative::factory()->create(['sponsor_campaign_id' => $campaign->id, 'destination_url' => 'https://example.test/offer']);
    $this->get(route('ads.click', $safe).'?url=https://evil.test/&redirect=https://evil.test/&next=//evil.test')->assertRedirect('https://example.test/offer');
});

test('33: an uploaded filename with traversal segments never decides where or under what name the file is stored', function () {
    Storage::fake('public');
    $admin = e26_user();
    $admin->assignRole('administrator');
    $category = PuzzleCategory::factory()->create();
    $this->actingAs($admin);

    Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm([
            'title' => 'traversal', 'puzzle_category_id' => $category->id, 'type' => 'image', 'difficulty' => 'easy', 'prompt' => 'سؤال',
            'image_path' => UploadedFile::fake()->image('../../../../etc/evil.jpg', 400, 300)->size(100), 'answer_raw' => 'x', 'score_mode' => 'flat',
        ])->call('create')->assertHasNoFormErrors();

    $path = (string) \App\Models\Puzzle::query()->latest('id')->value('image_path');
    expect($path)->toStartWith('puzzles/')->not->toContain('..')->not->toContain('etc/evil')->and(Storage::disk('public')->exists($path))->toBeTrue();
    foreach (Storage::disk('public')->allFiles() as $stored) {
        expect($stored)->not->toContain('..')->not->toContain('evil');
    }
});

test('34: executable content disguised as an image, and non-image MIME types, are rejected by the upload field', function (string $kind) {
    Storage::fake('public');
    $admin = e26_user();
    $admin->assignRole('administrator');
    $category = PuzzleCategory::factory()->create();
    $this->actingAs($admin);

    $file = match ($kind) {
        'php-mime' => UploadedFile::fake()->create('shell.php', 5, 'application/x-php'),
        'svg' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
        'pdf' => UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'),
    };

    Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm(['title' => 'bad', 'puzzle_category_id' => $category->id, 'type' => 'image', 'difficulty' => 'easy', 'prompt' => 'q', 'image_path' => $file, 'answer_raw' => 'x', 'score_mode' => 'flat'])
        ->call('create')->assertHasFormErrors(['image_path']);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(\App\Models\Puzzle::count())->toBe(0);
})->with(['php-mime', 'svg', 'pdf']);

test('34b: a script renamed to .png is rejected because the MIME is sniffed from the content, not the extension (the rule family every image upload field uses)', function () {
    // Livewire في وضع الاختبار يستعمل MIME المُعلَن للملف المزيَّف لا المحتوى، فنتحقق من قاعدة المحتوى نفسها (mimetypes) على ملف حقيقي.
    $php = tempnam(sys_get_temp_dir(), 'e26');
    $png = tempnam(sys_get_temp_dir(), 'e26');
    // بلا حمولة ويب-شل حقيقية: ويندوز ديفندر يحجب الملف المؤقت عند الكتابة (finfo: Invalid argument). يكفي أن يكون المحتوى PHP لا صورة.
    file_put_contents($php, "<?php\n// e26 test fixture - intentionally not an image\n?>");
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $rules = ['f' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp']];

    $disguised = new \Illuminate\Http\UploadedFile($php, 'shell.png', 'image/png', null, true);   // امتداد وMIME معلَن كاذبان
    $real = new \Illuminate\Http\UploadedFile($png, 'logo.png', null, null, true);

    expect($disguised->getMimeType())->not->toStartWith('image/')   // المحتوى هو الذي يُستشَفّ، لا الامتداد ولا MIME المُعلَن
        ->and(\Illuminate\Support\Facades\Validator::make(['f' => $disguised], $rules)->fails())->toBeTrue()
        ->and(\Illuminate\Support\Facades\Validator::make(['f' => $real], $rules)->passes())->toBeTrue();
    @unlink($php);
    @unlink($png);
});
