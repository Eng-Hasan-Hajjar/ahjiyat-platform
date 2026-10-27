<?php

use App\Models\AhjiyatPass;
use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use App\Models\SeasonPhase;
use App\Models\User;
use Database\Seeders\DemoE42Seeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e42_seed());

function e42_demo_counts(): array
{
    return [
        'seasons' => Season::query()->where('slug', DemoE42Seeder::SEASON_SLUG)->count(),
        'phases' => SeasonPhase::query()->count(),
        'items' => SeasonContentItem::query()->count(),
        'announcements' => SeasonAnnouncement::query()->count(),
        'users' => User::query()->count(),
        'campaigns' => DB::table('campaigns')->count(),
        'pools' => DB::table('mission_pools')->count(),
        'events' => CompetitiveEvent::query()->count(),
        'passes' => AhjiyatPass::query()->count(),
        'puzzles' => DB::table('puzzles')->count(),
        'audit' => DB::table('operational_audit_logs')->count(),
    ];
}

test('E42-SD-01: the demo seeder refuses production and creates nothing', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DemoE42Seeder::class)->run())->toThrow(RuntimeException::class);
    expect(Season::query()->count())->toBe(0)->and(User::query()->where('email', DemoE42Seeder::ADMIN_EMAIL)->exists())->toBeFalse();
});

test('E42-SD-02: the demo seeder builds a published live season with three phases, linked content and announcements - and is idempotent', function () {
    $this->seed(DemoE42Seeder::class);
    $first = e42_demo_counts();
    $this->seed(DemoE42Seeder::class);

    $season = Season::query()->where('slug', DemoE42Seeder::SEASON_SLUG)->firstOrFail();

    expect(e42_demo_counts())->toBe($first)
        ->and($first['seasons'])->toBe(1)->and($first['phases'])->toBe(3)->and($first['items'])->toBe(4)->and($first['announcements'])->toBe(3)
        ->and($season->is_published)->toBeTrue()
        ->and($first['events'])->toBe(2)->and($first['passes'])->toBe(1)
        ->and($season->contentItems()->where('content_type', SeasonContentItem::TYPE_COMPETITIVE_EVENT)->count())->toBe(2)
        ->and($season->ahjiyat_pass_id)->not->toBeNull()->and(AhjiyatPass::query()->find($season->ahjiyat_pass_id)->internal_key)->toBe('demo-e42-pass')
        ->and(e42_life()->state($season))->toBe('live')
        ->and($season->phases()->pluck('name')->all())->toBe(['الافتتاح', 'ذروة التحدي', 'الختام الكبير'])
        ->and(SeasonPhase::query()->whereNotNull('went_live_at')->count())->toBe(0) // المعالج وحده يعلّم بدء المراحل
        ->and(User::query()->where('email', DemoE42Seeder::ADMIN_EMAIL)->first()->hasRole('administrator'))->toBeTrue();
});

test('E42-SD-03: the demo season renders publicly with the current phase, the visible announcements only, and leaks nothing unscheduled', function () {
    $this->seed(DemoE42Seeder::class);

    $guest = $this->get(route('seasons.show', DemoE42Seeder::SEASON_SLUG));
    $guest->assertOk()->assertSee('ذروة التحدي')->assertSee('منافسة الموسم الجارية')->assertSee('منافسة الموسم القادمة')->assertSee('مسار الموسم التجريبي')->assertSee('أهلًا بكم في الموسم التجريبي')->assertDontSee('إعلان مجدول: الختام الكبير');

    $player = User::query()->where('email', DemoE42Seeder::PLAYER_EMAIL)->firstOrFail();
    $this->actingAs($player)->get(route('seasons.show', DemoE42Seeder::SEASON_SLUG))->assertOk()->assertSee('تحدٍّ إضافي')->assertSee('ذروة التحدي');
});

test('E42-SD-04: the demo seeder grants no gems, cash, XP or rewards, sends no season-phase or announcement notification, and no other seeder calls it', function () {
    $snapshot = fn () => [DB::table('currency_transactions')->count(), DB::table('sponsorship_payments')->count(), DB::table('redemption_requests')->count(), DB::table('wallets')->sum('available_balance'), DB::table('xp_transactions')->count()];
    $before = $snapshot();
    $this->seed(DemoE42Seeder::class);

    expect($snapshot())->toBe($before)
        ->and(DB::table('notifications')->whereIn('type_key', ['season_phase_started', 'season_announcement_published'])->count())->toBe(0)
        // إشعارات دورة الحياة القائمة (إتاحة حملة / بدء موسم) تصل لحسابات العرض فقط - لا لمستخدمين آخرين.
        ->and(DB::table('notifications')->whereNotIn('notifiable_id', User::query()->where('email', 'like', '%@season.test')->pluck('id'))->count())->toBe(0);

    foreach (glob(database_path('seeders/*.php')) as $file) {
        if (basename($file) === 'DemoE42Seeder.php') {
            continue;
        }

        expect(file_get_contents($file))->not->toContain('DemoE42Seeder');
    }

    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('Demo');
});

test('E42-SD-05: the demo season links only its own demo pass - an unrelated existing pass is neither linked nor touched, and no seeder file mentions another demo seeder', function () {
    $other = e40_pass(['key' => 'unrelated-pass']);
    $before = $other->fresh()->toArray();

    $this->seed(DemoE42Seeder::class);

    $season = Season::query()->where('slug', DemoE42Seeder::SEASON_SLUG)->firstOrFail();

    expect(AhjiyatPass::query()->find($season->ahjiyat_pass_id)->internal_key)->toBe('demo-e42-pass')
        ->and(AhjiyatPass::query()->count())->toBe(2)
        ->and($other->fresh()->toArray())->toBe($before)
        // E40 يحرس ألا يذكر seeder آخر DemoE40Seeder (OPS-25): نفس الحارس نطبّقه على seeder الموسم صراحةً.
        ->and(file_get_contents(database_path('seeders/DemoE42Seeder.php')))->not->toContain('DemoE40Seeder');
});
