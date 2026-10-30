<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E44 (2/3) - تقدير الداعمين الموسمي: باقات بطاقات الداعم + بطاقات الدعم المشتراة + دفتر نقاط الدعم + المجاميع الموسمية.
|
|  نقاط الدعم (Support Points): تقدير فقط - ليست مالًا ولا جواهر ولا XP ولا نقاط تمريرة ولا درجة ولا أرباح مبدع، ولا تُصرف ولا تُحوَّل.
|
|  season_supporter_packages   باقات البطاقة (silver|gold|diamond) لكل موسم: السعر (وحدة صغرى) والعملة ونقاط الدعم ونمط الاسم من قائمة بيضاء. الخادم هو سلطة السعر.
|                              كل باقة مرتبطة ببرنامج رعاية E30 للموسم (sponsorship_program_id): الدفع الحقيقي يمرّ كاملًا عبر SponsorshipPayment (E32) → تحقق → SponsorshipContribution.
|  season_supporter_cards      بطاقة مشتراة = طلب دفع E32 واحد (فريد) + لقطة ثابتة للسعر/النقاط/النمط وقت الطلب. pending → active (بتحقق الدفع) | void (رفض/إلغاء/انتهاء) | refunded.
|                              مستوى البطاقة الحالي = أعلى باقة active؛ تغيير إعداد الباقة لاحقًا لا يمسّ اللقطة.
|  season_support_point_entries دفتر نقاط الدعم (إلحاق فقط): source_key فريد ⇒ منح واحد لكل مصدر؛ الاسترداد قيد سالب مرتبط بالأصل بفرادة (reverses_entry_id) ⇒ عكس واحد بالضبط.
|                              is_public لقطة خصوصية وقت المنح (مصدر مجهول E30 لا يظهر في المجاميع العامة).
|  season_supporter_stats      مجاميع موسمية كسولة (لا صفوف مسبقة): النقاط الكلية والعلنية، أول/آخر نقطة، مستوى البطاقة الحالي (مشتق ومخزَّن للقراءة السريعة)،
|                              طابور التنويه الاجتماعي (إداري فقط؛ لا ربط بأي شبكة)، ولقطة الترتيب النهائي (يكتبها المُنهي وحده).
|
| الحذف: لا حذف تتابعي للتاريخ (RESTRICT) - المستخدم صاحب بطاقة/نقاط لديه أصلًا مشتريات متجر أو طلب دفع رعاية يمنع حذفه (FinancialHistoryGuard).
| لا float/decimal. أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا). لا فهارس بادئة مكرِّرة (حارس Q-4).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_supporter_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();

            $table->string('tier_key', 12);                 // silver | gold | diamond
            $table->string('name', 60);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('support_points');
            $table->string('name_style_key', 12);           // قائمة بيضاء: silver|gold|diamond
            $table->boolean('social_mention_eligible')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'tier_key'], 'season_supporter_packages_tier_unique');
        });

        Schema::create('season_supporter_cards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('season_supporter_cards_public_unique');
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('season_supporter_packages')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sponsorship_payment_id')->constrained('sponsorship_payments')->restrictOnDelete();

            // لقطة ثابتة وقت الطلب (تغيير الباقة لاحقًا لا يعيد كتابة التاريخ).
            $table->string('tier_key', 12);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('support_points');
            $table->string('name_style_key', 12);
            $table->boolean('social_mention_eligible')->default(false);
            $table->boolean('is_anonymous')->default(false);

            $table->string('status', 12)->default('pending'); // pending | active | void | refunded
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique('sponsorship_payment_id', 'season_supporter_cards_payment_unique');
            $table->index(['user_id', 'season_id', 'status'], 'season_supporter_cards_user_season_index');
            $table->index(['season_id', 'status'], 'season_supporter_cards_season_status_index');
        });

        Schema::create('season_support_point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('source_type', 16);              // store_purchase | supporter_card
            $table->unsignedBigInteger('source_id');
            $table->string('source_key', 80);
            $table->string('reason', 12);                   // grant | reversal
            $table->bigInteger('points_delta');             // موقَّع عمدًا: العكس سالب
            $table->boolean('is_public')->default(true);
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('reverses_entry_id', 'season_support_entries_reverses_foreign')->references('id')->on('season_support_point_entries')->restrictOnDelete();

            $table->unique('source_key', 'season_support_entries_source_unique');
            $table->unique('reverses_entry_id', 'season_support_entries_reverses_unique');
            $table->index(['season_id', 'user_id'], 'season_support_entries_season_user_index');
        });

        Schema::create('season_supporter_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // موقَّعة عمدًا ليظهر أي هبوط تحت الصفر في المطابقة بدل أن يُخفيه قيد النوع.
            $table->bigInteger('points_total')->default(0);
            $table->bigInteger('public_points_total')->default(0);
            $table->timestamp('first_point_at')->nullable();
            $table->timestamp('last_point_at')->nullable();

            $table->string('card_tier_key', 12)->nullable();   // مخزَّن للقراءة السريعة؛ سلطته البطاقات (المطابقة تتحقق)

            $table->string('social_status', 12)->nullable();   // eligible | queued | published | skipped
            $table->string('social_note', 300)->nullable();
            $table->foreignId('social_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('social_updated_at')->nullable();

            $table->unsignedInteger('final_rank')->nullable();
            $table->bigInteger('final_points')->nullable();
            $table->string('final_card_tier_key', 12)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'user_id'], 'season_supporter_stats_user_unique');
            $table->index(['season_id', 'public_points_total', 'first_point_at'], 'season_supporter_stats_rank_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_supporter_stats');
        Schema::dropIfExists('season_support_point_entries');
        Schema::dropIfExists('season_supporter_cards');
        Schema::dropIfExists('season_supporter_packages');
    }
};
