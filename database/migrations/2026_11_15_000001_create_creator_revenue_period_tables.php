<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E39 (1/3) - فترات الإيراد (creator_revenue_periods) + تخصيصات المبدعين (creator_revenue_allocations).
|
|  creator_revenue_periods
|     فترة محاسبية [starts_at, ends_at) بمجمّع صريح يحدده المدير (pool_minor، عدد صحيح بالوحدة الصغرى وبعملة محاسبة واحدة).
|     الحالات: draft → review → finalized | cancelled. بعد finalized لا يتغير شيء (النموذج يرفض). policy_snapshot يلتقط قواعد الحساب لحظة الإقفال.
|     عدم تداخل الفترات غير الملغاة تفرضه الخدمة تحت قفل ثابت الترتيب (لا يوجد قيد نطاقات بقاعدة البيانات).
|  creator_revenue_allocations
|     نتيجة التوزيع لكل مبدع في فترة مُقفلة: وحدات مؤهَّلة وحصّة صحيحة. unique (period, creator) = لا تخصيص مكرَّر تحت أي سباق. إلحاقي فقط (لا تعديل ولا حذف).
|     **كل المفاتيح الأجنبية RESTRICT**: التاريخ المالي لا يُمحى تتابعيًا (حذف مستخدم له حساب أرباح يمنعه FinancialHistoryGuard).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_revenue_periods', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_revenue_periods_public_unique');
            $table->string('label', 120);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->char('currency', 3);
            $table->unsignedBigInteger('pool_minor');
            $table->string('status', 16)->default('draft');

            // نتائج الإقفال (أصفار حتى finalized) + لقطة السياسة.
            $table->unsignedBigInteger('total_units')->default(0);
            $table->unsignedInteger('eligible_creators')->default(0);
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->unsignedBigInteger('unallocated_minor')->default(0);
            $table->json('policy_snapshot')->nullable();
            $table->json('review_summary')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'creator_revenue_periods_status_start_index');
            $table->index(['starts_at', 'ends_at'], 'creator_revenue_periods_range_index');
        });

        Schema::create('creator_revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('creator_revenue_allocations_public_unique');
            $table->foreignId('creator_revenue_period_id')->constrained('creator_revenue_periods')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unsignedInteger('raw_units');
            $table->unsignedInteger('qualified_units');
            $table->unsignedInteger('unique_players');
            $table->unsignedBigInteger('period_total_units');
            $table->unsignedBigInteger('allocated_minor');
            $table->unsignedTinyInteger('remainder_bonus_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['creator_revenue_period_id', 'creator_profile_id'], 'creator_revenue_alloc_period_creator_unique');
            $table->index(['creator_profile_id', 'creator_revenue_period_id'], 'creator_revenue_alloc_creator_period_index');
            $table->index('user_id', 'creator_revenue_alloc_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_revenue_allocations');
        Schema::dropIfExists('creator_revenue_periods');
    }
};
