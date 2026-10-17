<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E30 (أ) - بيانات Demo للرعاية والداعمين.   php artisan db:seed --class=DemoSponsorshipSeeder
 *
 * - اختيارية وترفض الإنتاج. مستقلة عن DemoQaSeeder (أرقام Demo المثبَّتة بالاختبارات لا تتغير) وعن سلاسل المبدعين.
 * - **بالخدمات الرسمية فقط** (ملفات داعمين، برامج، تسجيل/تأكيد/استرداد مساهمات، تعيين الراعي الرئيسي): لا كتابة مباشرة بحالة أو مبلغ.
 * - **Idempotent**: ملف الداعم بالاسم، البرنامج بالعنوان، المساهمة بمرجعها (DEMO-E30-xx). إعادة التشغيل لا تضاعف شيئًا.
 * - لا تفعّل خطة تعزيز المكافآت: عرض الرعاية لا يغيّر اقتصاد المكافآت في بيئة العرض. (فعّلها من لوحة الإدارة إن أردت رؤية التعزيز.)
 * - الحساب الإداري والحساب المرتبط بداعم بكلمة مرور معروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoSponsorshipSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-sponsorship-admin@sponsorship.test';

    public const SUPPORTER_EMAIL = 'demo-supporter-ahmad@sponsorship.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoSponsorshipSeeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الرعاية التجريبي');
        $admin->syncRoles(['administrator']);
        $linked = $this->user(self::SUPPORTER_EMAIL, 'أحمد الداعم');

        $supporters = app(SupporterService::class);
        $programs = app(SponsorshipProgramService::class);
        $contributions = app(ContributionService::class);

        // 1 شركة راعٍ رئيسي + 2 مؤسستان داعمتان + 3 أفراد + 1 داعم يفضّل عدم الظهور.
        $people = [
            'main' => $this->supporter($supporters, $admin, ['display_name' => 'شركة النخبة للتقنية', 'type' => 'organization', 'description' => 'شركة تقنية تدعم المحتوى العربي الذكي.', 'website_url' => 'https://example.com', 'is_public' => true]),
            'org1' => $this->supporter($supporters, $admin, ['display_name' => 'مؤسسة الأفق التعليمية', 'type' => 'organization', 'description' => 'مؤسسة تعليمية داعمة للتفكير والمعرفة.', 'is_public' => true]),
            'org2' => $this->supporter($supporters, $admin, ['display_name' => 'جمعية دعم الابتكار', 'type' => 'organization', 'is_public' => true]),
            'ahmad' => $this->supporter($supporters, $admin, ['display_name' => 'أحمد الداعم', 'type' => 'individual', 'is_public' => true, 'user_id' => $linked->id]),
            'sara' => $this->supporter($supporters, $admin, ['display_name' => 'سارة الداعمة', 'type' => 'individual', 'is_public' => true]),
            'khaled' => $this->supporter($supporters, $admin, ['display_name' => 'خالد المحب', 'type' => 'individual', 'is_public' => true]),
            'anon' => $this->supporter($supporters, $admin, ['display_name' => 'داعم كريم', 'type' => 'individual', 'is_public' => true, 'anonymous_by_default' => true]),
        ];

        $platform = $this->program($programs, $admin, 'دعم منصة أحجيات', ['target_type' => 'platform', 'description' => 'دعم عام لتشغيل المنصة وتطويرها.', 'goal_amount' => '5000', 'show_public_amounts' => true, 'show_goal_progress' => true]);
        $season = $this->program($programs, $admin, 'رعاية الموسم التجريبي', ['target_type' => 'season', 'target_id' => $this->season()->id, 'description' => 'برنامج رعاية مرتبط بموسم تجريبي.', 'goal_amount' => '1000', 'show_public_amounts' => true, 'show_goal_progress' => true]);

        // [مرجع، الداعم، البرنامج، المبلغ، الحالة، مجهول؟]
        $rows = [
            ['DEMO-E30-01', 'main', $platform, '2500', 'confirmed', null],
            ['DEMO-E30-02', 'org1', $platform, '600', 'confirmed', null],
            ['DEMO-E30-03', 'org2', $platform, '150', 'confirmed', null],
            ['DEMO-E30-04', 'ahmad', $platform, '40', 'confirmed', null],
            ['DEMO-E30-05', 'sara', $platform, '12', 'confirmed', null],
            ['DEMO-E30-06', 'khaled', $platform, '25', 'pending', null],
            ['DEMO-E30-07', 'anon', $platform, '80', 'confirmed', true],
            ['DEMO-E30-08', 'khaled', $platform, '30', 'refunded', null],
            ['DEMO-E30-09', 'main', $season, '500', 'confirmed', null],
            ['DEMO-E30-10', 'org1', $season, '200', 'confirmed', null],
            ['DEMO-E30-11', 'sara', $season, '20', 'pending', null],
        ];

        foreach ($rows as [$reference, $who, $program, $amount, $state, $anonymous]) {
            $this->contribution($contributions, $admin, $people[$who], $program, $reference, $amount, $state, $anonymous);
        }

        // الراعي الرئيسي لبرنامجي المنصة والموسم، وراعٍ داعم واحد (يُعيَّن فقط من له مساهمة مؤكَّدة).
        $this->role($programs, $admin, $platform, $people['main'], SponsorshipProgramRole::ROLE_MAIN_SPONSOR);
        $this->role($programs, $admin, $season, $people['main'], SponsorshipProgramRole::ROLE_MAIN_SPONSOR);
        $this->role($programs, $admin, $platform, $people['org1'], SponsorshipProgramRole::ROLE_SPONSOR);

        $this->command?->info('DemoSponsorshipSeeder: داعمون ('.SupporterProfile::query()->count().')، برامج ('.SponsorshipProgram::query()->count().')، مساهمات ('.SponsorshipContribution::query()->count().'). كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    protected function supporter(SupporterService $service, User $admin, array $data): SupporterProfile
    {
        return SupporterProfile::query()->where('display_name', $data['display_name'])->first() ?? $service->create($admin, $data);
    }

    protected function program(SponsorshipProgramService $service, User $admin, string $title, array $data): SponsorshipProgram
    {
        $program = SponsorshipProgram::query()->where('title', $title)->first();

        if ($program === null) {
            $program = $service->create($admin, ['title' => $title] + $data);
        }

        return $program->status === SponsorshipProgram::STATUS_DRAFT ? $service->activate($admin, $program) : $program;
    }

    /** يعيّن الدور فقط إن لم يكن قائمًا (إعادة التشغيل لا تُنتج تدقيقًا مكررًا). */
    protected function role(SponsorshipProgramService $service, User $admin, SponsorshipProgram $program, SupporterProfile $supporter, string $role): void
    {
        $exists = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->where('role', $role)->exists();

        if (! $exists) {
            $service->assignRole($admin, $program, $supporter, $role);
        }
    }

    protected function contribution(ContributionService $service, User $admin, SupporterProfile $supporter, SponsorshipProgram $program, string $reference, string $amount, string $state, ?bool $anonymous): void
    {
        $contribution = SponsorshipContribution::query()->where('reference', $reference)->first()
            ?? $service->record($admin, $supporter, $program, $amount, 'USD', ['reference' => $reference, 'notes' => 'مساهمة تجريبية للعرض فقط.', 'is_anonymous' => $anonymous]);

        if ($state !== 'pending' && $contribution->status === SponsorshipContribution::STATUS_PENDING) {
            $contribution = $service->confirm($admin, $contribution);
        }

        if ($state === 'refunded' && $contribution->status === SponsorshipContribution::STATUS_CONFIRMED) {
            $service->refund($admin, $contribution, 'استرداد تجريبي لعرض الحالة بالواجهة.');
        }
    }

    /** أول موسم قائم، وإلا يُنشأ موسم تجريبي صغير (حملة + موسم) بالمصانع - للعرض المحلي فقط. */
    protected function season(): Season
    {
        return Season::query()->orderBy('id')->first()
            ?? Season::factory()->create(['campaign_id' => Campaign::factory()->create(['is_active' => true])->id, 'is_published' => true]);
    }
}
