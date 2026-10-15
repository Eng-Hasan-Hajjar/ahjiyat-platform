<?php

require_once __DIR__.'/_support.php';

use App\Filament\Pages\PlatformSettingsPage;
use App\Services\PlatformSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| E26 (62-66) - رفع الملفات (الشعارات/الأيقونات). السطح: مستخدمو الإدارة فقط عبر Filament/Livewire، قرص public، مسارات ثابتة، أسماء عشوائية.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    $this->admin = e26_user();
    $this->admin->assignRole('administrator');
    $this->actingAs($this->admin);
});

if (! function_exists('e26_branding_value')) {
    function e26_branding_value(string $key): mixed
    {
        return app(PlatformSettingsService::class)->getGroup('branding')[$key] ?? null;
    }

    /** يحمّل صفحة الإعدادات ويضع ملفًا في حقل هوية بصرية ثم يحفظ. */
    function e26_save_branding($test, string $field, UploadedFile $file)
    {
        return Livewire::test(PlatformSettingsPage::class)
            ->fillForm(['branding.'.$field => $file])
            ->call('save');
    }

    /** نفس تعريف الحقل كما في الصفحة: [اسم الحقل => حدّ KB]. */
    function e26_branding_limits(): array
    {
        return ['logo_main' => 2048, 'logo_square' => 1024, 'logo_light' => 1024, 'logo_dark' => 1024, 'favicon' => 512, 'social_share_image' => 2048];
    }
}

test('62: a valid branding image is accepted, stored under the fixed branding directory with a generated name, and saved to the setting', function () {
    $component = e26_save_branding($this, 'logo_main', UploadedFile::fake()->image('شعار.png', 300, 120)->size(80));
    $component->assertHasNoFormErrors();

    $stored = e26_branding_value('logo_main');
    $path = is_array($stored) ? (string) array_values($stored)[0] : (string) $stored;

    expect($path)->toStartWith('settings/branding/')->toEndWith('.png')->and(Storage::disk('public')->exists($path))->toBeTrue();
    expect(basename($path))->not->toContain('شعار');    // الاسم الأصلي لا يصل للقرص
});

test('63: executable or active content disguised as a branding image (php, html, svg) is rejected and nothing is stored or saved', function (string $kind) {
    $file = match ($kind) {
        'php' => UploadedFile::fake()->create('logo.php', 5, 'application/x-php'),
        // محتوى PHP حقيقي الشكل (يُستشَفّ كـ text/x-php) لكن بلا حمولة ويب-شل: مضادات الفيروسات على ويندوز تحجب ملفًا مؤقتًا يحمل توقيع ويب-شل فيفشل fopen بـ "Invalid argument".
        'php-as-png-name' => UploadedFile::fake()->createWithContent('logo.png', "<?php\n// e26 test fixture - intentionally not an image\n?>")->mimeType('application/x-httpd-php'),
        'html' => UploadedFile::fake()->createWithContent('logo.html', '<script>alert(1)</script>'),
        'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
        'phar' => UploadedFile::fake()->create('logo.phar', 5, 'application/octet-stream'),
    };

    $before = e26_branding_value('logo_main');
    e26_save_branding($this, 'logo_main', $file)->assertHasFormErrors(['branding.logo_main']);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(e26_branding_value('logo_main'))->toBe($before);
})->with(['php', 'php-as-png-name', 'html', 'svg', 'phar']);

