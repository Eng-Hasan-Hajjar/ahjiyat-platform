<?php

use App\Models\Season;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoE44Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__.'/_support.php';

/*
| E44 / بذور Demo (اختيارية): ترفض الإنتاج، تبني الموسم بالخدمات الرسمية فقط (إعدادات ← قائمة ← سلاسل ← فتح التصويت ← برنامج رعاية ← باقات)، لا تاريخ مالي مزيَّف،
| لا تغيّر المفتاحين العامين، idempotent، ولا يستدعيها أي seeder آخر. الخطوة المتخطّاة (تحذير) تُعدّ فشلًا.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

/**
 * يشغّل البذرة كأمر Artisan فيعود المخرج (التحذيرات «تخطّي خطوة» ظاهرة فيه).
 * مخزن مخرجات خاص بنا عمدًا: RolesAndPermissionsSeeder يستدعي Artisan::call('rbac:migrate-legacy-roles') داخليًا فيستبدل Artisan::output() بمخرج الاستدعاء المتداخل،
 * فيبدو المخرج خاليًا من تحذيراتنا حتى لو وُجدت.
 */
function e44_sd_run(): string
{
    $buffer = new BufferedOutput;
    Artisan::call('db:seed', ['--class' => DemoE44Seeder::class, '--no-interaction' => true], $buffer);

    return $buffer->fetch();
}

/** عدّادات E44 كلها + كل ما يجب ألا تلمسه البذور (التاريخ المالي والتقدير). */
function e44_sd_counts(): array
{
    $tables = ['seasons', 'season_recognition_settings', 'season_writer_entries', 'season_writer_series', 'season_writer_vote_budgets', 'season_writer_star_allocations', 'season_writer_awards',
        'season_supporter_packages', 'season_supporter_cards', 'season_support_point_entries', 'season_supporter_stats', 'sponsorship_programs', 'sponsorship_payments',
        'sponsorship_contributions', 'store_purchases', 'operational_audit_logs'];

    return collect($tables)->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->count()])->all();
}

test('E44-SD-01: the demo seeder refuses production and creates nothing', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DemoE44Seeder::class)->run())->toThrow(RuntimeException::class);
    expect(Season::query()->where('slug', DemoE44Seeder::SEASON_SLUG)->exists())->toBeFalse()->and(User::query()->where('email', DemoE44Seeder::ADMIN_EMAIL)->exists())->toBeFalse();
});

test('E44-SD-02: it builds one season with an enabled league (one writer, two linked series, voting open and closing a week before the end), a sponsorship program and three packages — with no skipped step', function () {
    $output = e44_sd_run();
    $season = Season::query()->where('slug', DemoE44Seeder::SEASON_SLUG)->firstOrFail();
    $setting = e44_settings()->settingFor($season);
    $counts = e44_sd_counts();
    $end = e44_settings()->seasonEnd($season);

    expect($output)->not->toContain('تخطّي خطوة')->toContain('DemoE44Seeder:')
        ->and(Season::query()->where('slug', DemoE44Seeder::SEASON_SLUG)->count())->toBe(1)
        ->and($counts['season_writer_entries'])->toBe(1)->and($counts['season_writer_series'])->toBe(2)
        ->and($counts['season_supporter_packages'])->toBe(3)->and($counts['sponsorship_programs'])->toBe(1)
        ->and($setting->writers_enabled)->toBeTrue()->and($setting->supporters_enabled)->toBeTrue()->and((int) $setting->writers_capacity)->toBe(20)
        ->and(e44_settings()->votingState($season, $setting))->toBe('open')
        ->and($end)->not->toBeNull()->and($setting->writers_voting_closes_at->lte($end->copy()->subDays(7)))->toBeTrue();

    $packages = DB::table('season_supporter_packages')->where('season_id', $season->id)->orderBy('sort_order')->get();

    expect($packages->pluck('tier_key')->all())->toBe(['silver', 'gold', 'diamond'])
        ->and($packages->pluck('price_minor')->map(fn ($v) => (int) $v)->all())->toBe([2500, 10000, 25000])
        ->and($packages->pluck('support_points')->map(fn ($v) => (int) $v)->all())->toBe([250, 1000, 2500])
        ->and(DB::table('sponsorship_programs')->where('target_type', 'season')->where('target_id', $season->id)->count())->toBe(1);

    // الحسابان التجريبيان: مدير بدور administrator ولاعب عادي بلا أي صلاحية إدارية (قد يحمل دور player القياسي من ترحيل الأدوار)، وكلاهما موثَّق.
    $admin = User::query()->where('email', DemoE44Seeder::ADMIN_EMAIL)->firstOrFail();
    $player = User::query()->where('email', DemoE44Seeder::PLAYER_EMAIL)->firstOrFail();

    expect($admin->hasRole('administrator'))->toBeTrue()->and($admin->email_verified_at)->not->toBeNull()
        ->and($player->hasRole('administrator'))->toBeFalse()->and($player->can('admin.access'))->toBeFalse()->and($player->can('seasonal-recognition.view'))->toBeFalse()->and($player->email_verified_at)->not->toBeNull();
});

