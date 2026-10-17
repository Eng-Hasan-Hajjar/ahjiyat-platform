<?php

use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM1-1: admin creates an individual supporter with a ULID public id and a stable slug', function () {
    $profile = e30_supporter(['display_name' => 'فاعل خير', 'type' => 'individual']);

    expect($profile->type)->toBe('individual')->and($profile->public_id)->toHaveLength(26)->and($profile->slug)->not->toBeEmpty()
        ->and($profile->status)->toBe('active')->and($profile->is_public)->toBeTrue();
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_created']);

    $slug = $profile->slug;
    app(SupporterService::class)->update(e30_admin(), $profile, ['display_name' => 'اسم جديد']);
    expect($profile->fresh()->slug)->toBe($slug);
});

test('AM1-2: admin creates an organization supporter', function () {
    $profile = e30_company(['display_name' => 'شركة النور']);

    expect($profile->type)->toBe('organization')->and($profile->display_name)->toBe('شركة النور');
});

test('AM1-3: a supporter may optionally link to a platform user, and the link is optional', function () {
    $user = e16User();
    $linked = e30_supporter(['user_id' => $user->id]);
    $free = e30_supporter();

    expect($linked->user_id)->toBe($user->id)->and($free->user_id)->toBeNull();
});

test('AM1-4: linking one user to a second supporter profile is rejected', function () {
    $user = e16User();
    e30_supporter(['user_id' => $user->id]);

    expect(fn () => e30_supporter(['user_id' => $user->id]))->toThrow(SponsorshipException::class, 'مرتبط');
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('AM1-5: a public active supporter is publicly visible by slug', function () {
    $profile = e30_supporter(['display_name' => 'داعم علني']);

    $this->get(route('supporters.show', $profile->slug))->assertOk()->assertSee('داعم علني');
});

test('AM1-6: a private or suspended supporter is not publicly identified anywhere', function () {
    $private = e30_supporter(['display_name' => 'داعم خاص جدًا', 'is_public' => false]);
    $suspended = e30_supporter(['display_name' => 'داعم موقوف جدًا']);
    $program = e30_program();
    e30_confirmed($private, $program, '50');
    e30_confirmed($suspended, $program, '60');
    app(SupporterService::class)->setStatus(e30_admin(), $suspended, 'suspended');

    $this->get(route('supporters.show', $private->slug))->assertNotFound();
    $this->get(route('supporters.show', $suspended->slug))->assertNotFound();
    $this->get(route('supporters.index'))->assertOk()->assertDontSee('داعم خاص جدًا')->assertDontSee('داعم موقوف جدًا');
});

test('AM1-7: unsafe website URLs are rejected', function (string $url) {
    expect(fn () => e30_supporter(['website_url' => $url]))->toThrow(SponsorshipException::class);
})->with(['javascript:alert(1)', 'data:text/html,<b>x</b>', 'ftp://example.com', 'http://127.0.0.1/admin', 'https://user:pass@example.com', 'http://localhost', "https://exa\nmple.com"]);

test('AM1-8: safe websites are accepted and normalized', function () {
    $profile = e30_supporter(['website_url' => 'https://Example.com/about?x=1']);

    expect($profile->website_url)->toStartWith('https://example.com');
});

test('AM1-9: unsafe logos (SVG, script, wrong mime, oversized) are rejected', function () {
    Storage::fake(config('sponsorship.logo.disk'));
    $admin = e30_admin();

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $fake = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;');
    $big = UploadedFile::fake()->image('logo.png')->size(5000);

    foreach ([$svg, $fake, $big] as $file) {
        expect(fn () => app(SupporterService::class)->create($admin, ['display_name' => 'ذو شعار', 'type' => 'individual'], $file))->toThrow(SponsorshipException::class);
    }

    expect(SupporterProfile::query()->where('display_name', 'ذو شعار')->count())->toBe(0);

    $ok = app(SupporterService::class)->create($admin, ['display_name' => 'ذو شعار صالح', 'type' => 'individual'], UploadedFile::fake()->image('logo.png', 64, 64));
    expect($ok->logo_path)->not->toBeNull();
    Storage::disk(config('sponsorship.logo.disk'))->assertExists($ok->logo_path);
});

test('AM1-10: an ordinary user or a content manager without the permission cannot manage supporters', function () {
    $user = e16User();

    expect(fn () => app(SupporterService::class)->create($user, ['display_name' => 'محاولة', 'type' => 'individual']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SupporterProfile::query()->count())->toBe(0);
    expect($user->can('sponsorships.manage'))->toBeFalse()->and(e30_manager()->can('sponsorships.manage'))->toBeTrue();
});
