<?php

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| E32 (الدفع) - مساعدات مشتركة. كل شيء على SQLite وكاش array وقرص تخزين وهمي؛ لا شبكة ولا بوابة دفع ولا بيانات بنكية.
| كل تهيئة تمرّ بالخدمات الرسمية (SponsorshipPaymentService)؛ لا كتابة مباشرة بحالة أو مبلغ إلا عند اختبار الحراسة نفسها.
| تعتمد على مساعدات E30 (e30_*) و E28 (e28_*) و E16 (e16User).
*/

require_once __DIR__.'/../SponsorshipE30/_support.php';

if (! function_exists('e32_seed')) {
    function e32_seed(): void
    {
        e30_seed();
        Storage::fake('local');
        Cache::flush();
        config(['payments.enabled' => true, 'payments.notifications.enabled' => true]);
    }

    function e32_payments(): SponsorshipPaymentService
    {
        return app(SponsorshipPaymentService::class);
    }

    /** مدير مالي (sponsorships.confirm). */
    function e32_finance(): User
    {
        return e30_admin();
    }

    /** مدير محتوى: يرى ويدير الرعاية بلا تأكيد مالي. */
    function e32_manager(): User
    {
        return e30_manager();
    }

    function e32_program(array $over = [], bool $activate = true): SponsorshipProgram
    {
        return e30_program($over, $activate);
    }

    /** طلب دعم بانتظار الدفع بالخدمة الرسمية. */
    function e32_request(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false, string $currency = 'USD', ?string $key = null, ?string $name = null): SponsorshipPayment
    {
        $user ??= e16User();
        $program ??= e32_program();

        return e32_payments()->createRequest($user, $program, $amount, $currency, $anonymous, $name, 'manual', $key);
    }

    /** طلب مُرسَل (حوّلت المبلغ) مع إثبات اختياري. */
    function e32_submitted(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false, bool $withProof = true): SponsorshipPayment
    {
        $user ??= e16User();
        $payment = e32_request($user, $program, $amount, $anonymous);

        return e32_payments()->markTransferred($user, $payment, 'حوّلت من حسابي', $withProof ? e32_proof() : null);
    }

    /** طلب محقَّق (مساهمة مؤكَّدة مجسَّدة). */
    function e32_verified(?User $user = null, ?SponsorshipProgram $program = null, string|int $amount = '10', bool $anonymous = false): SponsorshipPayment
    {
        $payment = e32_submitted($user, $program, $amount, $anonymous);

        return e32_payments()->verify(e32_finance(), $payment);
    }

    /** إثبات وهمي: png | jpg | webp | pdf | svg | exe | html | big. */
    function e32_proof(string $kind = 'png'): UploadedFile
    {
        return match ($kind) {
            'png' => UploadedFile::fake()->image('proof.png', 20, 20),
            'jpg' => UploadedFile::fake()->image('proof.jpg', 20, 20),
            'webp' => e32_webp(),
            'pdf' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
            'svg' => UploadedFile::fake()->createWithContent('proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'exe' => UploadedFile::fake()->createWithContent('proof.png', "MZ\x90\x00\x03\x00\x00\x00 fake executable payload"),
            'html' => UploadedFile::fake()->createWithContent('proof.pdf', '<html><script>alert(1)</script></html>'),
            'big' => UploadedFile::fake()->create('proof.pdf', 6000, 'application/pdf'),
            default => throw new InvalidArgumentException($kind),
        };
    }

    function e32_webp(): UploadedFile
    {
        $image = imagecreatetruecolor(16, 16);
        $path = tempnam(sys_get_temp_dir(), 'e32w');
        imagewebp($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'proof.webp', 'image/webp', null, true);
    }

    function e32_funding(SponsorshipProgram $program): int
    {
        return app(SupportTotals::class)->programFunding($program->id);
    }

    function e32_contribution(SponsorshipPayment $payment): ?SponsorshipContribution
    {
        return SponsorshipContribution::query()->find($payment->fresh()->sponsorship_contribution_id);
    }

    function e32_audit(string $action): int
    {
        return DB::table('operational_audit_logs')->where('action', $action)->count();
    }

    /** إشعارات E15 لمستخدم (اختياريًا بنوع). */
    function e32_notes(User $user, ?string $type = null): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->when($type, fn ($q) => $q->where('type_key', $type))->orderBy('created_at')->get();
    }
}