test('E44-SD-03: no fabricated history — no stars, budgets, awards, cards, payments, contributions, support points, stats or final snapshots', function () {
    e44_sd_run();
    $counts = e44_sd_counts();

    foreach (['season_writer_vote_budgets', 'season_writer_star_allocations', 'season_writer_awards', 'season_supporter_cards', 'season_support_point_entries', 'season_supporter_stats',
        'sponsorship_payments', 'sponsorship_contributions', 'store_purchases'] as $table) {
        expect($counts[$table])->toBe(0, $table);
    }

    $setting = e44_settings()->settingFor(Season::query()->where('slug', DemoE44Seeder::SEASON_SLUG)->firstOrFail());

    expect($setting->writers_finalized_at)->toBeNull()->and($setting->supporters_finalized_at)->toBeNull()
        ->and((int) DB::table('season_writer_entries')->sum('stars_total'))->toBe(0)->and((int) DB::table('season_writer_entries')->sum('voters_count'))->toBe(0);
});

test('E44-SD-04: it is idempotent — a second and a third run change nothing and skip nothing', function () {
    expect(e44_sd_run())->not->toContain('تخطّي خطوة');
    $first = e44_sd_counts();

    expect(e44_sd_run())->not->toContain('تخطّي خطوة')->and(e44_sd_counts())->toBe($first);
    expect(e44_sd_run())->not->toContain('تخطّي خطوة')->and(e44_sd_counts())->toBe($first);
});

test('E44-SD-05: it leaves the two global flags exactly as it found them — flags off stop new actions and keep the supporters page hidden, while the existing roster stays readable; flags on render both pages', function () {
    config(['seasonal_recognition.writers.enabled' => false, 'seasonal_recognition.supporters.enabled' => false]);

    e44_sd_run();

    expect(config('seasonal_recognition.writers.enabled'))->toBeFalse()->and(config('seasonal_recognition.supporters.enabled'))->toBeFalse();

    $season = Season::query()->where('slug', DemoE44Seeder::SEASON_SLUG)->firstOrFail();
    $setting = e44_settings()->settingFor($season);
    $slug = DemoE44Seeder::SEASON_SLUG;

    // المفتاح العام معطَّل ⇒ لا تصويت ولا بطاقات جديدة؛ لكن للدوري قائمة فتبقى صفحته مقروءة (التعطيل لا يخفي تاريخًا)، وصفحة الداعمين مخفية حتى التفعيل.
    expect(e44_settings()->writersActive($season, $setting))->toBeFalse()->and(e44_settings()->supportersActive($season, $setting))->toBeFalse();

    $this->get("/seasons/{$slug}/writers")->assertOk();
    $this->get("/seasons/{$slug}/supporters")->assertNotFound();

    config(['seasonal_recognition.writers.enabled' => true, 'seasonal_recognition.supporters.enabled' => true]);

    $writerSlug = \App\Models\CreatorProfile::query()->whereIn('id', DB::table('season_writer_entries')->pluck('creator_profile_id'))->value('slug');

    expect(e44_settings()->writersActive($season, $setting->fresh()))->toBeTrue();

    $this->get("/seasons/{$slug}/writers")->assertOk()->assertSee('الترتيب الحيّ')->assertSee('data-rank="1"', false)->assertSee($writerSlug);
    $this->get("/seasons/{$slug}/supporters")->assertOk()->assertSee('عن بطاقات الداعم');
});

test('E44-SD-06: the seeder is optional — no other seeder calls it, it uses only the official services, and it carries no fabricated money or points writes', function () {
    foreach (glob(base_path('database/seeders/*.php')) ?: [] as $file) {
        if (basename($file) === 'DemoE44Seeder.php') {
            continue;
        }

        expect(file_get_contents($file))->not->toContain('DemoE44Seeder');
    }

    $code = e44_code('database/seeders/DemoE44Seeder.php');

    expect($code)->toContain('RefusesProduction')->toContain('refuseInProduction')
        ->toContain('SeasonRecognitionSettingsService')->toContain('SeasonWriterRosterService')->toContain('SeasonSupporterPackageService')->toContain('SponsorshipProgramService');

    foreach (['SeasonSupportPointService', 'SeasonWriterVotingService', 'SeasonSupporterCardService', 'SeasonWriterFinalizer', 'SeasonSupporterFinalizer', 'SponsorshipPaymentService',
        'DB::table', 'insert(', 'SponsorshipPayment', 'StorePurchase'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }
});
