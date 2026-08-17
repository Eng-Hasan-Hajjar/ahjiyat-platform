<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * كلمة مرور كل المستخدمين التجريبيين (بدون استثناء) عشان تسهيل تسجيل الدخول أثناء الاختبار.
     */
    public const TEST_PASSWORD = 'password';

    public function run(): void
    {
        $users = [

            // ===== حسابات "بطلة" - مؤهلة بالكامل للاستبدال، مفيدة لاختبار كل الواجهات =====
            ['name' => 'يوسف الحمدان', 'email' => 'yousef@ahjiyat.test', 'days_ago' => 75, 'verified' => true],
            ['name' => 'سارة القيسي', 'email' => 'sara@ahjiyat.test', 'days_ago' => 60, 'verified' => true],
            ['name' => 'ريم الشامي', 'email' => 'reem@ahjiyat.test', 'days_ago' => 90, 'verified' => true],

            // ===== حسابات مجمّدة - لاختبار رسالة الحظر عند تسجيل الدخول =====
            ['name' => 'طارق زيدان', 'email' => 'tarek@ahjiyat.test', 'days_ago' => 40, 'verified' => true, 'frozen' => true, 'frozen_reason' => 'نشاط غير طبيعي بمعدل الكسب - قيد المراجعة اليدوية.'],
            ['name' => 'هدى كنعان', 'email' => 'huda@ahjiyat.test', 'days_ago' => 30, 'verified' => true, 'frozen' => true, 'frozen_reason' => 'طلب المستخدم تجميد الحساب مؤقتاً.'],

            // ===== حسابات جديدة/غير موثّقة - لاختبار رسائل عدم الأهلية =====
            ['name' => 'معاذ حجازي', 'email' => 'muath@ahjiyat.test', 'days_ago' => 0, 'verified' => false],
            ['name' => 'رزان بدوي', 'email' => 'razan@ahjiyat.test', 'days_ago' => 1, 'verified' => false],
            ['name' => 'كنان صالح', 'email' => 'kenan@ahjiyat.test', 'days_ago' => 1, 'verified' => true],

            // ===== حسابات عادية متنوعة =====
            ['name' => 'خالد النجار', 'email' => 'khaled@ahjiyat.test', 'days_ago' => 20, 'verified' => true],
            ['name' => 'لمى العبيدي', 'email' => 'lama@ahjiyat.test', 'days_ago' => 25, 'verified' => true],
            ['name' => 'عمر الفارسي', 'email' => 'omar@ahjiyat.test', 'days_ago' => 15, 'verified' => true],
            ['name' => 'نور الدين حداد', 'email' => 'noureddine@ahjiyat.test', 'days_ago' => 35, 'verified' => true],
            ['name' => 'ليان سلطان', 'email' => 'layan@ahjiyat.test', 'days_ago' => 10, 'verified' => true],
            ['name' => 'فراس منصور', 'email' => 'firas@ahjiyat.test', 'days_ago' => 50, 'verified' => true],
            ['name' => 'جنى العتيبي', 'email' => 'jana@ahjiyat.test', 'days_ago' => 8, 'verified' => true],
            ['name' => 'ياسر برهوم', 'email' => 'yaser@ahjiyat.test', 'days_ago' => 18, 'verified' => true],
            ['name' => 'ملاك صافي', 'email' => 'malak@ahjiyat.test', 'days_ago' => 22, 'verified' => true],
            ['name' => 'وسام الحلبي', 'email' => 'wisam@ahjiyat.test', 'days_ago' => 5, 'verified' => true],
            ['name' => 'رغد التميمي', 'email' => 'raghad@ahjiyat.test', 'days_ago' => 33, 'verified' => true],
            ['name' => 'باسل قندس', 'email' => 'basel@ahjiyat.test', 'days_ago' => 12, 'verified' => true],
            ['name' => 'شذى مراد', 'email' => 'shatha@ahjiyat.test', 'days_ago' => 28, 'verified' => true],
            ['name' => 'أنس الرفاعي', 'email' => 'anas@ahjiyat.test', 'days_ago' => 45, 'verified' => true],
            ['name' => 'دانة الشريف', 'email' => 'dana@ahjiyat.test', 'days_ago' => 7, 'verified' => true],
            ['name' => 'إيمان غانم', 'email' => 'eman@ahjiyat.test', 'days_ago' => 55, 'verified' => true],
            ['name' => 'عدنان زعبي', 'email' => 'adnan@ahjiyat.test', 'days_ago' => 14, 'verified' => true],
        ];

        foreach ($users as $u) {
            $user = User::where('email', $u['email'])->first();

            if (! $user) {
                $user = new User();

                // forceFill لازم هون بالضبط متل AdminUserSeeder: role, email_verified_at,
                // is_frozen, frozen_reason, created_at, updated_at كلهم غير موجودين بـ
                // User::$fillable، فلو استخدمنا create()/firstOrCreate() العاديين كانوا
                // ينحذفوا بصمت. النتيجة لو صار هيك: كل المستخدمين التجريبيين يطلعوا
                // "غير موثّقين" وحسابهم "منشأ اليوم" بغض النظر عمّا كتبناه، وحسابات
                // التجميد ما تنجمّد فعلياً - يعني كل سيناريوهات الاختبار المقصودة تفشل بصمت.
                $user->forceFill([
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'password' => Hash::make(self::TEST_PASSWORD),
                    'role' => 'user',
                    'email_verified_at' => $u['verified'] ? now() : null,
                    'is_frozen' => $u['frozen'] ?? false,
                    'frozen_reason' => $u['frozen_reason'] ?? null,
                    'created_at' => now()->subDays($u['days_ago']),
                    'updated_at' => now()->subDays($u['days_ago']),
                ]);
                $user->save();
            }

            Wallet::firstOrCreate(['user_id' => $user->id]);
        }

        $this->command->info('تم إنشاء '.count($users).' مستخدماً تجريبياً. كلمة مرور الجميع: '.self::TEST_PASSWORD);
        $this->command->info('حسابات جاهزة للاستبدال فوراً: yousef@ahjiyat.test / sara@ahjiyat.test / reem@ahjiyat.test');
        $this->command->info('حسابات مجمّدة (لاختبار رسالة الحظر): tarek@ahjiyat.test / huda@ahjiyat.test');
    }
}