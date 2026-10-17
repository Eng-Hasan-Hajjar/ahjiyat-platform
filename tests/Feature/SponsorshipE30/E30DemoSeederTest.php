<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use Database\Seeders\DemoCreatorSeriesSeeder;
use Database\Seeders\DemoE30Seeder;
use Database\Seeders\DemoSponsorshipSeeder;
use Illuminate\Support\Facades\DB;

/*
| E30 - بيانات Demo: التركيبة المطلوبة، إعادة التشغيل لا تضاعف شيئًا، رفض الإنتاج، واستقلال الوحدتين.
*/

test('AM-D1: the sponsorship demo seeds the required mix and re-running it duplicates nothing', function () {
    $this->seed(DemoSponsorshipSeeder::class);

    $snapshot = fn () => [
        SupporterProfile::query()->count(), SponsorshipProgram::query()->count(), SponsorshipContribution::query()->count(),
        SponsorshipProgramRole::query()->count(), DB::table('operational_audit_logs')->count(),
    ];
    $first = $snapshot();

    expect(SupporterProfile::query()->where('type', 'organization')->count())->toBe(3)         // الراعي الرئيسي + مؤسستان داعمتان
        ->and(SupporterProfile::query()->where('type', 'individual')->count())->toBe(4)        // 3 أفراد + داعم يفضّل عدم الظهور
        ->and(SupporterProfile::query()->where('anonymous_by_default', true)->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('target_type', 'platform')->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('target_type', 'season')->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('status', 'active')->count())->toBe(2)
        ->and(SponsorshipContribution::query()->where('status', 'confirmed')->count())->toBeGreaterThan(0)
        ->and(SponsorshipContribution::query()->where('status', 'pending')->count())->toBe(2)
        ->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1)
        ->and(SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(2)
        ->and(Season::query()->count())->toBeGreaterThanOrEqual(1);

    $this->seed(DemoSponsorshipSeeder::class);

    expect($snapshot())->toBe($first);
});

test('AM-D2: the demo sponsorship pages render, the anonymous supporter stays anonymous and no private note or reference leaks', function () {
    $this->seed(DemoSponsorshipSeeder::class);

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();

    expect($html)->toContain('شركة النخبة للتقنية')->and(str_contains($html, 'DEMO-E30-'))->toBeFalse()->and(str_contains($html, 'مساهمة تجريبية للعرض فقط'))->toBeFalse();
});

test('AM-D3: the creator series demo seeds 2 published, 1 pending and 1 draft series, idempotently', function () {
    $this->seed(DemoCreatorSeriesSeeder::class);

    $by = fn (string $status) => CreatorSeriesRevision::query()->where('status', $status)->count();
    $first = [CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count()];

    expect(CreatorSeries::query()->whereNotNull('published_revision_id')->count())->toBe(2)
        ->and($by('approved'))->toBe(2)->and($by('pending_review'))->toBe(1)->and($by('draft'))->toBe(1);

    $this->seed(DemoCreatorSeriesSeeder::class);

    expect([CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count()])->toBe($first);

    $series = CreatorSeries::query()->whereNotNull('published_revision_id')->first();
    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(DB::table('currency_transactions')->count())->toBe(0);   // لا أثر اقتصادي
});

test('AM-D4: the combined seeder runs both modules; both seeders refuse production', function () {
    $this->seed(DemoE30Seeder::class);

    expect(SupporterProfile::query()->count())->toBeGreaterThan(0)->and(CreatorSeries::query()->count())->toBeGreaterThan(0);

    app()['env'] = 'production';

    foreach ([DemoSponsorshipSeeder::class, DemoCreatorSeriesSeeder::class, DemoE30Seeder::class] as $seeder) {
        expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class);
    }
});
