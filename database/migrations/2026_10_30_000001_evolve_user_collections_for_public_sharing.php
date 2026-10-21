<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E35 - تطوير مجموعات E29 (UserCollection) لتصير private|unlisted|public دون محرك ثانٍ ودون المساس بهجرة E29.
|
|  user_collections:
|    visibility            private (افتراضي وكل الصفوف القائمة) | unlisted | public
|    first_published_at    أول مرة صارت فيها غير خاصة (لا تُكتب فوقها)
|    saves_count           عدّاد مجمَّع لحفظ الآخرين (يكتبه EngagementCounters وحده + يصالحه community:reconcile-engagement)
|    is_moderation_hidden  إخفاء إشرافي: لا يملك المالك رفعه. moderation_reason نص عادي من المشرف يراه المالك فقط.
|  user_collection_items:
|    position              ترتيب يحدده المالك. التعادل يُحسم دائمًا بـid فيبقى الترتيب حتميًا حتى لو تكررت القيمة.
|                          (فهرس عادي لا فريد عمدًا: إعادة الترتيب تُنفَّذ تحت قفل صف المجموعة بتحديث دفعي، ولا تحتاج مرحلتين لتفادي تعارض قيد فريد أثناء التبديل.)
|
| الصفوف القائمة: كلها خاصة، والمواضع تُملأ حتميًا من (created_at, id) داخل كل مجموعة ← يبقى ما يراه المالك كما هو منطقيًا.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_collections', function (Blueprint $table) {
            $table->string('visibility', 16)->default('private')->after('description');
            $table->timestamp('first_published_at')->nullable()->after('visibility');
            $table->unsignedInteger('saves_count')->default(0)->after('first_published_at');
            $table->boolean('is_moderation_hidden')->default(false)->after('saves_count');
            $table->string('moderation_reason', 255)->nullable()->after('is_moderation_hidden');
            $table->timestamp('moderation_hidden_at')->nullable()->after('moderation_reason');
            $table->unsignedBigInteger('moderation_hidden_by')->nullable()->after('moderation_hidden_at');

            // الأسطح العامة (بحث/اكتشاف/ملف): visibility ثم الإخفاء الإشرافي ثم الحداثة أو الحفظ.
            $table->index(['visibility', 'is_moderation_hidden', 'updated_at'], 'user_collections_public_recent_index');
            $table->index(['visibility', 'is_moderation_hidden', 'saves_count'], 'user_collections_public_saves_index');
            $table->index(['user_id', 'visibility'], 'user_collections_owner_visibility_index');
        });

        Schema::table('user_collection_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('creator_puzzle_id');
            $table->index(['user_collection_id', 'position'], 'collection_items_order_index');
        });

        $this->backfillPositions();
        $this->addVisibilityCheck();
    }

    public function down(): void
    {
        Schema::table('user_collection_items', function (Blueprint $table) {
            $table->dropIndex('collection_items_order_index');
            $table->dropColumn('position');
        });

        $this->dropVisibilityCheck();

        Schema::table('user_collections', function (Blueprint $table) {
            $table->dropIndex('user_collections_owner_visibility_index');
            $table->dropIndex('user_collections_public_saves_index');
            $table->dropIndex('user_collections_public_recent_index');
            $table->dropColumn(['visibility', 'first_published_at', 'saves_count', 'is_moderation_hidden', 'moderation_reason', 'moderation_hidden_at', 'moderation_hidden_by']);
        });
    }

    /** مواضع حتمية 1..n لكل مجموعة بحسب (created_at, id)؛ تُنفَّذ مجموعةً مجموعة (السقف لكل مجموعة صغير) فلا تحميل كامل للجدول. آمنة للتكرار. */
    protected function backfillPositions(): void
    {
        DB::table('user_collections')->select('id')->orderBy('id')->chunkById(200, function ($collections) {
            foreach ($collections as $collection) {
                $position = 0;

                foreach (DB::table('user_collection_items')->where('user_collection_id', $collection->id)->orderBy('created_at')->orderBy('id')->pluck('id') as $itemId) {
                    DB::table('user_collection_items')->where('id', $itemId)->update(['position' => ++$position]);
                }
            }
        });
    }

    /** قيد قيم الظهور بمستوى القاعدة (دفاع إضافي؛ الخدمة تتحقق أولًا). SQLite لا يدعم ADD CONSTRAINT وMySQL < 8.0.16 يتجاهل CHECK: فشل الإضافة لا يكسر الترحيل. */
    protected function addVisibilityCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement("ALTER TABLE user_collections ADD CONSTRAINT user_collections_visibility_check CHECK (visibility IN ('private','unlisted','public'))");
        } catch (Throwable) {
            // الحارس الفعلي هو UserCollection::VISIBILITIES عبر الخدمة.
        }
    }

    protected function dropVisibilityCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        try {
            DB::statement('ALTER TABLE user_collections DROP CONSTRAINT user_collections_visibility_check');
        } catch (Throwable) {
            // لم يكن موجودًا.
        }
    }
};