test('64: oversized images are rejected on every branding field (limit per field), and exactly-at-limit files are accepted', function () {
    foreach (e26_branding_limits() as $field => $limitKb) {
        $over = UploadedFile::fake()->image($field.'.png', 64, 64)->size($limitKb + 100);
        e26_save_branding($this, $field, $over)->assertHasFormErrors(['branding.'.$field]);
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);

    e26_save_branding($this, 'favicon', UploadedFile::fake()->image('ok.png', 32, 32)->size(500))->assertHasNoFormErrors();   // 500 ≤ 512KB
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

test('64b: field-specific type lists hold - a JPEG is refused where only PNG/WebP is allowed (square logo, favicon)', function () {
    e26_save_branding($this, 'logo_square', UploadedFile::fake()->image('square.jpg', 64, 64)->size(50))->assertHasFormErrors(['branding.logo_square']);
    e26_save_branding($this, 'favicon', UploadedFile::fake()->image('fav.webp', 32, 32)->size(10))->assertHasFormErrors(['branding.favicon']);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('65: traversal / double-extension filenames are neutralized - the stored name is generated, inside the branding directory, with the sniffed extension', function (string $name, bool $accepted) {
    $component = e26_save_branding($this, 'logo_main', UploadedFile::fake()->image($name, 100, 100)->size(40));
    $files = Storage::disk('public')->allFiles();

    if (! $accepted) {
        // الامتداد الأخير php: يُرفض أصلًا (لا يصل شيء للقرص).
        $component->assertHasFormErrors(['branding.logo_main']);
        expect($files)->toBe([]);

        return;
    }

    $component->assertHasNoFormErrors();
    expect($files)->toHaveCount(1);
    foreach ($files as $stored) {
        expect($stored)->not->toContain('..')->not->toContain('\\')->not->toContain('evil')->not->toContain('.php')->not->toContain('.phtml');
        expect($stored)->toMatch('#^settings/branding/[A-Za-z0-9]{20,}\.(png|jpg|jpeg|webp)$#');
    }
})->with([
    'unix traversal' => ['../../../../evil.png', true],
    'windows traversal' => ['..\\..\\evil.png', true],
    'double extension' => ['evil.php.png', true],
    'absolute path' => ['/etc/evil.png', true],
    'nested dirs' => ['a/b/evil.png', true],
    'null-byte style, final .php' => ['evil.png%00.php', false],
]);

test('65b: every upload field in the application declares an explicit allow-list (no image/* wildcard, no SVG/HTML/PHP) and a size cap, on the public disk under a fixed directory', function () {
    $offenders = [];
    $seen = 0;

    foreach (e26_php_files(['app/Filament']) as $file) {
        $lines = file($file);
        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'FileUpload::make(')) {
                continue;
            }
            $seen++;

            // سلسلة الحقل: من هذا السطر حتى الحقل التالي (أو 16 سطرًا).
            $chain = $line;
            for ($j = $i + 1; $j < min($i + 17, count($lines)); $j++) {
                if (str_contains($lines[$j], 'FileUpload::make(') || preg_match('/^\s*Forms\\\\Components\\\\(?!FileUpload)/', $lines[$j])) {
                    break;
                }
                $chain .= $lines[$j];
            }

            $where = e26_relative($file).':'.($i + 1);
            if (! str_contains($chain, 'acceptedFileTypes(')) {
                $offenders[] = $where.' no acceptedFileTypes';
            }
            if (! str_contains($chain, 'maxSize(')) {
                $offenders[] = $where.' no maxSize';
            }
            if (! str_contains($chain, "->disk('public')") || ! str_contains($chain, '->directory(')) {
                $offenders[] = $where.' not public disk/fixed directory';
            }
            if (preg_match('#image/\*|svg|text/html|php|octet-stream|application/x-#i', preg_replace('#//.*$#m', '', $chain))) {
                $offenders[] = $where.' dangerous type allowed';
            }
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(19)->and($offenders)->toBe([]);
});

test('66: the branding hotfix (previewable(false) on all six fields) is intact and the settings page still renders with an existing logo', function () {
    $source = e26_php_code(base_path('app/Filament/Pages/PlatformSettingsPage.php'));
    expect(substr_count($source, '->previewable(false)'))->toBe(6);

    foreach (array_keys(e26_branding_limits()) as $field) {
        expect($source)->toMatch("/FileUpload::make\\('branding\\.{$field}'\\)[^;]*->previewable\\(false\\)/s");
    }

    app(PlatformSettingsService::class)->setMany('branding', ['logo_main' => 'settings/branding/existing.png'], $this->admin);
    Storage::disk('public')->put('settings/branding/existing.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    $this->get('/admin/settings')->assertOk();
    Livewire::test(PlatformSettingsPage::class)->assertSuccessful();
});

test('66b: only users with settings.update can change branding - a viewer with settings.view is refused server-side and nothing is stored', function () {
    $viewer = e26_user();
    $viewer->givePermissionTo(['admin.access', 'settings.view']);
    $this->actingAs($viewer);

    $before = e26_branding_value('logo_main');

    e26_save_branding($this, 'logo_main', UploadedFile::fake()->image('x.png', 50, 50)->size(20))->assertForbidden();   // 403 من abort_unless داخل save()

    expect(e26_branding_value('logo_main'))->toBe($before);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});
