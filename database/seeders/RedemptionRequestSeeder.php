<?php

namespace Database\Seeders;

use App\Models\RedemptionRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class RedemptionRequestSeeder extends Seeder
{
    /**
     * يبني طلبات استبدال بكل الحالات الخمس على حسابات العرض التوضيحي
     * (اللي عندها رصيد كافٍ من PuzzleAttemptSeeder). هذا السيدر يتعامل
     * مباشرة مع الجدول والمحفظة بدل خدمة RedemptionService، لأنه يمثّل
     * "طلبات تاريخية" جاهزة للعرض فوراً - وليس تدفق مستخدم حي.
     */
    public function run(): void
    {
        if (RedemptionRequest::count() > 0) {
            $this->command->warn('طلبات الاستبدال موجودة مسبقاً - تخطّي.');

            return;
        }

        $yousef = User::where('email', 'yousef@ahjiyat.test')->first();
        $sara = User::where('email', 'sara@ahjiyat.test')->first();
        $reem = User::where('email', 'reem@ahjiyat.test')->first();
        $anas = User::where('email', 'anas@ahjiyat.test')->first();

        $admin = User::where('role', 'admin')->first();

        if (! $yousef || ! $sara || ! $reem || ! $anas || ! $admin) {
            $this->command->warn('حسابات العرض التوضيحي غير مكتملة - شغّل UserSeeder وAdminUserSeeder وPuzzleAttemptSeeder أولاً.');

            return;
        }

        // 1) يوسف: طلب قيد المراجعة
        $this->createRequest($yousef, 10000, 'قسيمة شحن رصيد جوال بقيمة 10$', RedemptionRequest::STATUS_PENDING);

        // 2) سارة: طلب مقبول لكن لسا ما تنفّذ
        $this->createRequest($sara, 10000, 'سماعات لاسلكية', RedemptionRequest::STATUS_APPROVED, $admin, 'تمت الموافقة، جاري التجهيز للشحن.');

        // 3) ريم: طلب اتنفّذ بالكامل
        $this->createRequest($reem, 10000, 'قسيمة أمازون 10$', RedemptionRequest::STATUS_FULFILLED, $admin, 'تم إرسال كود القسيمة على البريد الإلكتروني.', fulfilled: true);

        // 4) ريم: طلب ثانٍ اترفض (والجواهر رجعت لرصيدها تلقائياً)
        $this->createRequest($reem, 5000, 'قميص مطبوع بشعار المنصة', RedemptionRequest::STATUS_REJECTED, $admin, 'المنتج غير متوفر بالمخزون حالياً، حاول بمكافأة أخرى.', refunded: true);

        // 5) أنس: طلب قديم اتلغى (قبل ما يظهر عليه علم الاحتيال بFraudFlagSeeder)
        $this->createRequest($anas, 8000, 'قسيمة شحن رصيد جوال', RedemptionRequest::STATUS_CANCELLED, refunded: true);

        $this->command->info('تم إنشاء 5 طلبات استبدال بكل الحالات الخمس الممكنة.');
    }

    protected function createRequest(
        User $user,
        int $amount,
        string $description,
        string $status,
        ?User $admin = null,
        ?string $note = null,
        bool $fulfilled = false,
        bool $refunded = false,
    ): void {
        $request = RedemptionRequest::create([
            'user_id' => $user->id,
            'gems_amount' => $amount,
            'reward_description' => $description,
            'status' => $status,
            'admin_note' => $note,
            'reviewed_by' => $admin?->id,
            'reviewed_at' => $admin ? now()->subDays(fake()->numberBetween(1, 5)) : null,
        ]);

        // الجواهر تُحجز (تُخصم من المتاح) فور تقديم أي طلب - نطابق سلوك RedemptionService الحقيقي.
        $user->wallet->decrement('available_balance', $amount);

        if ($fulfilled) {
            $user->wallet->increment('lifetime_redeemed', $amount);
        }

        if ($refunded) {
            $user->wallet->increment('available_balance', $amount);
        }
    }
}