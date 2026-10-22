<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E37 - لقطة السمعة المجتمعية (مشتقّة، غير سلطة) + المبدعون المختارون (إبراز تحريري).
|
|  community_reputation_snapshots
|     صف واحد لكل مستخدم (UNIQUE user_id بقيد قاعدة بيانات؛ الإدراج/التحديث بـUPSERT ذرّي فالحساب المتزامن لا يكرّر). مشتقّة بالكامل من سجلات السلطة
|     (تقييمات/مفضّلات/متابعات/حفظ مجموعات/إعجابات تعليقات/أعمال ظاهرة)، فيجوز حذفها وإعادة بنائها دون أثر على أي بيانات أخرى (reputation:recalculate).
|     الدرجات أعداد صحيحة 0..10000 (لا فاصلة عائمة مخزَّنة). الأعمدة المؤثرة بالترتيب أعمدة عادية مفهرسة؛ لا JSON.
|     أعلام الأهلية (creator_/rising_/curator_/helpful_eligible) لحظة الحساب فقط: القراءة العلنية تعيد فحص الأهلية حيًّا (معتمَد ∧ غير مجمَّد ∧ عمل ظاهر ∧ ملف عام...).
|     user_id يُحذف تتابعيًا مع حساب المستخدم (اللقطة مشتقّة). لا أثر على أي دفتر اقتصادي: لا عمود هنا يغذّي جواهر/XP/مهام/منافسات.
|  community_featured_creators
|     إبراز تحريري (مبدع مختار): صف واحد لكل ملف مبدع، بنافذة اختيارية. ليس سمعة ولا يحسب من اللقطة. الحذف يتتابع مع ملف المبدع؛ featured_by يصير NULL بحذف المدير.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_reputation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // أبعاد الدرجات (0..10000)
            $table->unsignedSmallInteger('creator_score')->default(0);
            $table->unsignedSmallInteger('creator_rating_score')->default(0);
            $table->unsignedSmallInteger('creator_engagement_score')->default(0);
            $table->unsignedSmallInteger('creator_consistency_score')->default(0);
            $table->unsignedSmallInteger('creator_reach_score')->default(0);
            $table->unsignedSmallInteger('rising_score')->default(0);
            $table->unsignedSmallInteger('curator_score')->default(0);
            $table->unsignedSmallInteger('helpfulness_score')->default(0);

            // أهلية لحظة الحساب (تُعاد فحصها حيًّا بالقراءة العلنية)
            $table->boolean('creator_eligible')->default(false);
            $table->boolean('rising_eligible')->default(false);
            $table->boolean('curator_eligible')->default(false);
            $table->boolean('helpful_eligible')->default(false);

            // مصادر المبدع (من السجلات الأصلية، دون تقييم/مفضلة/متابعة ذاتية)
            $table->unsignedInteger('works_count')->default(0);
            $table->unsignedInteger('rated_works_count')->default(0);
            $table->unsignedInteger('series_count')->default(0);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('ratings_sum')->default(0);
            $table->unsignedInteger('raters_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);
            $table->unsignedInteger('players_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();

            // النافذة الحديثة (الصاعدون)
            $table->unsignedInteger('recent_ratings_count')->default(0);
            $table->unsignedInteger('recent_ratings_sum')->default(0);
            $table->unsignedInteger('recent_favorites_count')->default(0);
            $table->unsignedInteger('recent_players_count')->default(0);
            $table->unsignedInteger('recent_followers_count')->default(0);

            // المنسّق
            $table->unsignedInteger('curator_collections_count')->default(0);
            $table->unsignedInteger('curator_saves_count')->default(0);
            $table->unsignedInteger('curator_savers_count')->default(0);
            $table->unsignedInteger('curator_items_count')->default(0);
            $table->unsignedInteger('curator_fresh_count')->default(0);

            // المساهم المفيد
            $table->unsignedInteger('helpful_comments_count')->default(0);
            $table->unsignedInteger('helpful_liked_comments')->default(0);
            $table->unsignedInteger('helpful_likers_count')->default(0);
            $table->unsignedInteger('helpful_likes_count')->default(0);

            $table->unsignedSmallInteger('formula_version')->default(1);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique('user_id', 'reputation_snapshots_user_unique');
            $table->index(['creator_eligible', 'creator_score', 'ratings_count'], 'reputation_creator_rank_index');
            $table->index(['rising_eligible', 'rising_score', 'recent_ratings_count'], 'reputation_rising_rank_index');
            $table->index(['curator_eligible', 'curator_score', 'curator_savers_count'], 'reputation_curator_rank_index');
            $table->index(['helpful_eligible', 'helpfulness_score', 'helpful_likers_count'], 'reputation_helpful_rank_index');
            $table->index('calculated_at', 'reputation_calculated_index');
        });

        Schema::create('community_featured_creators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_profile_id')->unique()->constrained('creator_profiles')->cascadeOnDelete();
            $table->timestamp('featured_from')->nullable();
            $table->timestamp('featured_until')->nullable();
            $table->foreignId('featured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 160)->nullable();
            $table->timestamps();

            $table->index(['featured_from', 'featured_until'], 'featured_creators_window_index');
        });

        $this->addScoreChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('community_featured_creators');
        Schema::dropIfExists('community_reputation_snapshots');
    }

    /** سقف الدرجات بمستوى القاعدة (دفاع إضافي؛ الخدمة تقصّ أولًا). SQLite لا يدعم ADD CONSTRAINT؛ فشل الإضافة لا يكسر الترحيل. */
    protected function addScoreChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement('ALTER TABLE community_reputation_snapshots ADD CONSTRAINT reputation_scores_range_check CHECK ('
                .'creator_score <= 10000 AND creator_rating_score <= 10000 AND creator_engagement_score <= 10000 AND creator_consistency_score <= 10000 '
                .'AND creator_reach_score <= 10000 AND rising_score <= 10000 AND curator_score <= 10000 AND helpfulness_score <= 10000)');
        } catch (Throwable) {
            // الحارس الفعلي هو ReputationMath::toScale عبر الخدمة.
        }
    }
};
