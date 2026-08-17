<?php

namespace Database\Seeders;

use App\Models\FraudFlag;
use App\Models\User;
use Illuminate\Database\Seeder;

class FraudFlagSeeder extends Seeder
{
    public function run(): void
    {
        if (FraudFlag::count() > 0) {
            $this->command->warn('توجد علامات احتيال مسبقاً - تخطّي.');

            return;
        }

        // أنس عنده رصيد كافٍ وحساب مؤهل بكل الشروط الأخرى، لكن هذا العلم
        // غير المحلول يمنعه من الاستبدال - مثالي لاختبار رسالة الحجب بالواجهة.
        if ($anas = User::where('email', 'anas@ahjiyat.test')->first()) {
            FraudFlag::create([
                'user_id' => $anas->id,
                'reason' => 'abnormal_earn_rate',
                'severity' => 'high',
                'details' => 'معدل حل غير طبيعي رُصد تلقائياً: عدد كبير من الحلول الصحيحة خلال فترة قصيرة جداً.',
                'resolved' => false,
            ]);
        }

        // علم محلول على مستخدم عادي - لاختبار فلتر "محلول" بلوحة الإدارة.
        if ($basel = User::where('email', 'basel@ahjiyat.test')->first()) {
            FraudFlag::create([
                'user_id' => $basel->id,
                'reason' => 'ip_shared',
                'severity' => 'low',
                'details' => 'نفس عنوان الـ IP استُخدم من عدة حسابات - تمت المراجعة ولم يثبت أي مخالفة.',
                'resolved' => true,
            ]);
        }

        // علم إضافي غير محلول على مستخدم آخر لتنويع لوحة الإدارة.
        if ($wisam = User::where('email', 'wisam@ahjiyat.test')->first()) {
            FraudFlag::create([
                'user_id' => $wisam->id,
                'reason' => 'multiple_accounts',
                'severity' => 'medium',
                'details' => 'اشتباه بوجود أكثر من حساب لنفس الشخص من نفس بصمة الجهاز.',
                'resolved' => false,
            ]);
        }

        $this->command->info('تم إنشاء 3 علامات احتيال (محلولة وغير محلولة) لاختبار لوحة الإدارة وحجب الاستبدال.');
    }
}