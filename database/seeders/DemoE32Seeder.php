<?php

namespace Database\Seeders;

use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipProgramService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * E32 - بيانات Demo لطلبات الدعم/الدفع.   php artisan db:seed --class=DemoE32Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (بلا حسابات بنكية ولا أسرار ولا شبكة).
 * - بالخدمات الرسمية فقط (إنشاء/تحويل/تحقق/رفض/استرداد): لا كتابة مباشرة بحالة أو مبلغ.
 * - Idempotent: كل طلب بمفتاح request_key ثابت لكل مستخدم ⇒ إعادة التشغيل لا تُنشئ طلبات مكررة.
 * - تولّد طلبات: معلّق، مُرسَل مع إثبات، محقَّق، مرفوض، مجهول محقَّق، مسترد.
 */
class DemoE32Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-payments-admin@sponsorship.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE32Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الدفع التجريبي');
        $admin->syncRoles(['administrator']);

        $programs = app(SponsorshipProgramService::class);
        $program = SponsorshipProgram::query()->where('title', 'دعم أحجيات (دفع تجريبي)')->first()
            ?? $programs->create($admin, ['title' => 'دعم أحجيات (دفع تجريبي)', 'target_type' => 'platform', 'description' => 'برنامج تجريبي لمسار الدعم والدفع اليدوي.', 'goal_amount' => '2000', 'show_public_amounts' => true, 'show_goal_progress' => true]);

        if ($program->status === SponsorshipProgram::STATUS_DRAFT) {
            $program = $programs->activate($admin, $program);
        }

        $service = app(SponsorshipPaymentService::class);
        $users = [];

        foreach ([
            'pending' => 'ليلى (طلب معلّق)',
            'submitted' => 'عمر (إثبات مُرسَل)',
            'verified' => 'هالة (محقَّق)',
            'rejected' => 'ياسر (مرفوض)',
            'anonymous' => 'داعم مجهول',
            'refunded' => 'نادر (مسترد)',
        ] as $key => $name) {
            $users[$key] = $this->user("demo-e32-{$key}@sponsorship.test", $name);
        }

        $pending = $this->request($service, $users['pending'], $program, '15', false);

        $submitted = $this->request($service, $users['submitted'], $program, '40', false);
        if ($submitted->status === SponsorshipPayment::STATUS_AWAITING) {
            $service->markTransferred($users['submitted'], $submitted, 'حوّلت من حساب عمر', $this->tinyPng());
        }

        $verified = $this->request($service, $users['verified'], $program, '60', false);
        if ($verified->isOpen()) {
            $service->verify($admin, $verified);
        }

        $rejected = $this->request($service, $users['rejected'], $program, '25', false);
        if ($rejected->isOpen()) {
            $service->reject($admin, $rejected, 'not_received', 'لم يصلنا التحويل بهذا المرجع.', 'تجريبي');
        }

        $anonymous = $this->request($service, $users['anonymous'], $program, '120', true);
        if ($anonymous->isOpen()) {
            $service->verify($admin, $anonymous);
        }

        $refunded = $this->request($service, $users['refunded'], $program, '30', false);
        if ($refunded->isOpen()) {
            $refunded = $service->verify($admin, $refunded);
        }
        if ($refunded->status === SponsorshipPayment::STATUS_VERIFIED) {
            $service->refund($admin, $refunded, 'استرداد تجريبي للعرض');
        }

        $this->command?->info('DemoE32Seeder: طلبات الدعم ('.SponsorshipPayment::query()->count().'). الطلب المعلّق: '.$pending->reference.'. كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
    }

    protected function request(SponsorshipPaymentService $service, User $user, SponsorshipProgram $program, string $amount, bool $anonymous): SponsorshipPayment
    {
        return $service->createRequest($user, $program, $amount, 'USD', $anonymous, null, 'manual', 'demo-e32-'.$user->id);
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

    /** صورة PNG وهمية 1×1 كإثبات تجريبي (لا بيانات حقيقية). */
    protected function tinyPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'e32');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'demo-proof.png', 'image/png', null, true);
    }
}
