<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E29 - التفاعل المجتمعي. ترحيل واحد متماسك: خمسة جداول + أعمدة عدّادات مشتقّة. لا تعديل لأي ترحيل سابق.
 *
 *  community_puzzle_ratings    تقييم 1–5 واحد لكل (مستخدم، عمل مبدع). المصدر الوحيد للحقيقة؛ العدّادات مشتقّة وقابلة لإعادة البناء.
 *  community_puzzle_favorites  «مفضلة» واحدة (لا إعجاب منفصل) لكل (مستخدم، عمل). خاصة؛ العدد المجمَّع فقط علني.
 *  creator_follows             متابعة واحدة لكل (مستخدم، مبدع). هوية المتابعين لا تُعرض لأحد.
 *  user_collections            مجموعات شخصية **خاصة فقط** (ULID عام يُحلّ باستعلام محصور بالمالك).
 *  user_collection_items       عضوية (مجموعة، عمل مبدع) فريدة. تشير للعمل الثابت لا لنسخة الأحجية القانونية.
 *
 * سياسة المفاتيح الأجنبية (مقصودة، تفصيلها في docs/community-engagement-e29.md):
 *  - كل الصفوف تشير إلى **العمل الثابت** creator_puzzles (لا puzzles القانونية): النسخة الجديدة المعتمدة لا تمحو التقييمات ولا المفضلة.
 *  - حذف العمل/المبدع ← تتتابع تفاعلاته (cascade): لا صفوف يتيمة لمحتوى لم يعد موجودًا.
 *  - حذف المستخدم: المفضلة والمتابعات والمجموعات = بيانات شخصية خاصة ← cascade (وتُنقص العدّادات خدميًا قبل الحذف)،
 *    أما **التقييم** فيبقى مجهولًا (user_id = NULL، nullOnDelete): مساهمة في المتوسط العام لا تحمل هوية، فيبقى المجمَّع دقيقًا دون إعادة حساب.
 *    NULL مسموح بتكراره بالقيد الفريد، فلا تعارض.
 *
 * العدّادات (ratings_count, ratings_sum, favorites_count, followers_count) تُكتب بتحديث ذرّي عبر خدمات التفاعل فقط (لا تعيين جماعي ولا أحداث نماذج)،
 * وأمر community:reconcile-engagement يكشف الانحراف ويصلحه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_puzzle_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'creator_puzzle_id'], 'community_ratings_user_work_unique');
            $table->index(['creator_puzzle_id', 'rating'], 'community_ratings_work_rating_index');
        });

        $this->addRatingRangeCheck();

        Schema::create('community_puzzle_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'creator_puzzle_id'], 'community_favorites_user_work_unique');
            $table->index(['user_id', 'id'], 'community_favorites_recent_index');   // صفحة «مفضلتي»: الأحدث حفظًا أولًا
            $table->index('creator_puzzle_id', 'community_favorites_work_index');
        });

        Schema::create('creator_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'creator_profile_id'], 'creator_follows_user_creator_unique');
            $table->index(['creator_profile_id', 'id'], 'creator_follows_fanout_index');   // إشعار النشر بدفعات بمؤشر id لكل مبدع
        });

        Schema::create('user_collections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('description', 400)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at'], 'user_collections_owner_index');
        });

        Schema::create('user_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_collection_id', 'creator_puzzle_id'], 'collection_items_collection_work_unique');
            $table->index('creator_puzzle_id', 'collection_items_work_index');
        });

        Schema::table('creator_puzzles', function (Blueprint $table) {
            $table->unsignedInteger('ratings_count')->default(0)->after('plays_count');
            $table->unsignedInteger('ratings_sum')->default(0)->after('ratings_count');
            $table->unsignedInteger('favorites_count')->default(0)->after('ratings_sum');

            // مرشّحو «الأعلى تقييمًا»: عدد تقييمات ≥ الحدّ الأدنى ∧ ظاهر (مدى على ratings_count أولًا؛ مسح محدود مرة لكل TTL بالكاش).
            // العمود الرئيسي ratings_count لا is_visible عمدًا: فهرس يبدأ بـis_visible كان سيزاحم creator_puzzles_visible_title_index (E28) على استعلامات البحث بالعنوان.
            $table->index(['ratings_count', 'is_visible'], 'creator_puzzles_rated_visible_index');
        });

        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0)->after('approved_at');
            $table->index(['status', 'followers_count'], 'creator_profiles_followed_index');   // «الأكثر متابعة» بالملخص الإداري
        });
    }

    public function down(): void
    {
        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->dropIndex('creator_profiles_followed_index');
            $table->dropColumn('followers_count');
        });

        Schema::table('creator_puzzles', function (Blueprint $table) {
            $table->dropIndex('creator_puzzles_rated_visible_index');
            $table->dropColumn(['ratings_count', 'ratings_sum', 'favorites_count']);
        });

        Schema::dropIfExists('user_collection_items');
        Schema::dropIfExists('user_collections');
        Schema::dropIfExists('creator_follows');
        Schema::dropIfExists('community_puzzle_favorites');
        Schema::dropIfExists('community_puzzle_ratings');
    }

    /**
     * قيد 1..5 بمستوى القاعدة (دفاع إضافي؛ الخدمة تتحقق أولًا دائمًا). SQLite لا يدعم ADD CONSTRAINT، وMySQL < 8.0.16 يتجاهل CHECK بصمت:
     * في الحالتين يبقى التحقق الخدمي هو الحارس. فشل هذه الإضافة لا يكسر الترحيل.
     */
    protected function addRatingRangeCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement('ALTER TABLE community_puzzle_ratings ADD CONSTRAINT community_ratings_range_check CHECK (rating BETWEEN 1 AND 5)');
        } catch (\Throwable) {
            // محرك لا يدعم CHECK: التحقق الخدمي كافٍ.
        }
    }
};
