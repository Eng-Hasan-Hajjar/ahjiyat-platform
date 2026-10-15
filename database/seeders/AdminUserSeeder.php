<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@ahjiyat.app';

    /** كلمة المرور الافتراضية للتطوير المحلي فقط - ممنوعة بالإنتاج (انظر bootstrap بالأسفل). */
    public const DEFAULT_PASSWORD = 'change-me-now';

    public function run(): void
    {
        // E25: بالإنتاج لا كلمة مرور افتراضية معروفة. يلزم ADMIN_BOOTSTRAP_EMAIL وADMIN_BOOTSTRAP_PASSWORD (≥ 12 خانة) من البيئة؛ تُحذفان من البيئة بعد أول تشغيل.
        // خارج الإنتاج يبقى السلوك القديم كما هو (admin@ahjiyat.app / change-me-now).
        [$email, $password] = $this->credentials();

        $admin = User::where('email', $email)->first();

        if (! $admin) {
            $admin = new User();
            // forceFill لازم هون: role وemail_verified_at مو موجودين بـ $fillable
            // (وهذا مقصود بالموديل لأسباب أمان)، فلو استخدمنا create()/firstOrCreate()
            // العاديين كانوا ينحذفوا بصمت والحساب يطلع role=user غير موثّق -
            // يعني ما يقدر يفتح لوحة إدارة Filament إطلاقاً رغم إنه "أدمن" بالاسم.
            $admin->forceFill([
                'name' => 'مدير المنصة',
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $admin->save();
        }

        // المحفظة لازم تحمل العملة الافتراضية المكتسبة (نفس ما يفعله التسجيل العادي)،
        // وإلا ينهار عرض المحفظة لأنه يقرأ currency->type.
        Wallet::firstOrCreate([
            'user_id' => $admin->id,
            'currency_id' => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id,
        ]);

        if (app()->environment('production')) {
            // لا نطبع كلمة المرور أبدًا.
            $this->command?->warn("حساب المدير جاهز ({$email}). احذف ADMIN_BOOTSTRAP_EMAIL وADMIN_BOOTSTRAP_PASSWORD من البيئة الآن، ثم rbac:migrate-legacy-roles إن لم يوجد مدير أعلى.");

            return;
        }

        $this->command?->warn('تم إنشاء حساب المدير: admin@ahjiyat.app / change-me-now - غيّر كلمة المرور فوراً بعد أول دخول.');
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        if (! app()->environment('production')) {
            return [self::DEFAULT_EMAIL, self::DEFAULT_PASSWORD];
        }

        $email = (string) config('operations.admin_bootstrap.email');
        $password = (string) config('operations.admin_bootstrap.password');
        $min = (int) config('operations.admin_bootstrap.min_password_length', 12);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < $min || $password === self::DEFAULT_PASSWORD) {
            throw new RuntimeException("AdminUserSeeder بالإنتاج يتطلب ADMIN_BOOTSTRAP_EMAIL (بريد صالح) وADMIN_BOOTSTRAP_PASSWORD (≥ {$min} خانة وليست الافتراضية) من البيئة. لا كلمة مرور افتراضية بالإنتاج.");
        }

        return [$email, $password];
    }
}