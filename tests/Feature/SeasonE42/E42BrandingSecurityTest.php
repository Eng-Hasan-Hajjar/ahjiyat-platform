<?php

use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Models\Season;
use App\Models\SeasonContentItem;
use Carbon\Carbon;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';
require_once __DIR__.'/../LiveOpsE34/_support.php';

/*
| E42 / الهوية البصرية والأمان والعزل: ثيم مخزَّن عدائي يُعرض خاملًا، قيود رفع الصور (لا SVG)، تحذير الوسائط الناقصة لا يمنع النشر،
| CSRF على لوحة البناء، وعزل شامل: حياة موسم كاملة لا تكتب شيئًا في جداول المال/المكافآت/السمعة/الإحالة/الرعاية/النتائج/المسار.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── الثيم ─────────────────────────────

test('E42-BS-01: a hostile stored theme_config renders inert - the tagline is escaped, an unknown preset falls back to generic, no style or script is injected', function () {
    $season = e42_live(['season' => ['theme_config' => [
        'preset' => 'x;}body{display:none',
        'hero_tagline' => '<script>alert(1)</script>تحدٍّ',
        'css' => 'body{background:url(//evil.test/x)}',
    ]]]);
    $phase = e42_phase($season, 'مرحلة قادمة', '2026-12-15 00:00:00', '2026-12-20 00:00:00');
    $phase->forceFill(['theme_config' => ['accent' => 'red;background:url(//evil.test/y)']])->save();

    expect($season->fresh()->themePreset())->toBe('generic')->and($phase->fresh()->themeAccent())->toBeNull();

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('evil.test')->not->toContain('x;}body')->not->toContain('theme-aseel');
});

// ───────────────────────────── الوسائط ─────────────────────────────

test('E42-BS-02: banner and logo uploads accept jpeg / png / webp only (never SVG), are capped in size, stored on the public disk under random names', function () {
    $this->actingAs(e42_admin());
    $form = Livewire::test(CreateSeason::class)->assertSuccessful()->instance()->form;

    $uploads = collect($form->getFlatComponents())->filter(fn ($c) => $c instanceof FileUpload)->keyBy(fn ($c) => $c->getName());

    expect($uploads->keys()->all())->toContain('logo_image')->toContain('banner_image');

    foreach (['logo_image' => 2048, 'banner_image' => 4096] as $name => $cap) {
        $field = $uploads[$name];

        expect($field->getAcceptedFileTypes())->toBe(['image/jpeg', 'image/png', 'image/webp'])
            ->and($field->getAcceptedFileTypes())->not->toContain('image/svg+xml')
            ->and($field->getDiskName())->toBe('public')
            ->and($field->getMaxSize())->toBe($cap)
            ->and($field->shouldPreserveFilenames())->toBeFalse();
    }
});

test('E42-BS-03: missing or dangling branding media is a warning, never a blocker; a stored file clears it; a dangling path renders no broken image', function () {
    Storage::fake('public');
    $season = e42_live();

    $report = e42_ready()->report($season->fresh('campaign'));
    expect($report['ready'])->toBeTrue()
        ->and(implode(' ', $report['warnings']))->toContain('صورة الغلاف')->toContain('الشعار')
        ->and(implode(' ', $report['blockers']))->not->toContain('الشعار')->not->toContain('صورة الغلاف');

    $season->forceFill(['banner_image' => 'seasons/branding/gone.png', 'logo_image' => 'seasons/branding/logo.png'])->save();
    Storage::disk('public')->put('seasons/branding/logo.png', 'x');

    $report = e42_ready()->report($season->fresh('campaign'));
    $warnings = implode(' | ', $report['warnings']);
    expect($report['ready'])->toBeTrue()
        ->and($warnings)->toContain('ملف صورة الغلاف المشار إليه غير موجود بالتخزين')->not->toContain('ملف الشعار المشار إليه');

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();
    expect($html)->not->toContain('seasons/branding/gone.png');
});

// ───────────────────────────── CSRF ─────────────────────────────

test('E42-BS-04: with CSRF enforcement active the builder cannot be driven without a token - the season is untouched', function () {
    $season = e42_season(['published' => false]);
    $this->actingAs(e42_admin());
    $original = $this->app['env'];
    $this->app['env'] = 'local'; // VerifyCsrfToken يتخطى الفحص فقط حين تكون البيئة testing

    try {
        $this->post('/livewire/update', ['components' => []])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect($season->fresh()->is_published)->toBeFalse();
});

// ───────────────────────────── العزل الشامل ─────────────────────────────

/** جداول المال/المكافآت/السمعة/الإحالة/الرعاية/النتائج/المسار/التقدّم - لا تكتب فيها حياة الموسم أبدًا. @return list<string> */
function e42bs_guarded_tables(): array
{
    // مطابقة على «كلمات» اسم الجدول (مفصولة بـ_) لا على أجزاء نصه: لا يلتقط password_reset_tokens (pass) ولا engagement (gem).
    $words = '/^(purchases?|payments?|orders?|invoices?|refunds?|entitlements?|wallets?|currenc(y|ies)|gems?|xp|earnings?|payouts?|reputations?|referrals?|sponsors?|sponsorships?|results?|standings?|leaderboards?|scores?|pass|passes|rewards?|ledgers?|streaks?|store|quests?|assignments?)$/';

    return collect(Schema::getTables())->pluck('name')
        ->filter(fn ($t) => collect(explode('_', (string) $t))->contains(fn ($w) => preg_match($words, $w) === 1) && ! str_starts_with($t, 'season') && $t !== 'ahjiyat_passes')
        ->values()->all();
}

