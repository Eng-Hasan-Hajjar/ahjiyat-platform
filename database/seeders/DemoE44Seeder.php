<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\Season;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupporterPackageService;
use App\Services\SeasonalRecognition\SeasonWriterRosterService;
use App\Services\Sponsorship\SponsorshipProgramService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E44 - بيانات Demo للتقدير الموسمي.   php artisan db:seed --class=DemoE44Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق recognition.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من أي seeder آخر.
 * - تعمل **بالخدمات الرسمية فقط**: إعدادات الدوري، إضافة الكاتب وربط سلاسله، برنامج رعاية E30 للموسم ثم الباقات الافتراضية (فضي/ذهبي/ماسي).
 * - **لا تاريخ مالي مزيَّف**: لا دفعة محقَّقة ولا بطاقة مفعَّلة ولا نقاط دعم ولا نجوم ولا ترتيب نهائي ولا جائزة. الدفع والتحقق والتصويت يجرّبها المختبِر بنفسه عبر المسارات الحقيقية.
 * - المفتاحان العامان يُفعَّلان أثناء التشغيل فقط (لبناء الإعدادات) ثم يعودان لقيمتيهما؛ فبقاؤهما معطَّلين يوقف التصويت وطلبات البطاقات الجديدة (صفحة الدوري تبقى مقروءة لأن له قائمة، وصفحة الداعمين مخفية حتى التفعيل). بلا كتّاب بلا سلاسل.
 * - ترتيب الدوري: تفعيل ← قائمة (كاتب + سلاسله) ← فتح التصويت أخيرًا (القائمة تُقفل عند الفتح).
 * - Idempotent: موسم واحد بمعرّف ثابت، وكل خطوة تتخطى الموجود؛ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE44Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-recognition-admin@recognition.test';

    public const PLAYER_EMAIL = 'demo-recognition-player@recognition.test';

    public const SEASON_SLUG = 'demo-e44-recognition';

    public function run(): void
    {
        $this->refuseInProduction('DemoE44Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير التقدير الموسمي التجريبي', 'administrator');
        $this->user(self::PLAYER_EMAIL, 'لاعب التقدير التجريبي');

        $creatorUser = User::query()->where('email', DemoCreatorSeriesSeeder::CREATOR_EMAIL)->first();

        if ($creatorUser === null || ! CreatorSeries::query()->whereHas('creator', fn ($q) => $q->where('user_id', $creatorUser->id))->whereNotNull('published_revision_id')->exists()) {
            $this->call(DemoCreatorSeriesSeeder::class);
            $creatorUser = User::query()->where('email', DemoCreatorSeriesSeeder::CREATOR_EMAIL)->firstOrFail();
        }

        $profile = CreatorProfile::query()->where('user_id', $creatorUser->id)->firstOrFail();

        $wasWriters = config('seasonal_recognition.writers.enabled');
        $wasSupporters = config('seasonal_recognition.supporters.enabled');
        config(['seasonal_recognition.writers.enabled' => true, 'seasonal_recognition.supporters.enabled' => true]);

        try {
            $season = $this->season();
            $this->writers($season, $admin, $profile);
            $this->supporters($season, $admin);
        } finally {
            config(['seasonal_recognition.writers.enabled' => $wasWriters, 'seasonal_recognition.supporters.enabled' => $wasSupporters]);
        }

        $this->command?->info('DemoE44Seeder: موسم «'.self::SEASON_SLUG.'» بدوري كتّاب (كاتب واحد بسلاسله) وبرنامج رعاية وباقات داعمين (فضي/ذهبي/ماسي). لا نجوم ولا بطاقات ولا نقاط مزيَّفة. فعّل مفتاحَي E44 في البيئة لتفتح التصويت والبطاقات وصفحة الداعمين: /seasons/'.self::SEASON_SLUG.'/writers و/supporters . الدخول: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    protected function season(): Season
    {
        $existing = Season::query()->where('slug', self::SEASON_SLUG)->first();

        if ($existing !== null) {
            return $existing;
        }

        $campaign = Campaign::query()->create(['slug' => 'demo-e44-primary', 'title' => 'موسم التقدير التجريبي', 'description' => 'موسم تجريبي لعرض كتّاب الموسم وداعميه.', 'is_active' => true, 'starts_at' => null, 'ends_at' => null]);
        $stage = CampaignStage::query()->create(['campaign_id' => $campaign->getKey(), 'title' => 'المرحلة الأولى', 'sort_order' => 1]);
        $gate = CampaignGate::query()->create(['campaign_stage_id' => $stage->getKey(), 'title' => 'البوابة الأولى', 'sort_order' => 1]);
        CampaignStep::query()->create([
            'campaign_gate_id' => $gate->getKey(), 'kind' => CampaignStep::KIND_NARRATIVE, 'sort_order' => 1, 'title' => 'البداية',
            'content' => ['body' => 'نص سردي تجريبي لعرض الحملة.'], 'reward_mode' => CampaignStep::REWARD_MODE_INHERIT,
        ]);

        $start = now()->subDays(5)->startOfDay();

        $season = new Season;
        $season->fill([
            'campaign_id' => $campaign->getKey(),
            'code' => 'D44',
            'slug' => self::SEASON_SLUG,
            'is_published' => true,
            'is_featured' => false,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(45),
        ]);
        $season->save();

        return $season;
    }

    protected function writers(Season $season, User $admin, CreatorProfile $profile): void
    {
        $settings = app(SeasonRecognitionSettingsService::class);
        $setting = $settings->ensure($season);

        // الترتيب مهم: القائمة تُقفل عند فتح التصويت، فنفعّل الدوري ونبني القائمة (كاتب + سلاسله) ثم نفتح التصويت أخيرًا.
        $this->tryDo(function () use ($settings, $season, $admin, $setting) {
            if (! $setting->writers_enabled) {
                $settings->saveWriterSettings($season, ['enabled' => true, 'capacity' => 20, 'eliminated_count' => 5], $admin);
            }
        });

        $this->tryDo(function () use ($season, $admin, $profile) {
            $roster = app(SeasonWriterRosterService::class);
            $entry = \App\Models\SeasonWriterEntry::query()->where('season_id', $season->id)->where('creator_profile_id', $profile->id)->first()
                ?? $roster->addWriter($season, $profile, $admin);

            CreatorSeries::query()->where('creator_profile_id', $profile->id)->whereNotNull('published_revision_id')->orderBy('id')->limit(2)->get()
                ->each(function (CreatorSeries $series) use ($roster, $entry, $admin) {
                    if (! \App\Models\SeasonWriterSeries::query()->where('writer_entry_id', $entry->id)->where('creator_series_id', $series->id)->exists()) {
                        $roster->linkSeries($entry, $series, $admin);
                    }
                });
        });

        $this->tryDo(function () use ($settings, $season, $admin) {
            if ($settings->settingFor($season)?->writers_voting_opens_at !== null) {
                return;
            }

            $end = $settings->seasonEnd($season);
            $closes = ($end ?? now()->addDays(40))->copy()->subDays(8);

            $settings->saveWriterSettings($season, ['opens_at' => now()->subDay()->startOfHour(), 'closes_at' => $closes], $admin);
        });
    }

    protected function supporters(Season $season, User $admin): void
    {
        $this->tryDo(function () use ($season, $admin) {
            app(SeasonRecognitionSettingsService::class)->setSupportersEnabled($season, true, $admin);

            $programs = app(SponsorshipProgramService::class);
            $program = SponsorshipProgram::query()->where('target_type', SponsorshipProgram::TARGET_SEASON)->where('target_id', $season->id)->orderBy('id')->first()
                ?? $programs->create($admin, ['title' => 'دعم الموسم التجريبي', 'description' => 'برنامج رعاية تجريبي لبطاقات الداعم.', 'target_type' => SponsorshipProgram::TARGET_SEASON, 'target_id' => $season->id]);

            if ($program->isDraft()) {
                $program = $programs->activate($admin, $program);
            }

            app(SeasonSupporterPackageService::class)->seedDefaults($season, $program, $admin);
        });
    }

    protected function user(string $email, string $name, ?string $role = null): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE44Seeder: تخطّي خطوة - '.$e->getMessage());
        }
    }
}
