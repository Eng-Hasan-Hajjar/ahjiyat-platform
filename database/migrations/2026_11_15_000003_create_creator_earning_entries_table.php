<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E39 (3/3) - دفتر أرباح المبدعين (creator_earning_entries): إلحاقي فقط، لا تعديل ولا حذف ولا تصحيح بالكتابة فوق صف.
|
|  كل صف يحمل ثلاث حركات موقَّعة: available_delta / reserved_delta / paid_delta. القاعدة (يفرضها النموذج بنوع الحركة):
|     revenue_share        available +x                     (تخصيص فترة مُقفلة)
|     adjustment_credit    available +x                     (تعديل إداري موثَّق)
|     adjustment_debit     available -x                     (لا يتجاوز المتاح ولا يمس المحجوز/المدفوع)
|     payout_reserve       available -x, reserved +x        (طلب دفعة)
|     payout_release       reserved -x, available +x        (رفض/إلغاء)
|     payout_settle        reserved -x, paid +x             (تسجيل الصرف)
|  source_key فريد = idempotency: التكرار لا يدرج صفًا ثانيًا (revenue:{period}:{creator} | payout:{id}:reserve|release|settle | adjustment:{ulid}).
|  الأعمدة signed عمدًا (حركات)، أما الأرصدة في creator_earning_accounts فغير سالبة.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_earning_entries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_earning_entries_public_unique');
            $table->foreignId('creator_earning_account_id')->constrained('creator_earning_accounts')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('type', 24);
            $table->bigInteger('available_delta_minor')->default(0);
            $table->bigInteger('reserved_delta_minor')->default(0);
            $table->bigInteger('paid_delta_minor')->default(0);
            $table->char('currency', 3);

            $table->string('source_key', 120)->unique('creator_earning_entries_source_unique');
            $table->foreignId('creator_revenue_period_id')->nullable()->constrained('creator_revenue_periods')->restrictOnDelete();
            $table->foreignId('creator_revenue_allocation_id')->nullable()->constrained('creator_revenue_allocations')->restrictOnDelete();
            $table->foreignId('creator_payout_id')->nullable()->constrained('creator_payouts')->restrictOnDelete();
            $table->string('reason_code', 32)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['creator_earning_account_id', 'id'], 'creator_earning_entries_account_id_index');
            $table->index(['type', 'created_at'], 'creator_earning_entries_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_earning_entries');
    }
};