/** @param list<string> $tables @return array<string, string> بصمة محتوى كل جدول (العدد + تجزئة الصفوف) */
function e42bs_fingerprint(array $tables): array
{
    $out = [];

    foreach ($tables as $t) {
        $rows = DB::table($t)->get();
        $out[$t] = $rows->count().':'.md5($rows->toJson());
    }

    return $out;
}

test('E42-BS-05: a whole season life - build, link, publish, process, view, archive - writes nothing to money, rewards, reputation, referral, sponsorship, results or pass tables', function () {
    $admin = e42_admin();
    $b = e42_builder();
    $season = e42_season(['published' => false, 'window' => ['2026-12-01 00:00:00', '2026-12-30 00:00:00']]);
    $extra = e42_campaign();
    $event = e17Event();
    $pool = e34_pool(e34_quests(2));
    $pass = e40_pass();
    $player = e42_join($season);

    $tables = e42bs_guarded_tables();
    expect(count($tables))->toBeGreaterThanOrEqual(10); // الفحص ليس فارغًا

    $before = e42bs_fingerprint($tables);
    $passBefore = $pass->fresh()->toArray();
    $eventBefore = $event->fresh()->toArray();

    e42_phase($season, 'الأولى', '2026-12-01 00:00:00', '2026-12-10 11:00:00', [], $admin); // نفس المدير: إنشاء مدير جديد ينشئ محفظة صفرية له (ليس من الموسم)
    e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00', [], $admin);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->id);
    $b->linkPass($admin, $season, $pass->id);
    $b->createAnnouncement($admin, $season, ['title' => 'انطلقت المرحلة الثانية', 'body' => 'نص', 'publish_now' => true, 'notify_audience' => 'participants']);
    $b->publish($admin, $season);

    e42_processor()->run();

    $this->actingAs($player)->get(route('seasons.show', $season))->assertOk();
    $this->get(route('seasons.index'))->assertOk();

    $b->archive($admin, $season->fresh());
    $this->get(route('seasons.archive'))->assertOk();

    expect(e42bs_fingerprint($tables))->toBe($before)
        ->and($pass->fresh()->toArray())->toBe($passBefore)
        ->and($event->fresh()->toArray())->toBe($eventBefore);
});
