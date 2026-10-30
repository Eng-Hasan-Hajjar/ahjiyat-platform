<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E44 (1/3) - دوري الكتّاب الموسمي: الإعدادات الموسمية + قائمة الكتّاب + السلاسل المربوطة + ميزانية النجوم + توزيع النجوم + قرارات الجوائز.
|
|  season_recognition_settings   صف واحد لكل موسم (يُنشأ كسولًا): إعدادات الدوري (السعة/نافذة التصويت/نجوم الناخب/عدد الإقصاء/خطة الجوائز) وإعدادات الداعمين (مفتاح الموسم/علامة التجميد).
|                               لا علاقة له بـSeason نفسه (لا أعمدة جديدة على seasons) ولا بخدمات E42؛ مفتاح عام لكل نظام + مفتاح لكل موسم.
|  season_writer_entries         عضوية كاتب في قائمة موسم = ملف مبدع معتمد (لا هوية جديدة). user_id لقطة ثابتة من مالك الملف (لحارس التاريخ ولاختبار «الكاتب يصوّت لغيره»).
|                               مجاميع النجوم/المصوّتين مخزَّنة (stars_total/voters_count) وتُحدَّث ذريًا داخل معاملة التصويت، ودفتر التوزيع هو السلطة (reconcile يعيد البناء).
|                               لقطة النتيجة النهائية (final_*, outcome, finalized_at) يكتبها المُنهي وحده. outcome مجموعة مغلقة: first_place|second_place|third_place|participant|ineligible_next_season.
|  season_writer_series          ربط صريح: سلسلة المبدع ⇒ عضوية الموسم. هذا وحده ما يُحتسب أهليةً للتصويت (لا نستنتج من كل ما نشره المبدع يومًا).
|  season_writer_vote_budgets    ميزانية الناخب الموسمية الكسولة: **حساب واحد = ميزانية واحدة** لكل موسم مهما تعدّدت صفاته (إداري/كاتب/لاعب).
|  season_writer_star_allocations توزيع النجوم (ناخب ⇒ كاتب) ⇒ صف واحد لكل زوج، والصفر يحذف الصف. هذا هو دفتر السلطة؛ لا يُظهَر علنًا أبدًا.
|  season_writer_awards          قرار جائزة لكاتب بعد الإنهاء: تجميلية (تُمنح بخدمة الاستحقاقات القائمة) أو مالية (التزام قابل للتدقيق فقط؛ لا دفع آلي ولا تحويل عملات ولا مساس برصيد المبدع).
|
| الحذف: التاريخ لا يُمحى (RESTRICT) إلا مستخدم الناخب فيُصفَّر (SET NULL) كي يبقى مجموع النجوم صحيحًا عند محو حساب (حق المحو) دون أن يتغيّر الترتيب.
| الأعمدة النقدية بالوحدة الصغرى فقط (لا float/decimal). أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا). لا فهارس بادئة مكرِّرة (حارس Q-4).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_recognition_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            // الموسم السابق المباشر (اختياري): يحدّد من تُطبَّق عليهم قاعدة «المستبعدون من الموسم التالي». NULL = يُشتقّ من التسلسل الزمني للمواسم.
            $table->foreignId('previous_season_id')->nullable()->constrained('seasons')->nullOnDelete();

            // دوري الكتّاب
            $table->boolean('writers_enabled')->default(false);
            $table->unsignedSmallInteger('writers_capacity')->default(20);
            $table->timestamp('writers_voting_opens_at')->nullable();
            $table->timestamp('writers_voting_closes_at')->nullable();
            $table->unsignedTinyInteger('writers_stars_per_voter')->default(10);
            $table->unsignedTinyInteger('writers_eliminated_count')->default(5);
            $table->json('writers_reward_plan')->nullable();
            $table->timestamp('writers_finalized_at')->nullable();
            $table->foreignId('writers_finalized_by')->nullable()->constrained('users')->nullOnDelete();

            // تقدير الداعمين
            $table->boolean('supporters_enabled')->default(false);
            $table->timestamp('supporters_finalized_at')->nullable();
            $table->foreignId('supporters_finalized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('season_id', 'season_recognition_settings_season_unique');
        });

        Schema::create('season_writer_entries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('season_writer_entries_public_unique');
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained('creator_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 12)->default('active'); // active | withdrawn
            $table->timestamp('joined_at');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();

            // المجاميع الحيّة (مشتقّة من دفتر التوزيع ومعاملة التصويت وحدها تكتبها).
            $table->unsignedInteger('stars_total')->default(0);
            $table->unsignedInteger('voters_count')->default(0);
            $table->timestamp('first_star_at')->nullable();
            $table->timestamp('last_star_at')->nullable();

            // لقطة النتيجة النهائية (يكتبها المُنهي فقط ولا تُعدَّل بعده).
            $table->unsignedSmallInteger('final_rank')->nullable();
            $table->unsignedInteger('final_stars')->nullable();
            $table->unsignedInteger('final_voters_count')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'creator_profile_id'], 'season_writer_entries_season_creator_unique');
            $table->unique(['user_id', 'season_id'], 'season_writer_entries_user_season_unique');
        });

        Schema::create('season_writer_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writer_entry_id')->constrained('season_writer_entries')->restrictOnDelete();
            $table->foreignId('creator_series_id')->constrained('creator_series')->restrictOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(['writer_entry_id', 'creator_series_id'], 'season_writer_series_pair_unique');
        });

        Schema::create('season_writer_vote_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // لقطة حجم الميزانية عند أول تصويت (تغيير الإعداد لاحقًا لا يغيّر ميزانيات قائمة).
            $table->unsignedTinyInteger('total_stars');
            $table->unsignedTinyInteger('spent_stars')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'user_id'], 'season_writer_budgets_voter_unique');
        });

        Schema::create('season_writer_star_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('writer_entry_id')->constrained('season_writer_entries')->restrictOnDelete();
            $table->foreignId('voter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->timestamps();

            // الزوج (كاتب، ناخب) فريد؛ الكاتب يحدّد الموسم فيتضمّن القيد الفرادة الموسمية (season, writer, voter) المطلوبة بقيد أشدّ.
            $table->unique(['writer_entry_id', 'voter_user_id'], 'season_writer_alloc_pair_unique');
            $table->index(['season_id', 'voter_user_id'], 'season_writer_alloc_voter_index');
        });

        Schema::create('season_writer_awards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('season_writer_awards_public_unique');
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('writer_entry_id')->constrained('season_writer_entries')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unsignedTinyInteger('plan_index');       // موضع البند في خطة الجوائز (فرادة ⇒ الإنهاء متكرّر الأمان)
            $table->string('placement', 16);                 // first_place | second_place | third_place
            $table->string('kind', 12);                      // cosmetic | monetary
            $table->string('label', 120);                    // لقطة اسم الجائزة

            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->nullOnDelete(); // التجميلية فقط
            $table->unsignedBigInteger('amount_minor')->nullable();                                      // المالية فقط (وحدة صغرى)
            $table->char('currency', 3)->nullable();

            $table->string('status', 16)->default('planned'); // planned | granted | pending_finance | settled | cancelled | failed
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('settlement_method', 16)->nullable(); // manual
            $table->string('settlement_ref', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['writer_entry_id', 'plan_index'], 'season_writer_awards_entry_plan_unique');
            $table->index(['season_id', 'status'], 'season_writer_awards_season_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_writer_awards');
        Schema::dropIfExists('season_writer_star_allocations');
        Schema::dropIfExists('season_writer_vote_budgets');
        Schema::dropIfExists('season_writer_series');
        Schema::dropIfExists('season_writer_entries');
        Schema::dropIfExists('season_recognition_settings');
    }
};
