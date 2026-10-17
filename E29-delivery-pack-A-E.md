# E29 — DELIVERY PACK (A–E)

NO GIT COMMANDS. أساس المقارنة: remote `main` = v28 (`f053f84c41a5246f0ec285341eb5562f2fd58f66`). هذا الملف خارج المستودع عمدًا (لا يُنسخ إليه). لا حزم جديدة (0 Composer / 0 npm)، `composer.lock` و`composer.json` و`package*.json` لم تُمسّ، وأصول الواجهة (CSS/JS) لم تتغيّر (Blade فقط) فلا `npm run build`.

الملفات: **53 جديدًا** + **20 معدَّلًا** (كاملة). التحقق بعد النسخ: `sha256sum <path>` مقابل `manifest-sha256.txt`.

## الفهرس

- A) NEW FILES (محتوى كامل)
- B) MODIFIED FILES (ملفات كاملة)
- C) MIGRATIONS (جديد واحد)
- D) ENV LINES
- E) LOCAL COMMANDS

---

## A) NEW FILES — المسار + المحتوى الكامل (53 ملفًا)

### `config/engagement.php`

`sha256: 92a23b85f630765abc70a14a620098767144d90e5b900f9bb03e7bbc8c425a6e`

`````php
<?php

/*
| E29 - التفاعل المجتمعي: التقييمات، المفضلة، متابعة المبدعين، خلاصة المتابَعين، المجموعات الشخصية، والأعلى تقييمًا.
|
| قاعدة مغلقة: التفاعل **بلا سلطة اقتصادية**. لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات ولا ترتيب مدفوع بأي إعداد هنا.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. التفاصيل: docs/community-engagement-e29.md
*/
return [

    'ratings' => [
        'min' => 1,
        'max' => 5,

        // أقل عدد تقييمات لإظهار «المتوسط» علنًا. تحته يُعرض العدد فقط (عيّنة صغيرة لا تُسوَّق كـ«5.0 ★»).
        'display_min' => (int) env('ENGAGEMENT_RATING_DISPLAY_MIN', 3),
    ],

    /*
    | الأعلى تقييمًا: درجة انكماش بايزية بسيطة وقابلة للشرح، لا «المتوسط الخام» أبدًا:
    |
    |   score = (ratings_sum + prior_mean * prior_weight) / (ratings_count + prior_weight)
    |
    | prior_mean   = متوسط افتراضي «محايد» نسحب نحوه الأعمال قليلة التقييم.
    | prior_weight = كم «تقييمًا وهميًا» بمتوسط prior_mean نضيفه لكل عمل. كلما كبر صار الوصول للقمة يتطلب تقييمات أكثر.
    | min_ratings  = لا يدخل الترتيب عملٌ بأقل من هذا العدد مهما كان متوسطه.
    | مثال: خمسة تقييمات 5 → 4.25، بينما 500 تقييم بمتوسط 4.8 → 4.79: الكثرة الموثوقة تتفوق على العيّنة الصغيرة.
    */
    'top_rated' => [
        'min_ratings' => (int) env('ENGAGEMENT_TOP_MIN_RATINGS', 5),
        'prior_mean' => (float) env('ENGAGEMENT_TOP_PRIOR_MEAN', 3.5),
        'prior_weight' => (float) env('ENGAGEMENT_TOP_PRIOR_WEIGHT', 5),
        'pool' => 40,                                                   // أقصى عدد معرّفات تُخزَّن بالكاش
        'cache_ttl' => (int) env('ENGAGEMENT_TOP_CACHE_TTL', 300),      // معرّفات + درجات فقط؛ الظهور يُفحص حيًّا بكل طلب
    ],

    // ملخّص جودة المبدع بصفحته العامة: لا يظهر المتوسط إلا ببلوغ هذا العدد الإجمالي من التقييمات على أعماله الظاهرة.
    'creator_quality' => [
        'min_ratings' => (int) env('ENGAGEMENT_CREATOR_MIN_RATINGS', 5),
    ],

    'follows' => [
        // سقف لما يتابعه المستخدم الواحد: يحدّ استعلام الخلاصة ويمنع الإغراق. تعديله لا يمسّ البيانات القائمة.
        'max_per_user' => (int) env('ENGAGEMENT_MAX_FOLLOWS', 500),
    ],

    'collections' => [
        'max_per_user' => (int) env('ENGAGEMENT_MAX_COLLECTIONS', 20),
        'max_items' => (int) env('ENGAGEMENT_MAX_COLLECTION_ITEMS', 100),
        'title_min' => 2,
        'title_max' => 60,
        'description_max' => 300,
    ],

    'pagination' => [
        'favorites' => 12,
        'following' => 12,
        'collections' => 12,
        'collection_items' => 12,
    ],

    /*
    | إشعار «نشر جديد» لمتابعي المبدع. فئة social الموجودة (تفضيل social_enabled) بلا نظام تفضيلات ثانٍ.
    | on_revision: هل تُشعَر المتابعون أيضًا باعتماد نسخة جديدة لعمل منشور سابقًا (لا فقط بأول نشر).
    | الإرسال دفعات بمؤشر id عبر الطابور (حجم الدفعة من player_notifications.chunk_size)؛ لا حلقة واحدة على كل المتابعين.
    */
    'notifications' => [
        'on_revision' => (bool) env('ENGAGEMENT_NOTIFY_ON_REVISION', true),
    ],

    // تكامل الاكتشاف (E27/E28). الإشارات ضعيفة ولا تحلّ محل الاستكشاف: مجمَّع القراءة نفسه، وسقف أعمال المبدع نفسه.
    'discovery' => [
        'following_limit' => 4,
        'top_rated_limit' => 8,
        'affinity_min_rating' => 4,     // تقييم المشاهد ≥ هذا يعدّ إشارة تفضيل لتصنيف العمل
    ],
];
`````

### `database/migrations/2026_10_21_000001_create_community_engagement_tables.php`

`sha256: 2f0f23fc11687c779fc5f882f8290af4de851a3d8b03b075f29ae1dd97c6af50`

`````php
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
`````

### `app/Models/CommunityPuzzleRating.php`

`sha256: afa8163834afa236e8d285f14651e1f403444652bcc697b6b876655c76883e26`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - تقييم نجوم 1–5 لعمل مبدع (هوية ثابتة، لا نسخة قانونية). مصدر الحقيقة؛ العدّادات على العمل مشتقّة منه.
 * user_id يصير NULL إن حُذف المستخدم (يبقى التقييم مجهولًا فيبقى المتوسط دقيقًا). تكتبه CommunityRatingService وحدها: لا تعيين جماعي.
 */
class CommunityPuzzleRating extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}
`````

### `app/Models/CommunityPuzzleFavorite.php`

`sha256: de73d157cb104f10193947aec866c94226b1a83c00918244ff083da1b6b2246d`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - «مفضلة» (مفهوم واحد فقط؛ لا إعجاب منفصل). خاصة بصاحبها: لا تُعرض قائمة أحد لأحد؛ العدد المجمَّع فقط علني.
 * تكتبه CommunityFavoriteService وحدها (مع العدّاد بمعاملة واحدة).
 */
class CommunityPuzzleFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}
`````

### `app/Models/CreatorFollow.php`

`sha256: e277ddf1b98a9e3130d31f8edca3f32ace23fb004032a293947d9831f1c87e43`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - متابعة مستخدم لمبدع. قائمة ما يتابعه المستخدم خاصة، وهوية المتابعين لا تُعرض لأحد (حتى للمبدع): العدد المجمَّع فقط.
 * تكتبه CreatorFollowService وحدها (مع followers_count بمعاملة واحدة).
 */
class CreatorFollow extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }
}
`````

### `app/Models/UserCollection.php`

`sha256: f4c4a7cf17f7c810ef35dd2f03544bb55195fe767d4069e7019813b10ade7d62`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E29 - مجموعة شخصية **خاصة فقط** (لا مشاركة عامة بهذه المرحلة). معرّفها العام ULID، ويُحلّ دائمًا باستعلام محصور بالمالك (غير المالك = 404).
 * العنوان والوصف نص عادي (وسوم HTML مرفوضة عند الإدخال وتُهرَّب عند العرض). تكتبها CollectionService وحدها.
 */
class UserCollection extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (UserCollection $collection) {
            $collection->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserCollectionItem::class);
    }
}
`````

### `app/Models/UserCollectionItem.php`

`sha256: 4c5feaa17133237afafd52f7119f276bca056b43cd7aba54af05a2d9d3c4a2f2`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E29 - عضوية عمل مبدع (الهوية الثابتة لا النسخة القانونية) في مجموعة شخصية. فريدة لكل (مجموعة، عمل). تكتبها CollectionService وحدها. */
class UserCollectionItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(UserCollection::class, 'user_collection_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}
`````

### `app/Jobs/DispatchCreatorPublicationChunk.php`

`sha256: 24679d1d3b9d4a92cb77079c11cb4cc7d1910fc3a10025b359580f8aea4199f4`

`````php
<?php

namespace App\Jobs;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E29 - دفعة واحدة من إشعارات «نشر جديد» لمتابعي مبدع، ثم الدفعة التالية بمؤشر id لصف المتابعة (لا Job ضخمة ولا تحميل لكل المتابعين).
 * حجم الدفعة = player_notifications.chunk_size (نفس إعداد بقية الإرسال الجماعي E15/E20/E21): 100 متابع = دفعة، 10 آلاف = 50، مليون = 5000 دفعة متسلسلة.
 *
 *  - إعادة تشغيل أي دفعة آمنة: المفتاح creator-publication:{revision}:{user} + القيد الفريد بالإشعارات (تكرار = Duplicate لا إشعار ثانٍ).
 *  - **يُعاد فحص الظهور لحظة التنفيذ** (W6): نشرٌ أُخفي أو عُلِّق مبدعه أو حلّت محلّه نسخة أحدث قبل أن يعمل العامل لا يُرسَل عنه شيء.
 *  - الجمهور: متابعون موثَّقون وغير مجمَّدين. التفضيل (فئة social) والمفتاح الشامل يُفحصان لحظة الإرسال داخل NotificationDispatcher.
 *  - لا XP ولا عملة ولا تقدّم ولا مكافأة. فشل أي إشعار يُلتقَط داخل NotificationDispatcher ولا يُفشل الدفعة.
 */
class DispatchCreatorPublicationChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $revisionId, public bool $isUpdate = false, public int $afterFollowId = 0) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $revision = CreatorPuzzleRevision::query()->find($this->revisionId, ['id', 'creator_puzzle_id', 'status']);

        if ($revision === null || $revision->status !== CreatorPuzzleRevision::STATUS_APPROVED) {
            return;
        }

        $work = CreatorPuzzle::query()->visible()
            ->where('id', $revision->creator_puzzle_id)->where('published_revision_id', $revision->id)
            ->first(['id', 'public_id', 'creator_profile_id', 'published_title']);

        if ($work === null) {
            return;
        }

        $creatorUserId = (int) CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
        $creatorName = (string) User::query()->whereKey($creatorUserId)->value('name');
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $follows = CreatorFollow::query()
            ->where('creator_profile_id', $work->creator_profile_id)->where('id', '>', $this->afterFollowId)
            ->orderBy('id')->limit($size)->get(['id', 'user_id']);

        if ($follows->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $follows->pluck('user_id'))->where('is_frozen', false)->whereNotNull('email_verified_at')->get()->keyBy('id');

        foreach ($follows as $follow) {
            $user = $users->get($follow->user_id);

            if ($user === null || (int) $user->id === $creatorUserId) {
                continue;
            }

            $dispatcher->dispatch(
                $user,
                NotificationType::CreatorNewPublication,
                ['creator' => $creatorName, 'title' => (string) $work->published_title, 'updated' => $this->isUpdate],
                "creator-publication:{$revision->id}:{$user->id}",
                ['creator_puzzle_id' => $work->id],
                ['work' => $work->public_id],
            );
        }

        if ($follows->count() === $size) {
            self::dispatch($this->revisionId, $this->isUpdate, (int) $follows->last()->id);
        }
    }
}
`````

### `app/Console/Commands/ReconcileCommunityEngagement.php`

`sha256: e27aea97929a3efc1a4e78e49a66152a9b3ca97d38720b65168c13a4e2ca79e9`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Engagement\EngagementReconciler;
use Illuminate\Console\Command;

class ReconcileCommunityEngagement extends Command
{
    protected $signature = 'community:reconcile-engagement {--dry-run : كشف الانحراف دون تعديل} {--chunk=500 : حجم دفعة القراءة}';

    protected $description = 'E29: يقارن عدّادات التفاعل (ratings_count/ratings_sum/favorites_count/followers_count) بالصفوف الحقيقية ويصلح الانحراف. الصفوف هي المصدر الوحيد للحقيقة؛ آمن للتكرار.';

    public function handle(EngagementReconciler $reconciler): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $reconciler->run($dry, max(50, min(5000, (int) $this->option('chunk'))));

        $this->line('أعمال مفحوصة: '.$result['works'].' — مبدعون مفحوصون: '.$result['creators']);
        $driftCount = count($result['drift']);
        $this->line('انحراف مكتشف: '.$driftCount);

        foreach (array_slice($result['drift'], 0, 20) as $d) {
            $this->line(sprintf('  %s#%d %s: مخزَّن=%d فعلي=%d', $d['table'], $d['id'], $d['column'], $d['stored'], $d['actual']));
        }

        if ($dry) {
            $this->info($driftCount === 0 ? 'العدّادات مطابقة للصفوف (dry-run: لم يُعدَّل شيء).' : 'dry-run: لم يُعدَّل شيء. شغّل الأمر بدون --dry-run للإصلاح.');

            return $driftCount === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('أُصلح '.$result['fixed'].' عدّادًا.');

        return self::SUCCESS;
    }
}
`````

### `database/seeders/DemoEngagementSeeder.php`

`sha256: a587aaff4829072cbe665fc18bda0e9a7d16c5f196552f320a4c7cc8b34eea02`

`````php
<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E29 - بيانات Demo للتفاعل المجتمعي.   php artisan db:seed --class=DemoEngagementSeeder
 *
 * - اختيارية وترفض الإنتاج. **تعتمد على DemoCreatorSeeder** (تستدعيه إن لم يوجد مبدعون) ولا تُستدعى من DemoQaSeeder: أرقام Demo المثبَّتة بالاختبارات لا تتغير.
 * - **بالخدمات الرسمية فقط** (لعب مجتمع، تقييم، مفضلة، متابعة، مجموعات): لا كتابة مباشرة بالجداول ولا بالعدّادات، فتبقى العدّادات مطابقة للصفوف.
 *   لا محافظ ولا XP ولا محاولات رسمية (puzzle_attempts) ولا أي أثر على الصدارة/المهام: التفاعل بلا سلطة اقتصادية بالبناء.
 * - **Idempotent**: تُعاد دون تضاعف (كل خدمة تتجاهل المكرَّر؛ المجموعات بالعنوان لكل مستخدم).
 * - حسابات تجريبية بكلمة المرور المعروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoEngagementSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->refuseInProduction('DemoEngagementSeeder');

        if (! CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        $works = CreatorPuzzle::query()->visible()->orderBy('id')->limit(6)->get();

        if ($works->isEmpty()) {
            $this->command?->warn('DemoEngagementSeeder: لا أعمال مجتمع منشورة للتفاعل معها.');

            return;
        }

        $creators = CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->orderBy('id')->get();

        $players = collect(['a' => 'متفاعل أ', 'b' => 'متفاعل ب', 'c' => 'متفاعل ج', 'd' => 'متفاعل د', 'e' => 'متفاعل هـ', 'f' => 'متفاعل و'])
            ->map(fn (string $name, string $key) => $this->user("demo-engager-{$key}@engagement.test", $name))->values();

        $play = app(CommunityPlayService::class);
        $ratings = app(CommunityRatingService::class);
        $favorites = app(CommunityFavoriteService::class);
        $follows = app(CreatorFollowService::class);
        $collections = app(CollectionService::class);

        foreach ($players as $i => $player) {
            foreach ($works as $j => $work) {
                $work = $work->fresh();

                if (! $work->is_visible || (int) $work->creator->user_id === $player->id) {
                    continue;
                }

                // محاولة واحدة على الأقل (شرط أهلية التقييم). أنواع لا تقبل إجابة نصية خام قد ترمي استثناءً: لا يهم للعرض.
                try {
                    $play->attempt($player, $work, '__غير صحيحة__', []);
                } catch (\Throwable) {
                }

                // أنماط تقييم متفاوتة ومتكررة حتميًا (بلا عشوائية) فيتضح الفرق بين المتوسط الخام والدرجة المنكمشة.
                $value = [5, 4, 5, 3, 4, 5][($i + $j) % 6];

                try {
                    $ratings->set($player, $work, $j === 0 ? 5 : $value);
                } catch (\Throwable) {
                }
            }

            foreach ($works->take(2 + ($i % 2)) as $work) {
                try {
                    $favorites->add($player, $work->fresh());
                } catch (\Throwable) {
                }
            }

            foreach ($creators as $creator) {
                try {
                    $follows->follow($player, $creator);
                } catch (\Throwable) {
                }
            }
        }

        // 2–3 مجموعات خاصة لأول مستخدمين (بالعنوان: idempotent).
        foreach ([[$players[0], 'ألغاز المساء', $works->take(3)], [$players[0], 'للتحدي مع الأصدقاء', $works->slice(3, 2)], [$players[1], 'مفضلات هادئة', $works->take(2)]] as [$owner, $title, $items]) {
            $collection = \App\Models\UserCollection::query()->where('user_id', $owner->id)->where('title', $title)->first() ?? $collections->create($owner, $title, 'مجموعة تجريبية خاصة بصاحبها.');

            foreach ($items as $work) {
                try {
                    $collections->add($owner, $collection->public_id, $work->fresh());
                } catch (\Throwable) {
                }
            }
        }

        $this->command?->info('DemoEngagementSeeder: لاعبون ('.$players->count().')، تقييمات، مفضلة، متابعات، مجموعات. كلمة المرور: '.self::PASSWORD);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
`````

### `routes/engagement.php`

`sha256: d384f1fd614264bacfe6b72223d91b867be304a0f211ab3c8dff6980692b8a78`

`````php
<?php

use App\Http\Controllers\Engagement\CollectionController;
use App\Http\Controllers\Engagement\CommunityFavoriteController;
use App\Http\Controllers\Engagement\CommunityRatingController;
use App\Http\Controllers\Engagement\CreatorFollowController;
use Illuminate\Support\Facades\Route;

/*
| E29 - التفاعل المجتمعي (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
| كلها خلف auth + verified + account.active (المجمَّد لا يتفاعل: يُرد بخطأ من account.active ثم تحرسه الخدمة مرة ثانية).
| كل تعديل POST/PUT/DELETE فقط - لا GET يغيّر حالة. كل مُعدِّل بمحدّد معدّل مسمّى بمفتاح المستخدم (انظر AppServiceProvider).
| لا مسار عام لأي قائمة (مفضلة/متابَعون/مجموعات): خاصة بصاحبها، تُقرأ بـuser_id الجلسة.
| الصفحات الحرفية (create) تسبق الديناميكية ({collection}).
*/

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::put('/community/puzzles/{work}/rating', [CommunityRatingController::class, 'update'])->whereUlid('work')->middleware('throttle:community-rating')->name('community.puzzles.rating.update');
    Route::delete('/community/puzzles/{work}/rating', [CommunityRatingController::class, 'destroy'])->whereUlid('work')->middleware('throttle:community-rating')->name('community.puzzles.rating.destroy');

    Route::post('/community/puzzles/{work}/favorite', [CommunityFavoriteController::class, 'store'])->whereUlid('work')->middleware('throttle:community-favorite')->name('community.puzzles.favorite.store');
    Route::delete('/community/puzzles/{work}/favorite', [CommunityFavoriteController::class, 'destroy'])->whereUlid('work')->middleware('throttle:community-favorite')->name('community.puzzles.favorite.destroy');
    Route::get('/favorites', [CommunityFavoriteController::class, 'index'])->name('favorites.index');

    Route::post('/creators/{creator:slug}/follow', [CreatorFollowController::class, 'store'])->middleware('throttle:creator-follow')->name('creators.follow');
    Route::delete('/creators/{creator:slug}/follow', [CreatorFollowController::class, 'destroy'])->middleware('throttle:creator-follow')->name('creators.unfollow');
    Route::get('/following', [CreatorFollowController::class, 'feed'])->name('following.index');

    Route::post('/community/puzzles/{work}/collections', [CollectionController::class, 'addWork'])->whereUlid('work')->middleware('throttle:collection-actions')->name('community.puzzles.collections.add');

    Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::get('/collections/create', [CollectionController::class, 'create'])->name('collections.create');
    Route::post('/collections', [CollectionController::class, 'store'])->middleware('throttle:collection-actions')->name('collections.store');
    Route::prefix('/collections/{collection}')->whereUlid('collection')->group(function () {
        Route::get('/', [CollectionController::class, 'show'])->name('collections.show');
        Route::get('/edit', [CollectionController::class, 'edit'])->name('collections.edit');
        Route::put('/', [CollectionController::class, 'update'])->middleware('throttle:collection-actions')->name('collections.update');
        Route::delete('/', [CollectionController::class, 'destroy'])->middleware('throttle:collection-actions')->name('collections.destroy');
        Route::delete('/items/{work}', [CollectionController::class, 'removeWork'])->whereUlid('work')->middleware('throttle:collection-actions')->name('collections.items.destroy');
    });
});
`````

### `docs/community-engagement-e29.md`

`sha256: 54d7026c3805db5c3495c44f6ddd211bcbf331698a4297413f16703a60bacbf7`

`````markdown
# E29 — التفاعل المجتمعي: التقييمات، المفضلة، متابعة المبدعين، المجموعات الشخصية والأعلى تقييمًا

يبني E29 فوق E28 (المبدعون وأحجيات المجتمع) وE27 (البحث والاكتشاف) دون محرّك ثانٍ: **كل ما هنا يمتد إلى خدمات وجداول E28/E27/E20-21 القائمة** (`CreatorPuzzle`, `CreatorProfile`, `CreatorVisibilityService`, `CommunityPlayService`, `NotificationDispatcher`, `CachedSnapshot`, `CommunityDiscovery`, `CreatorAnalyticsService`).

> **القاعدة الأولى: التفاعل بلا سلطة اقتصادية.** لا جواهر، لا XP، لا مهام، لا سلسلة، لا إنجازات، لا تأهيل حملات، لا منافسات، ولا أي لوحة صدارة رسمية — لا من تقييم ولا من مفضلة ولا من متابعة ولا من «الأعلى تقييمًا». لا يوجد استدعاء لأي خدمة اقتصاد/تقدّم في `app/Services/Engagement` (يثبّته اختبار عزل مقارِن للقطات المحفظة/XP/المهام قبل وبعد كل تفاعل).

الحزمة: ترحيل واحد جديد، **0 حزم Composer/npm**، 4 محدِّدات معدّل، أمر صيانة واحد، وظيفة طابور واحدة.

---

## 1. سياسة التقييمات (Ratings)

| البند | القرار |
|---|---|
| الهدف | العمل الثابت `CreatorPuzzle` (لا نسخة `Puzzle` القانونية): اعتماد نسخة جديدة **لا يصفّر** التقييمات ولا يُنشئ سجلًّا ثانيًا. |
| القيم | عدد صحيح 1–5 (تُتحقَّق بالخادم في الطلب وفي الخدمة، وقيد `CHECK` على MySQL/PostgreSQL). |
| الفريدية | تقييم واحد لكل (مستخدم، عمل): `UNIQUE (user_id, creator_puzzle_id)` في القاعدة. التعديل يغيّر الصف نفسه، والحذف يزيله. |
| الواجهة | `PUT /community/puzzles/{work}/rating` للإنشاء/التعديل (idempotent)، `DELETE` للحذف. |
| عمل مخفي/مؤرشف/مبدع معلَّق | لا تقييم جديد ولا تعديل (404/رسالة «غير متاحة حاليًا»)؛ **التقييمات القديمة تبقى محفوظة ولا تُعرض** لأن المحتوى نفسه لا يُعرض؛ تعود بالاستعادة. الحذف الذاتي مسموح دائمًا لحساب غير مجمَّد. |
| النسخ | اعتماد نسخة جديدة يغيّر `published_puzzle_id` ولا يمسّ `ratings_*` ولا الصفوف. |
| العرض | المتوسط لا يظهر علنًا قبل `ENGAGEMENT_RATING_DISPLAY_MIN` (افتراضي 3) تقييمات؛ تحته يُعرض العدد فقط. لا «5.0 ★» من تقييم واحد. |

## 2. شروط الأهلية للتقييم

يستطيع المستخدم التقييم إذا اجتمع:

1. حساب **موثَّق البريد** و**غير مجمَّد** (يُقرأ من القاعدة لحظة الفعل، لا من نسخة جلسة قديمة).
2. **ليس صاحب العمل** (لا تقييم ذاتي).
3. له **محاولة واحدة على الأقل** في `community_puzzle_attempts` على هذا العمل (لا يُشترط الحل: الأحجية الصعبة تُقيَّم أيضًا؛ ولا يُمكن تقييم ما لم يُجرَّب).
4. العمل **ظاهر** (`is_visible = true`) لحظة الفعل، وتُفحص تحت قفل صف العمل داخل المعاملة.

لا قيمة اقتصادية للمحاولة هنا: المحاولة شرط أهلية فقط.

## 3. خصوصية المفضلة (Favorites)

- مفهوم واحد فقط («المفضلة»؛ لا «إعجاب» ثانٍ يخلط المعنى). قائمة **خاصة** بصاحبها: `/favorites` تُقرأ بـ`user_id` الجلسة، ولا مسار عام لأي قائمة مفضلة.
- يظهر **عدّاد عام مجمَّع** فقط (`favorites_count`) — بلا هويات ولا قائمة أسماء.
- `POST` / `DELETE /community/puzzles/{work}/favorite` — idempotent (تكرار الإضافة/الحذف لا يغيّر العدّاد). قيد فريد `(user_id, creator_puzzle_id)`.
- لا مفضلة على عملك. عمل غير ظاهر: لا إضافة جديدة؛ الحذف الذاتي مسموح.
- في `/favorites`، العمل الذي أُخفي لاحقًا يظهر **بديلًا محايدًا** («هذا المحتوى غير متاح حاليًا») دون عنوان ولا مبدع ولا رابط، مع إمكان إزالته؛ ويعود كاملًا عند الاستعادة.

## 4. خصوصية المتابعة (Following)

- متابعة مبدع **معتمَد** فقط، فريدة `(user_id, creator_profile_id)`، لا متابعة ذاتية.
- مبدع غير معتمَد (معلَّق/مرفوض/قيد المراجعة) أو حسابه مجمَّد: الصفحة العامة 404، فلا متابعة ولا إلغاء متابعة عبرها؛ المجمَّد لا يتفاعل بتاتًا (متابعة ولا إلغاء).
- **هويات المتابعين لا تظهر لأحد** — لا للعموم ولا لصاحب الملف ولا في لوحة الإدارة (لا شاشة Filament ولا Blade تقرأ جدول المتابعات): يُعرض `followers_count` فقط. لا مسار «قائمة المتابعين».
- سقف لما يتابعه المستخدم: `ENGAGEMENT_MAX_FOLLOWS` (افتراضي 500) يحدّ استعلام الخلاصة ويمنع الإغراق.
- `POST` / `DELETE /creators/{slug}/follow` — idempotent، محدَّدا المعدّل `creator-follow`.

## 5. خلاصة المتابَعين (`/following`)

- تعرض آخر أعمال المبدعين الذين يتابعهم المستخدم، **الظاهرة حاليًا فقط**، مرتبة بالأحدث نشرًا.
- ترقيم بمؤشر (`cursorPaginate`، 12 لكل صفحة) — لا offset يتدهور مع الحجم.
- الاستعلام: `whereIn(creator_profile_id, subquery follows)` + شروط الظهور (`is_visible`) **تُفحص حيًّا** عند كل عرض، فلا يظهر محتوى أُخفي بين التحميل والنقر.
- حالتان فارغتان مميَّزتان: «لا تتابع أحدًا بعد» (مع توجيه لاكتشاف مبدعين)، و«لا نشر جديد من مبدعيك».
- عدد الاستعلامات ثابت لا يتبع عدد البطاقات (12 مقابل 2 عنصر = نفس العدد؛ انظر القسم 15).

## 6. الإشعارات و fan-out (`creator_new_publication`)

- نوع جديد `NotificationType::CreatorNewPublication` ضمن **فئة `social` القائمة**: يحترم `social_enabled` نفسه، **دون نظام تفضيلات ثانٍ**. لا إشعار عن التقييم أو المفضلة أو اللعب أو المتابعة نفسها.
- المحفّز: اعتماد نسخة (`CreatorReviewService::approve`) ← `CreatorPublicationAnnouncer` ← `DispatchCreatorPublicationChunk::dispatch(...)->afterCommit()`.
  - **لا يُرسل شيء قبل نجاح المعاملة**، وعند فشل المعاملة لا يخرج إشعار.
  - فشل الإعلان (طابور/إشعارات) يُلتقط ويُبلَّغ `report()` ولا يُرجع النشر: **النشر لا يتراجع أبدًا بسبب الإشعارات**.
  - `ENGAGEMENT_NOTIFY_ON_REVISION` (افتراضي true): هل تُشعَر المتابعون أيضًا باعتماد نسخة جديدة لعمل منشور سابقًا (النص يختلف: «تحديث» مقابل «جديد»).
- التوزيع **دفعات** بمؤشر `id` عبر الطابور: كل دفعة `player_notifications.chunk_size` (افتراضي 200) تُحمِّل المتابعين وتعيد فحص أهلية كل منهم (حساب غير مجمَّد وموثَّق) وظهور العمل **قبل الإرسال** ثم تُرسل دفعة تالية بالمؤشر. ثلاث محاولات (`BoundedRetries`).
- **Idempotent**: مفتاح التفرّد `creator-publication:{revisionId}:{userId}` عبر `NotificationDispatcher`؛ إعادة تشغيل الدفعة/الوظيفة لا تُكرّر إشعارًا.
- لا يُرسل لصاحب العمل، ولا لمتابع جُمِّد أو لم يوثّق بريده.

### الحجم: 100 / 10 آلاف / مليون متابع

| المتابعون | الدفعات (chunk=200) | السلوك |
|---|---|---|
| 100 | 1 | دفعة واحدة بالطابور؛ زمن ثانية. |
| 10,000 | 50 | سلسلة 50 وظيفة قصيرة متتابعة؛ لا تحمّل أكثر من 200 صف بالذاكرة؛ تمرّ على عمّال الطابور العاديين. |
| 1,000,000 | 5,000 | تعمل لكن **التكلفة تتركّز في كتابة الإشعارات** (مليون صف + بث). المسار آمن (مؤشر id، لا offset، لا حلقة واحدة، idempotent، قابل للاستئناف)، لكن الكتابة تحتاج عمال طابور مخصَّصين وتصغير/تأجيل البث. هذا حدّ معروف مذكور بالقيود لا خلل: قبل بلوغ هذا الحجم يُراجَع (أولوية الطابور، تجميع الإشعارات، أو تحويل الخلاصة لـ pull فقط). |

## 7. خصوصية المجموعات الشخصية (Collections)

- **خاصة فقط**: لا مشاركة عامة ولا فهرسة ولا بلاغات في هذه المرحلة (قرار مقصود، انظر القسم 17).
- معرّف عام ULID (`public_id`)؛ كل قراءة/كتابة تحلّ المجموعة بـ`public_id` **مع `user_id` المالك** — مجموعة غيرك = 404 (لا 403 يكشف الوجود): لا IDOR.
- سقفان قابلان للضبط: `ENGAGEMENT_MAX_COLLECTIONS` (20) و`ENGAGEMENT_MAX_COLLECTION_ITEMS` (100)، يُفحصان **تحت قفل** صف المستخدم/المجموعة فلا يُتجاوزان بالتزامن.
- العنوان 2–60 والوصف حتى 300، نص عادي (`NoMarkup`) تُطبَّع مسافاته؛ المصدر الوحيد للقواعد `CollectionService::rules()`.
- العضوية تشير للعمل الثابت: إخفاء العمل لا يحذفها؛ يظهر **بديلًا محايدًا** («هذا المحتوى غير متاح حاليًا») ويعود بالاستعادة. لا يُضاف عمل غير ظاهر.
- حذف المجموعة يحذف عضوياتها فقط (لا الأحجيات). «إنشاء وإضافة» معاملة واحدة (لا مجموعة فارغة تُترك عند الفشل).
- المنتقي في صفحة العمل استعلام واحد (`withExists`) بلا استعلام لكل مجموعة.

## 8. صيغة «الأعلى تقييمًا» (Top Rated)

انكماش بايزي بسيط قابل للشرح:

```
score = (ratings_sum + prior_mean × prior_weight) / (ratings_count + prior_weight)
```

الافتراضات: `prior_mean = 3.5`، `prior_weight = 5`، **حدّ أدنى `min_ratings = 5`** قبل الأهلية للترتيب. أمثلة: خمسة تقييمات بـ5 → **4.25**؛ 500 تقييم بمتوسط 4.8 → **4.79**؛ تقييم واحد بـ5 → خارج الترتيب أصلًا. لا `ORDER BY AVG` أبدًا.

- الترتيب: الدرجة تنازليًا ثم عدد التقييمات ثم الأحدث (`id`) — حتمي.
- **الكاش يحمل معرّفات + درجات فقط** (`discover.community.top_rated`، TTL 300ث، حوض 40): لا أسماء ولا عناوين ولا هويات.
- **الظهور يُفحص حيًّا** عند كل استهلاك عبر `CommunityDiscovery` (يجلب الأعمال الظاهرة بالمعرّفات من القاعدة): عمل أُخفي يختفي فورًا ولو بقي في الكاش. أسوأ تأخير = TTL لتغيّر **الترتيب** فقط، لا لظهور محتوى مخفي.
- استعلام الحساب بربط معاملات (لا دمج نصي) ومفهرس بـ`creator_puzzles_rated_visible_index (ratings_count, is_visible)`.

## 9. دمج الاكتشاف (Discovery)

- «الأعلى تقييمًا من المجتمع» و«من تتابعهم» و«تفضيلاتك» كـ**إشارات ضعيفة** داخل `/discover` لا تحلّ محل الاستكشاف ولا ترفع محتوى بتقييم وحده فوق الجديد: السقف نفسه لكل مبدع (حدّ الأعمال لكل مبدع في المجمَّع) والمجمَّع نفسه.
- «تفضيلاتك»: تصنيفات أعمال قيّمها المشاهد ≥ `affinity_min_rating` (4) كإشارة تفضيل خفيفة — خاصة بالمشاهد، لا تُسرَّب.
- **الاكتشاف الرسمي لا يتأثر**: لا قسم رسمي يقرأ التقييمات أو المفضلة أو المتابعة؛ أحجيات المجتمع تبقى بوسم «من المجتمع» المميَّز.
- المعيار: المحتوى المخفي/المؤرشف/لمبدع معلَّق أو مجمَّد لا يظهر في أي من هذه الأقسام (فحص حي).

## 10. التحليلات (Analytics)

- **المبدع**: لوحته (`CreatorAnalyticsService::engagementFor`) تعرض مجاميع فقط: المتابعون، المفضلة المستلمة، عدد التقييمات، المتوسط (بحدّ العرض نفسه)، وأفضل أعماله بالدرجة المنكمشة (عنوان + متوسط + عدد). **لا هوية مقيِّم/مفضِّل/متابع** — أعداد لا أسماء.
- **الإدارة**: عدّادات مجمَّعة صغيرة (إجمالي التقييمات/المفضلة/المتابعات/المجموعات، أفضل 5 أعمال بالدرجة، أكثر 5 مبدعين متابَعين بعدد المتابعين) في `CreatorEcosystemStatsWidget` من نفس مفتاح الكاش `creators.admin.summary` (60ث) — لا استعلام ثقيل جديد على كل تحميل للوحة. أسماء المبدعين عامة أصلًا؛ **لا تظهر هوية أي متابع/مقيِّم**.
- تحليلات المبدع تعيد استعمال كاش E28 `creators.analytics.{id}` (لا كاش ثانٍ) وتقرأ الأعمال باستعلام واحد.

## 11. مكافحة الإساءة (Anti-abuse)

| الخطر | الدفاع |
|---|---|
| تقييم دون تجربة | شرط «محاولة واحدة على الأقل» + لا تقييم ذاتي. |
| تضخيم صاحب العمل | لا تقييم/مفضلة على عملك، ولا متابعة ذاتية. |
| إغراق/بوتات | أربعة محدِّدات معدّل **بمفتاح user id** (لا IP وحده): `community-rating` 20/د و200/س، `community-favorite` 30/د و300/س، `creator-follow` 15/د و100/س، `collection-actions` 30/د و240/س. الزائر بلا حساب يُحدَّد بـIP (لكن المسارات خلف `auth`). |
| حسابات مجمَّدة | `account.active` على المجموعة + `EngagementGuard::assertCanInteract` في الخدمة (طبقتان): المجمَّد لا يقيّم ولا يفضّل ولا يتابع ولا يلغي ولا ينشئ مجموعات. |
| تقييم قليل العدد يدفع المرتبة | حدّ عرض (3)، حدّ ترتيب (5)، انكماش بايزي. |
| سباق تزامن | قفل صف العمل/المبدع داخل المعاملة + قيود فريدة بالقاعدة. |
| كشف الهوية | لا مسار يعرض قائمة مقيِّمين/متابعين/مفضِّلين لأحد. |

## 12. اتساق العدّادات (Counter consistency)

- **الصفوف هي المصدر الوحيد للحقيقة**؛ `ratings_count` / `ratings_sum` / `favorites_count` على `creator_puzzles` و`followers_count` على `creator_profiles` أعمدة مشتقّة.
- تُحدَّث **بمعاملة واحدة مع الصف** تحت `lockForUpdate` على صف العمل/الملف، بتعبير SQL ذرّي مقيَّد من الأسفل (`CASE` لا يهبط تحت 0) وبقائمة أعمدة بيضاء (`EngagementCounters::adjust`) — لا قراءة-ثم-كتابة بالذاكرة.
- **سياسة حذف المستخدم**: التقييمات `user_id nullOnDelete` (يبقى التقييم **مجهول الهوية** في المجمَّع ولا يتغيّر ترتيب عمل المبدع بحذف حساب)، أما المفضلة والمتابعات والمجموعات فـ`cascadeOnDelete` ويُنقص `EngagementCleanup::purgeUser` عدّاداتها (خطّاف `User::deleting`) فلا يبقى انحراف.
- **المصالحة**: `php artisan community:reconcile-engagement [--dry-run] [--chunk=500]` تقارن العدّادات بالصفوف على دفعات وتصلح الانحراف؛ `--dry-run` يكتشف فقط (كود الخروج 1 إن وُجد انحراف)؛ آمن للتكرار. يُوصى بجدولته أسبوعيًا خارج الذروة.

## 13. قاعدة البيانات

ترحيل واحد: `2026_10_21_000001_create_community_engagement_tables` (لا تعديل على ترحيلات E28).

| الجدول | الأعمدة الأساسية | القيود/الفهارس |
|---|---|---|
| `community_puzzle_ratings` | `user_id` (null عند حذف المستخدم)، `creator_puzzle_id`، `rating`، طوابع | `UNIQUE (user_id, creator_puzzle_id)`، `CHECK rating BETWEEN 1 AND 5` (MySQL/PG)، فهرس `(creator_puzzle_id, rating)` |
| `community_puzzle_favorites` | `user_id`، `creator_puzzle_id`، `created_at` | `UNIQUE (user_id, creator_puzzle_id)`، فهرس `(user_id, id)` لقائمة المستخدم، وفهرس `creator_puzzle_id` |
| `creator_follows` | `user_id`، `creator_profile_id`، `created_at` | `UNIQUE (user_id, creator_profile_id)`، فهرس `(creator_profile_id, id)` لمؤشر fan-out |
| `user_collections` | `public_id` ULID فريد، `user_id`، `title`، `description` | فهرس `(user_id, updated_at)` |
| `user_collection_items` | `user_collection_id`، `creator_puzzle_id`، `created_at` | `UNIQUE (user_collection_id, creator_puzzle_id)`، وفهرس `creator_puzzle_id` |
| `creator_puzzles` (+أعمدة) | `ratings_count`، `ratings_sum`، `favorites_count` | `creator_puzzles_rated_visible_index (ratings_count, is_visible)` |
| `creator_profiles` (+عمود) | `followers_count` | `creator_profiles_followed_index (status, followers_count)` |

الفهرس `(ratings_count, is_visible)` بهذا الترتيب مقصود: ترتيبه المعاكس أزاح فهرس البحث `creator_puzzles_visible_title_index` من خطة SQLite (اختبار E28 X9 يلتقطه).

## 14. الأمان

- كل تعديل `POST/PUT/DELETE` فقط (لا GET يغيّر حالة) ضمن مجموعة `web` (جلسة + CSRF) خلف `auth + verified + account.active`.
- الكنترولرات رفيعة (تحلّ الكيان وتفوّض لخدمة)؛ التحقق في `FormRequest` + إعادة التحقق في الخدمة.
- معرّفات الأعمال والمجموعات ULID في الروابط (`whereUlid`)، ومعرّف المبدع slug آمن ASCII؛ لا أرقام داخلية.
- حماية IDOR: المجموعات تُحلّ بالمالك؛ المفضلة والمتابعات تُقرأ بـ`user_id` الجلسة فقط.
- لا SQL خام بدمج نصي: كل `selectRaw` بربط معاملات (يمرّ بتدقيق SecurityE26 W5)؛ `EngagementCounters::adjust` بقائمة أعمدة بيضاء.
- المخرجات Blade بـ`{{ }}` المهرّب؛ لا HTML من المستخدم.

## 15. الأداء (ميزانيات الاستعلام المقاسة)

القياس الفعلي (SQLite، تسخين أول، مستخدم مسجَّل ما لم يُذكر):

| الصفحة / العملية | الاستعلامات | الشرط |
|---|---|---|
| صفحة الأحجية — ضيف | **5** | = رقم E28 (لا استعلام تفاعل للضيف) |
| صفحة الأحجية — لاعب | **15** | يطابق 1 و25 تقييمًا |
| صفحة المبدع — ضيف | **7** | = رقم E28 |
| صفحة المبدع — مسجَّل | **16** | يطابق 1 و11 عملًا |
| `/community` — مسجَّل | **14** | يطابق 1 و12 بطاقة (حالة المفضلة دفعة واحدة) |
| `/favorites` (12) | **12** | يطابق 2 و12 عنصرًا |
| `/following` (12) | **12** | يطابق 2 و12 عنصرًا |
| `/collections` | **8** | — |
| `/discover` — مسجَّل بإشارات | **27** دافئ / **34** بارد | +3 فوق E28 (الأعلى تقييمًا، من أتابعهم، تفضيلات المشاهد) |
| `PUT` تقييم | **10** | معاملة واحدة بقفل |
| `DELETE` تقييم | **7** | — |
| `POST` مفضلة | **9** | — |
| `POST` متابعة | **10** | — |

الاختبارات تثبّت **تساوي** العدد بين حجمين (لا N+1) + سقفًا مطلقًا هامشه صغير (14–18 للصفحات، 30/38 للاكتشاف). سقف لوحة المبدع في E28 (20) ما زال محترمًا (أُعيدت هيكلة التحليلات باستعلام أعمال واحد).

## 16. بيانات Demo

`php artisan db:seed --class=DemoEngagementSeeder` — اختياري، **يرفض الإنتاج**، يعتمد `DemoCreatorSeeder` (يستدعيه إن لم يوجد مبدعون) ولا يُستدعى من `DemoQaSeeder` فلا تتغير أرقام Demo المثبَّتة بالاختبارات. يعمل **بالخدمات الرسمية فقط** (فتبقى العدّادات مطابقة)، وهو idempotent (التشغيل الثاني بلا تضاعف). حسابات `.test` بكلمة مرور `password` للبيئات المحلية فقط.

## 17. ما لم يُنفَّذ عمدًا (مؤجَّل)

مشاركة المجموعات علنًا وفهرستها وبلاغاتها؛ المراجعات النصية والردود؛ رأي «مفيد/غير مفيد»؛ قائمة المتابعين/المقيِّمين (ستحتاج قرار خصوصية)؛ تجميع الإشعارات (digest) وأولوية طابور مخصصة لمليون متابع؛ الإبلاغ عن تقييم/تقييم مشبوه آليًا وكشف حلقات التقييم؛ التوصيات الشخصية المتعلّمة؛ مكافآت على التقييم أو الإبداع (يمسّ الاقتصاد: مرفوض الآن)؛ تصدير بيانات التفاعل للمستخدم؛ مجموعات تعاونية؛ مكتبة المبدع المتميّز/شارة؛ تحليلات زمنية مفصّلة.

## 18. ربط بنود المواصفة بالاختبارات

139 اختبارًا في `tests/Feature/EngagementE29/` (العناوين تبدأ بالرقم `AA<قسم>-<رقم>`):

| الملف | الاختبارات | يغطي |
|---|---|---|
| `E29RatingTest` | 20 | AA1: الأهلية، النطاق، الفريدية، التعديل/الحذف، المخفي، النسخ، العدّادات |
| `E29FavoriteTest` | 12 | AA2: الإضافة/الحذف/idempotence، الخصوصية، الذاتي، المخفي، `/favorites` |
| `E29FollowTest` | 17 | AA3: المتابعة/الإلغاء، الفريدية، الذاتي، المعتمَد فقط، المجمَّد، الخلاصة، الخصوصية |
| `E29NotificationTest` | 12 | AA4: after-commit، الدفعات، idempotence، الفشل لا يُرجع النشر، التفضيل |
| `E29CollectionTest` | 18 | AA5: الخصوصية، IDOR، السقوف، العناصر المخفية، الحذف، المنتقي |
| `E29TopRatedDiscoveryTest` | 14 | AA6/AA7: الصيغة، الحدّ الأدنى، الكاش، الفحص الحي، الاكتشاف الرسمي، الفهرس |
| `E29SecurityTest` | 13 | AA8: CSRF، الطرق، الحدود، المجمَّد، العزل الاقتصادي |
| `E29IntegrityTest` | 12 | AA9: المصالحة، التزامن، حذف المستخدم، dry-run |
| `E29AnalyticsUiTest` | 14 | AA10: التحليلات، RTL، تحكم النجوم، الوصولية، الهاتف |
| `E29PerformanceTest` | 7 | AA11: ميزانيات الاستعلام وعدم وجود N+1 |

## 19. التشغيل والفحص

```
php artisan migrate                                   # ترحيل E29 الوحيد
php artisan test tests/Feature/EngagementE29          # اختبارات E29 (بلا php artisan test عارية)
php artisan community:reconcile-engagement --dry-run  # كشف الانحراف
php artisan community:reconcile-engagement            # إصلاحه (آمن للتكرار)
```

متغيرات البيئة الاختيارية (كلها لها افتراضيات، لا أسرار): `ENGAGEMENT_RATING_DISPLAY_MIN`, `ENGAGEMENT_TOP_MIN_RATINGS`, `ENGAGEMENT_TOP_PRIOR_MEAN`, `ENGAGEMENT_TOP_PRIOR_WEIGHT`, `ENGAGEMENT_TOP_CACHE_TTL`, `ENGAGEMENT_CREATOR_MIN_RATINGS`, `ENGAGEMENT_MAX_FOLLOWS`, `ENGAGEMENT_MAX_COLLECTIONS`, `ENGAGEMENT_MAX_COLLECTION_ITEMS`, `ENGAGEMENT_NOTIFY_ON_REVISION`.
`````

### `resources/views/components/engagement-icon.blade.php`

`sha256: 015867a6b9c2b59e6c56293821d982b3ed1ee2dc69da8161fd3b44f71d2faccf`

`````blade
@props(['name' => 'star', 'class' => 'w-5 h-5', 'filled' => false])

{{--
    E29: أيقونات التفاعل (نجمة/إشارة مرجعية/مجلد/متابعة) SVG مضمَّنة من مجموعة Heroicons v2 outline (MIT). مكوّن مستقل كي لا يمسّ ui-icon.
    الحالة لا تعتمد على اللون وحده: المعبَّأ (filled) شكل مختلف عن المفرَّغ، ويرافقه دائمًا نص. زخرفية (aria-hidden) والاسم المتاح للقارئ يأتي من النص/aria-label الحاضن.
--}}
@php
    $paths = [
        'star' => 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
        'bookmark' => 'M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z',
        'folder' => 'M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z',
        'user-plus' => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z',
    ];
    $d = $paths[$name] ?? $paths['star'];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} xmlns="http://www.w3.org/2000/svg" fill="{{ $filled ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $d }}" /></svg>
`````

### `resources/views/engagement/_rating_summary.blade.php`

`sha256: b985a2bc7a59157e033319123bd0fc864ed54be3d580833451cc8d9c7c48e4d8`

`````blade
@php
    /** @var int $count @var int $sum */
    $count = (int) ($count ?? 0);
    $sum = (int) ($sum ?? 0);
    $average = app(\App\Services\Engagement\CommunityQualityService::class)->displayAverage($count, $sum);
@endphp
{{-- E29: ملخّص التقييم العلني. عيّنة صغيرة (< display_min) تُعرض كعدد فقط لا كمتوسط؛ لا «5.0» من تقييم واحد. المتوسط من العدّادين المجمَّعين بلا استعلام. --}}
@if ($count === 0)
    <span class="text-[11px] font-bold text-slate-500">لا تقييمات بعد</span>
@elseif ($average !== null)
    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-gold" aria-label="متوسط التقييم {{ number_format($average, 1) }} من 5 بحسب {{ $count }} تقييم">
        <x-engagement-icon name="star" :filled="true" class="w-3.5 h-3.5" />{{ number_format($average, 1) }}<span class="text-slate-400 font-bold">({{ number_format($count) }})</span>
    </span>
@else
    <span class="text-[11px] font-bold text-slate-400">{{ number_format($count) }} {{ $count === 1 ? 'تقييم' : 'تقييمات' }}</span>
@endif
`````

### `resources/views/engagement/_unavailable.blade.php`

`sha256: bd2e25bed35cbccc3a0cf934eaf69b3346a851bfd85010c035dfb00015b7e6ca`

`````blade
{{-- E29: مكان بديل لعمل محفوظ/مضاف لمجموعة صار غير ظاهر. لا عنوان ولا مبدع ولا سبب إخفاء (لا تسريب لقرار الإشراف)؛ يبقى الصف ليعود العمل لو أُعيد. --}}
<div class="glass rounded-2xl p-4 h-full flex flex-col items-center justify-center text-center gap-2 border border-dashed border-white/10" role="group" aria-label="محتوى غير متاح">
    <span class="grid place-items-center w-10 h-10 rounded-xl bg-white/5 text-slate-400"><x-ui-icon name="lock" class="w-5 h-5" /></span>
    <p class="text-sm font-bold text-slate-300">هذا المحتوى غير متاح حاليًا</p>
</div>
`````

### `resources/views/engagement/_work_panel.blade.php`

`sha256: c39f7de42d07a6742509d3df1c2fcddfbf3948795fb52adbdc63252019b7d5ee`

`````blade
@php
    /**
     * E29: قسم التقييم والمفضلة والمجموعات في صفحة أحجية المجتمع.
     * @var \App\Models\CreatorPuzzle $work
     * @var bool $isOwner @var bool $canInteract
     * @var array{rating: int|null, favorite: bool, played: bool}|null $viewer  (null للضيف/المالك/غير المؤهل)
     * @var \Illuminate\Support\Collection|null $collections
     */
    $count = (int) $work->ratings_count;
    $mine = $viewer['rating'] ?? null;
@endphp
<section id="engagement" tabindex="-1" aria-labelledby="engagement-title" class="glass rounded-2xl p-5 md:p-6 space-y-5 anim-fade-up focus:outline-none">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="engagement-title" class="font-display font-black text-lg text-white">رأي المجتمع</h2>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            @include('engagement._rating_summary', ['count' => $count, 'sum' => (int) $work->ratings_sum])
            @if ((int) $work->favorites_count > 0)
                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-400"><x-engagement-icon name="bookmark" :filled="true" class="w-3.5 h-3.5" />{{ number_format((int) $work->favorites_count) }} حفظوها</span>
            @endif
        </div>
    </div>

    @if (session('engagement_status'))
        <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('engagement_status') }}</p>
    @endif

    @guest
        <p class="text-sm text-slate-300">
            <a href="{{ route('login') }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">سجّل الدخول</a>
            لتقييم الأحجية وحفظها في مفضلتك أو في مجموعاتك.
        </p>
    @else
        @if ($isOwner)
            <p class="text-sm text-slate-400">هذه أحجيتك؛ لا يمكنك تقييمها أو حفظها. تراها هنا كما يراها اللاعبون.</p>
        @elseif (! $canInteract)
            <p class="text-sm text-gold font-bold">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتقييم الأحجية وحفظها.</p>
        @else
            {{-- التقييم: مجموعة أزرار راديو أصلية (أسهم لوحة المفاتيح تعمل بلا JS)، النجمة المعبَّأة = مختارة (الشكل + نص «n من 5»، لا اللون وحده). --}}
            @if ($viewer['played'])
                <form method="POST" action="{{ route('community.puzzles.rating.update', $work->public_id) }}" x-data="{ r: {{ (int) ($mine ?? 0) }} }" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-200 mb-2">{{ $mine ? 'تقييمك لهذه الأحجية' : 'قيّم هذه الأحجية' }}</legend>
                        <div class="flex items-center gap-1" role="radiogroup">
                            @foreach (range((int) config('engagement.ratings.min', 1), (int) config('engagement.ratings.max', 5)) as $i)
                                <span class="relative">
                                    <input type="radio" name="rating" id="rating-{{ $i }}" value="{{ $i }}" class="peer sr-only" required x-model.number="r" @checked($mine === $i)>
                                    <label for="rating-{{ $i }}" class="grid place-items-center w-11 h-11 rounded-xl cursor-pointer text-gold hover:bg-white/5 peer-focus-visible:ring-2 peer-focus-visible:ring-amethyst" title="{{ $i }} من 5">
                                        <x-engagement-icon name="star" :filled="$mine !== null && $i <= $mine" class="w-7 h-7" x-bind:fill="r >= {{ $i }} ? 'currentColor' : 'none'" />
                                        <span class="sr-only">{{ $i }} من 5</span>
                                    </label>
                                </span>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-gem !py-2 !px-5 text-sm">{{ $mine ? 'تحديث التقييم' : 'حفظ التقييم' }}</button>
                        @if ($mine) <span class="text-xs font-bold text-slate-400">تقييمك الحالي: {{ $mine }} من 5</span> @endif
                    </div>
                </form>
                @if ($mine)
                    <form method="POST" action="{{ route('community.puzzles.rating.destroy', $work->public_id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة تقييمي</button>
                    </form>
                @endif
            @else
                <p class="text-sm text-slate-400">جرّب الأحجية ولو بمحاولة واحدة ليتاح لك تقييمها.</p>
            @endif

            <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-white/5">
                @if ($viewer['favorite'])
                    <form method="POST" action="{{ route('community.puzzles.favorite.destroy', $work->public_id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary !py-2 !px-4 text-sm inline-flex items-center gap-2" aria-label="إزالة من مفضلتي"><x-engagement-icon name="bookmark" :filled="true" class="w-4 h-4 text-gold" />محفوظة في مفضلتي</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('community.puzzles.favorite.store', $work->public_id) }}">
                        @csrf
                        <button type="submit" class="btn-secondary !py-2 !px-4 text-sm inline-flex items-center gap-2"><x-engagement-icon name="bookmark" class="w-4 h-4" />احفظ في المفضلة</button>
                    </form>
                @endif
                <a href="{{ route('favorites.index') }}" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مفضلتي</a>
            </div>

            <details class="rounded-xl border border-white/10 bg-white/5 p-4">
                <summary class="cursor-pointer text-sm font-bold text-slate-200 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-engagement-icon name="folder" class="w-4 h-4" />أضف إلى مجموعة</summary>
                <form method="POST" action="{{ route('community.puzzles.collections.add', $work->public_id) }}" class="mt-4 space-y-3">
                    @csrf
                    @if ($collections->isNotEmpty())
                        <div>
                            <label for="collection-pick" class="block text-xs font-bold text-slate-300 mb-1">مجموعة قائمة</label>
                            <select id="collection-pick" name="collection" class="input-gem">
                                <option value="">— اختر مجموعة —</option>
                                @foreach ($collections as $collection)
                                    <option value="{{ $collection->public_id }}" @disabled($collection->has_work)>{{ $collection->title }}@if ($collection->has_work) (مضافة) @endif</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="text-xs text-slate-500 text-center">أو</p>
                    @endif
                    <div>
                        <label for="collection-new" class="block text-xs font-bold text-slate-300 mb-1">مجموعة جديدة</label>
                        <input id="collection-new" type="text" name="new_title" maxlength="{{ (int) config('engagement.collections.title_max') }}" value="{{ old('new_title') }}" placeholder="عنوان المجموعة" class="input-gem">
                    </div>
                    @error('collection') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
                    @error('new_title') <p class="text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إضافة</button>
                        <a href="{{ route('collections.index') }}" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مجموعاتي</a>
                    </div>
                </form>
            </details>
        @endif
    @endguest
</section>
`````

### `resources/views/engagement/favorites.blade.php`

`sha256: 171616e7474750dd8213eea5c7356d19d3324b94c80d8c3c3789cfc99c64d51b`

`````blade
@extends('layouts.app')

@section('title', 'مفضلتي')
@section('content_width', 'max-w-6xl')

{{-- E29: قائمة المفضلة الخاصة بصاحبها (الأحدث حفظًا أولًا). العمل غير الظاهر يبقى بمكان بديل «غير متاح حاليًا» بلا عنوان ولا سبب، ويمكن إزالته. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="مفضلتي" icon="sparkles" subtitle="أحجيات المجتمع التي حفظتها. قائمتك خاصة بك؛ يظهر للآخرين عدد الحفظ فقط." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('collections.index') }}" class="chip !py-2 text-sm">مجموعاتي</a>
                <a href="{{ route('community.puzzles.index') }}" class="chip !py-2 text-sm">أحجيات المجتمع</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('engagement_status'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('engagement_status') }}</p>
        @endif

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لم تحفظ أي أحجية بعد" message="اضغط «احفظ في المفضلة» في أي أحجية مجتمع لتجدها هنا لاحقًا." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
        @else
            <section aria-label="الأحجيات المحفوظة" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    <div class="flex flex-col gap-2">
                        <div class="flex-1">
                            @if ($work->is_visible)
                                @include('community._card', ['work' => $work, 'showCreator' => true])
                            @else
                                @include('engagement._unavailable')
                            @endif
                        </div>
                        <form method="POST" action="{{ route('community.puzzles.favorite.destroy', $work->public_id) }}" class="text-center">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="from" value="favorites">
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المفضلة</button>
                        </form>
                    </div>
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
`````

### `resources/views/engagement/following.blade.php`

`sha256: 2c56045aa3f6d9821e68f1052577caca5008b884ead4665d61d0f34ab2e44445`

`````blade
@extends('layouts.app')

@section('title', 'من أتابعهم')
@section('content_width', 'max-w-6xl')

{{-- E29: خلاصة أحدث ما نشره المبدعون الذين تتابعهم (الظاهر الآن فقط، الأحدث نشرًا أولًا). خاصة بك؛ لا تُعرض هويات المتابعين لأحد. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="من أتابعهم" icon="friends" subtitle="أحدث أحجيات المبدعين الذين تتابعهم." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                <a href="{{ route('community.puzzles.index') }}" class="chip !py-2 text-sm">أحجيات المجتمع</a>
            </x-slot:actions>
        </x-page-header>

        @if ($works->isEmpty())
            @if ($followsAny)
                <x-empty-state icon="puzzle" title="لا جديد الآن" message="لم ينشر المبدعون الذين تتابعهم أحجيات جديدة بعد. سنُخطرك عند النشر." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
            @else
                <x-empty-state icon="friends" title="لا تتابع أي مبدع بعد" message="افتح صفحة أي مبدع من أحجيات المجتمع واضغط «متابعة» لتصلك أحدث أحجياته هنا." :action="route('community.puzzles.index')" actionLabel="اكتشف مبدعين" />
            @endif
        @else
            <section aria-label="أحدث أحجيات المتابَعين" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true, 'savedIds' => $savedIds ?? []])
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
`````

### `resources/views/engagement/collections/index.blade.php`

`sha256: b1beb338354bf6c5e9a63c344b1edc05766adc172d7e4ba02625a52cc1a10349`

`````blade
@extends('layouts.app')

@section('title', 'مجموعاتي')
@section('content_width', 'max-w-4xl')

{{-- E29: مجموعاتي الشخصية. خاصة بالكامل (لا رابط عام ولا مشاركة). السقف قابل للضبط ويُعرض للمستخدم. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="مجموعاتي" icon="inventory" subtitle="رتّب أحجيات المجتمع التي تعجبك في مجموعات خاصة بك." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                <a href="{{ route('collections.create') }}" class="btn-gem !py-2 !px-4 text-sm">مجموعة جديدة</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p>
        @endif

        @if ($collections->isEmpty())
            <x-empty-state icon="inventory" title="لا مجموعات بعد" message="أنشئ مجموعة وأضف إليها أحجيات من صفحة أي أحجية مجتمع." :action="route('collections.create')" actionLabel="أنشئ مجموعتك الأولى" />
        @else
            <p class="text-xs text-slate-400">{{ $collections->total() }} من {{ $max }} مجموعة</p>
            <ul class="grid sm:grid-cols-2 gap-3 anim-fade-up">
                @foreach ($collections as $collection)
                    <li>
                        <a href="{{ route('collections.show', $collection->public_id) }}" class="glass rounded-2xl p-4 flex flex-col gap-1 h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                            <span class="flex items-center gap-2 font-display font-black text-white break-words"><x-engagement-icon name="folder" class="w-5 h-5 text-amethyst shrink-0" />{{ $collection->title }}</span>
                            @if ($collection->description) <span class="text-xs text-slate-400 break-words">{{ $collection->description }}</span> @endif
                            <span class="mt-auto pt-2 text-[11px] font-bold text-slate-400">{{ number_format($collection->items_count) }} أحجية</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-center">{{ $collections->links() }}</div>
        @endif
    </div>
@endsection
`````

### `resources/views/engagement/collections/form.blade.php`

`sha256: dce41096fb51231f4cf04de3c997a4d7e743310e2845286ff8904c86469f23c0`

`````blade
@extends('layouts.app')

@php $editing = $collection !== null; @endphp
@section('title', $editing ? 'تعديل المجموعة' : 'مجموعة جديدة')
@section('content_width', 'max-w-xl')

@section('content')
    <div class="space-y-6">
        <x-page-header :title="$editing ? 'تعديل المجموعة' : 'مجموعة جديدة'" icon="inventory" :back="$editing ? route('collections.show', $collection->public_id) : route('collections.index')" class="anim-fade-up !mb-0" />

        <form method="POST" action="{{ $editing ? route('collections.update', $collection->public_id) : route('collections.store') }}" class="glass rounded-2xl p-5 md:p-6 space-y-4 anim-fade-up">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="title" class="block text-xs font-bold text-slate-300 mb-1">العنوان</label>
                <input id="title" type="text" name="title" required minlength="{{ (int) config('engagement.collections.title_min') }}" maxlength="{{ (int) config('engagement.collections.title_max') }}" value="{{ old('title', $collection?->title) }}" class="input-gem" aria-describedby="title-error">
                @error('title') <p id="title-error" class="mt-1 text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="description" class="block text-xs font-bold text-slate-300 mb-1">وصف (اختياري)</label>
                <textarea id="description" name="description" rows="3" maxlength="{{ (int) config('engagement.collections.description_max') }}" class="input-gem" aria-describedby="description-error">{{ old('description', $collection?->description) }}</textarea>
                @error('description') <p id="description-error" class="mt-1 text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
            </div>
            <p class="text-xs text-slate-500">المجموعة خاصة بك دائمًا ولا يراها أحد غيرك.</p>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">{{ $editing ? 'حفظ التعديلات' : 'إنشاء المجموعة' }}</button>
                <a href="{{ $editing ? route('collections.show', $collection->public_id) : route('collections.index') }}" class="text-sm font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلغاء</a>
            </div>
        </form>
    </div>
@endsection
`````

### `resources/views/engagement/collections/show.blade.php`

`sha256: 90c76190da766de72668d9e061a1ded4f84a297cba587167f703fdd6c2681f57`

`````blade
@extends('layouts.app')

@section('title', $collection->title.' - مجموعتي')
@section('content_width', 'max-w-6xl')

{{-- E29: محتوى مجموعة (خاصة بمالكها؛ غير المالك 404 من الخدمة). العنصر غير الظاهر يبقى بمكان بديل بلا عنوان ولا سبب، ويمكن إزالته. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="$collection->title" icon="inventory" :subtitle="$collection->description" :back="route('collections.index')" backLabel="مجموعاتي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('collections.edit', $collection->public_id) }}" class="chip !py-2 text-sm">تعديل</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p>
        @endif

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="المجموعة فارغة" message="افتح أي أحجية مجتمع واختر «أضف إلى مجموعة» لتظهر هنا." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
        @else
            <section aria-label="أحجيات المجموعة" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    <div class="flex flex-col gap-2">
                        <div class="flex-1">
                            @if ($work->is_visible)
                                @include('community._card', ['work' => $work, 'showCreator' => true])
                            @else
                                @include('engagement._unavailable')
                            @endif
                        </div>
                        <form method="POST" action="{{ route('collections.items.destroy', [$collection->public_id, $work->public_id]) }}" class="text-center">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المجموعة</button>
                        </form>
                    </div>
                @endforeach
            </section>
            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif

        <details class="glass rounded-2xl p-5 anim-fade-up">
            <summary class="cursor-pointer text-sm font-bold text-rose flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trash" class="w-4 h-4" />حذف المجموعة</summary>
            <form method="POST" action="{{ route('collections.destroy', $collection->public_id) }}" class="mt-4 space-y-3">
                @csrf
                @method('DELETE')
                <p class="text-sm text-slate-300">سيُحذف تجميع هذه المجموعة فقط؛ الأحجيات نفسها ومفضلتك وتقييماتك لا تتأثر.</p>
                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm !text-rose">تأكيد حذف المجموعة</button>
            </form>
        </details>
    </div>
@endsection
`````

### `app/Http/Controllers/Engagement/CollectionController.php`

`sha256: 9e44d383aa6fb343bc8c4bddc957cde3b9fa29831cde5c5a056704aa0c5a199d`

`````php
<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Http\Requests\Engagement\AddToCollectionRequest;
use App\Http\Requests\Engagement\CollectionRequest;
use App\Models\UserCollection;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E29 - المجموعات الشخصية (خاصة فقط، لا مسار عام). كل مسار {collection} يُحلّ بمعرّف ULID **مع user_id المالك** (غير المالك = 404).
 * المتحكم رقيق: تحقق (FormRequest بقواعد الخدمة) ← خدمة ← عودة.
 */
class CollectionController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CollectionService $collections) {}

    public function index(Request $request)
    {
        $collections = UserCollection::query()->where('user_id', $request->user()->id)
            ->select('id', 'public_id', 'title', 'description', 'updated_at')->withCount('items')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) config('engagement.pagination.collections'));

        return view('engagement.collections.index', ['collections' => $collections, 'max' => (int) config('engagement.collections.max_per_user')]);
    }

    public function create()
    {
        return view('engagement.collections.form', ['collection' => null]);
    }

    public function store(CollectionRequest $request): RedirectResponse
    {
        try {
            $collection = $this->collections->create($request->user(), (string) $request->validated('title'), $request->validated('description'));
        } catch (EngagementException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $collection->public_id)->with('success', 'أُنشئت المجموعة.');
    }

    public function show(Request $request, EngagementLists $lists, string $collection)
    {
        $collection = $this->collections->owned($request->user(), $collection);

        return view('engagement.collections.show', [
            'collection' => $collection,
            'works' => $lists->collectionItems($collection, (int) config('engagement.pagination.collection_items')),
        ]);
    }

    public function edit(Request $request, string $collection)
    {
        return view('engagement.collections.form', ['collection' => $this->collections->owned($request->user(), $collection)]);
    }

    public function update(CollectionRequest $request, string $collection): RedirectResponse
    {
        try {
            $updated = $this->collections->update($request->user(), $collection, (string) $request->validated('title'), $request->validated('description'));
        } catch (EngagementException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $updated->public_id)->with('success', 'حُفظت التعديلات.');
    }

    public function destroy(Request $request, string $collection): RedirectResponse
    {
        try {
            $this->collections->delete($request->user(), $collection);
        } catch (EngagementException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.index')->with('success', 'حُذفت المجموعة (الأحجيات نفسها لم تُحذف).');
    }

    /** من صفحة الأحجية: إضافتها لمجموعة قائمة أو لمجموعة جديدة تُنشأ في الخطوة نفسها. */
    public function addWork(AddToCollectionRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            if (filled($request->validated('collection'))) {
                $result = $this->collections->add($request->user(), (string) $request->validated('collection'), $work);

                return $this->backToWork($work, $result['changed'] ? 'أُضيفت الأحجية إلى المجموعة.' : 'الأحجية موجودة في هذه المجموعة مسبقًا.');
            }

            $this->collections->createAndAdd($request->user(), (string) $request->validated('new_title'), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, 'أُنشئت مجموعة جديدة وأُضيفت إليها الأحجية.');
    }

    public function removeWork(Request $request, string $collection, string $work): RedirectResponse
    {
        try {
            $this->collections->remove($request->user(), $collection, $work);
        } catch (EngagementException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $collection);
    }
}
`````

### `app/Http/Controllers/Engagement/CommunityFavoriteController.php`

`sha256: 3521eb0d1f8f4de0c2c228d52ce4327eac1ddff5d9b94b95edac4afe471cb200`

`````php
<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E29 - المفضلة: POST/DELETE صريحان (لا تبديل GET). صفحة «مفضلتي» خاصة بصاحبها حصرًا (تُقرأ دائمًا بـuser_id الجلسة، لا معرّف مستخدم من الطلب). */
class CommunityFavoriteController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CommunityFavoriteService $favorites) {}

    public function index(Request $request, EngagementLists $lists)
    {
        $works = $lists->favorites($request->user(), (int) config('engagement.pagination.favorites'));

        return view('engagement.favorites', ['works' => $works]);
    }

    public function store(Request $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $result = $this->favorites->add($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, $result['changed'] ? 'حُفظت الأحجية في مفضلتك.' : null);
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        $work = $this->anyWork($work);

        try {
            $result = $this->favorites->remove($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        abort_if(! $result['changed'] && ! $work->is_visible, 404);

        // من صفحة المفضلة نفسها نعود إليها؛ ومن صفحة الأحجية إلى قسم التفاعل.
        if ($request->input('from') === 'favorites') {
            return redirect()->route('favorites.index');
        }

        return $this->backToWork($work, $result['changed'] ? 'أُزيلت الأحجية من مفضلتك.' : null);
    }
}
`````

### `app/Http/Controllers/Engagement/CommunityRatingController.php`

`sha256: 7b6f8c2b1a45d34d2eed6307b50fb7b060aea2cf478c6ab4294ecdac01e9339b`

`````php
<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Http\Requests\Engagement\RateCommunityPuzzleRequest;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E29 - تقييم أحجية مجتمع (PUT/DELETE فقط، أبدًا GET). المتحكم رقيق: تحقق ← خدمة ← عودة. لا أثر اقتصادي. */
class CommunityRatingController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CommunityRatingService $ratings) {}

    public function update(RateCommunityPuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $this->ratings->set($request->user(), $work, (int) $request->validated('rating'));
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, 'تم حفظ تقييمك.');
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        $work = $this->anyWork($work);

        try {
            $removed = $this->ratings->remove($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        abort_if(! $removed && ! $work->is_visible, 404);   // لا نكشف وجود عمل مخفي لمن لا تقييم له عليه

        return $this->backToWork($work, $removed ? 'تمت إزالة تقييمك.' : null);
    }
}
`````

### `app/Http/Controllers/Engagement/CreatorFollowController.php`

`sha256: 52b16fbf9d8bc5cfc7c94f9595e5de4dc4a03594cdf1c61c0945fca2f50714fa`

`````php
<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E29 - متابعة المبدعين + خلاصة /following. POST/DELETE فقط. لا تُكشف هوية المتابعين لأحد؛ خلاصتي وقائمة من أتابعهم خاصتان بي (user_id الجلسة).
 * مبدع غير معتمَد/مجمَّد = 404 كصفحته العامة (لا تسريب لوجود ملف معلَّق). إلغاء متابعة مبدع عُلِّق لاحقًا مسموح لمن يتابعه فعلًا.
 */
class CreatorFollowController extends Controller
{
    public function __construct(protected CreatorFollowService $follows) {}

    public function feed(Request $request, EngagementLists $lists, EngagementViewerState $viewerState)
    {
        $works = $lists->following($request->user(), (int) config('engagement.pagination.following'));

        return view('engagement.following', [
            'works' => $works,
            'savedIds' => $viewerState->favoriteIds($request->user(), collect($works->items())->pluck('id')->all()),
            // الحالة الفارغة تفرّق بين «لا تتابع أحدًا» و«من تتابعهم لم ينشروا جديدًا»: استعلام exists واحد وفقط عند خلوّ الصفحة.
            'followsAny' => $works->isNotEmpty() || $this->followsAnyone($request),
        ]);
    }

    public function store(Request $request, CreatorProfile $creator): RedirectResponse
    {
        abort_unless($this->isPublic($creator), 404);

        try {
            $this->follows->follow($request->user(), $creator);
        } catch (EngagementException $e) {
            return $this->back($creator)->with('error', $e->getMessage());
        }

        return $this->back($creator, 'أصبحت تتابع هذا المبدع.');
    }

    public function destroy(Request $request, CreatorProfile $creator): RedirectResponse
    {
        abort_unless($this->isPublic($creator) || $this->follows->isFollowing($request->user(), $creator->id), 404);

        try {
            $result = $this->follows->unfollow($request->user(), $creator);
        } catch (EngagementException $e) {
            return $this->back($creator)->with('error', $e->getMessage());
        }

        return $this->back($creator, $result['changed'] ? 'ألغيت متابعة هذا المبدع.' : null);
    }

    protected function isPublic(CreatorProfile $creator): bool
    {
        $creator->loadMissing('user:id,is_frozen');

        return $creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen;
    }

    protected function followsAnyone(Request $request): bool
    {
        return \App\Models\CreatorFollow::query()->where('user_id', $request->user()->id)->exists();
    }

    /** العودة لحيث كان المستخدم (صفحة أحجية أو صفحة المبدع أو الخلاصة) إن كانت من موقعنا، وإلا لصفحة المبدع. */
    protected function back(CreatorProfile $creator, ?string $status = null): RedirectResponse
    {
        $previous = url()->previous();
        $target = str_starts_with($previous, url('/')) ? $previous : route('creators.show', $creator->slug);
        $redirect = redirect()->to($target);

        return $status === null ? $redirect : $redirect->with('engagement_status', $status);
    }
}
`````

### `app/Http/Controllers/Engagement/Concerns/ResolvesCommunityWork.php`

`sha256: 090ca8895630dfea50c03c7a19b75ab8ee485066dd1e315ce14e1999969f28d7`

`````php
<?php

namespace App\Http\Controllers\Engagement\Concerns;

use App\Models\CreatorPuzzle;
use Illuminate\Http\RedirectResponse;

/**
 * E29 - حلّ العمل من معرّفه العام (ULID). الإضافة (تقييم/حفظ/مجموعة) تشترط عملًا **ظاهرًا** وإلا 404 كصفحته العامة تمامًا؛
 * الإزالة تقبل أي عمل موجود (ينظّف المستخدم قوائمه) لكن لا تكشف وجود عمل مخفي لمن لا صف له عليه (انظر المتحكمات).
 */
trait ResolvesCommunityWork
{
    protected function visibleWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->visible()->where('public_id', $publicId)->with('creator:id,user_id,slug')->firstOrFail();
    }

    protected function anyWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->where('public_id', $publicId)->with('creator:id,user_id,slug')->firstOrFail();
    }

    /** العودة لقسم التفاعل بصفحة الأحجية (يحفظ موضع القراءة على الجوال ويدعم التركيز لقارئات الشاشة). */
    protected function backToWork(CreatorPuzzle $work, ?string $status = null): RedirectResponse
    {
        $redirect = redirect()->to(route('community.puzzles.show', $work->public_id).'#engagement');

        return $status === null ? $redirect : $redirect->with('engagement_status', $status);
    }
}
`````

### `app/Http/Requests/Engagement/AddToCollectionRequest.php`

`sha256: 479ce1286926e435e00b111491ef56845967235f23fcdb45d82d603f839d44bb`

`````php
<?php

namespace App\Http\Requests\Engagement;

use App\Services\Engagement\CollectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** E29 - إضافة أحجية لمجموعة: إما `collection` (معرّف ULID لمجموعة قائمة) أو `new_title` لإنشاء مجموعة جديدة. واحد منهما فقط. */
class AddToCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collection' => ['nullable', 'string', 'size:26', 'alpha_num'],
            // قواعد عنوان المجموعة نفسها (مصدر واحد) لكن اختيارية هنا: الحقل إما يُملأ أو يُترك لاختيار مجموعة قائمة.
            'new_title' => collect(CollectionService::rules()['title'])->reject(fn ($rule) => $rule === 'required')->prepend('nullable')->values()->all(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasCollection = filled($this->input('collection'));
            $hasNew = filled($this->input('new_title'));

            if ($hasCollection === $hasNew) {
                $v->errors()->add('collection', 'اختر مجموعة قائمة أو اكتب عنوانًا لمجموعة جديدة (أحدهما فقط).');
            }
        });
    }

    public function attributes(): array
    {
        return ['collection' => 'المجموعة', 'new_title' => 'عنوان المجموعة الجديدة'];
    }
}
`````

### `app/Http/Requests/Engagement/CollectionRequest.php`

`sha256: 94222e56dee58bc3a8dc41f1962eb31bc99df47cbe24f9b68e5826e5adb93cdd`

`````php
<?php

namespace App\Http\Requests\Engagement;

use App\Services\Engagement\CollectionService;
use Illuminate\Foundation\Http\FormRequest;

/** E29 - إنشاء/تعديل مجموعة. القواعد مصدرها واحد (CollectionService::rules): عنوان ووصف نص عادي بلا وسوم. */
class CollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CollectionService::rules();
    }

    public function attributes(): array
    {
        return ['title' => 'عنوان المجموعة', 'description' => 'وصف المجموعة'];
    }
}
`````

### `app/Http/Requests/Engagement/RateCommunityPuzzleRequest.php`

`sha256: bd2d19d637c5475f77644d1e7a38d25122d35642478082304f2c74ae5f55f39a`

`````php
<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

/** E29 - الحقل الوحيد المقبول `rating` (عدد صحيح بالنطاق). أي حقل آخر بالطلب (متوسط، عدّاد، user_id...) يُتجاهَل: لا يصل لأي كتابة. */
class RateCommunityPuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:'.(int) config('engagement.ratings.min', 1).','.(int) config('engagement.ratings.max', 5)]];
    }

    public function attributes(): array
    {
        return ['rating' => 'التقييم'];
    }
}
`````

### `app/Services/Engagement/CollectionService.php`

`sha256: 3a93f044288c61e82c7c976be3a520484ab68c725fe995c0af484a50153989f1`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Rules\NoMarkup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E29 - المجموعات الشخصية (**خاصة فقط**: لا مشاركة عامة ولا فهرسة ولا بلاغات بهذه المرحلة، قرار مقصود).
 *
 *  - الملكية: كل قراءة/كتابة تحلّ المجموعة بمعرّفها العام **مع user_id المالك** (غير المالك = ModelNotFound = 404، لا 403 يكشف الوجود). لا IDOR.
 *  - العنوان والوصف نص عادي: تُطبَّع المسافات ثم يتحقق Validator بقواعد NoMarkup (نفس قواعد الطلب HTTP، مصدرها هنا وحده).
 *  - سقفان قابلان للضبط: عدد المجموعات للمستخدم وعدد العناصر بالمجموعة، يُفحصان تحت قفل صف المالك/المجموعة (لا تجاوز بالتزامن).
 *  - العضوية تشير للعمل الثابت؛ إخفاء العمل لا يحذفها (يعود الظهور بالاستعادة)، لكنها لا تُضاف لعمل غير ظاهر.
 *  - حذف المجموعة يحذف عضوياتها فقط (لا الأحجيات). لا أثر على الاقتصاد أو التقدّم.
 */
class CollectionService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** قواعد التحقق الوحيدة لعنوان/وصف المجموعة (يستعملها طلب HTTP والخدمة معًا). */
    public static function rules(): array
    {
        $c = (array) config('engagement.collections');

        return [
            'title' => ['required', 'string', 'min:'.(int) $c['title_min'], 'max:'.(int) $c['title_max'], new NoMarkup],
            'description' => ['nullable', 'string', 'max:'.(int) $c['description_max'], new NoMarkup],
        ];
    }

    public function create(User $user, string $title, ?string $description = null): UserCollection
    {
        $this->guard->assertCanInteract($user);
        $data = $this->normalize($title, $description);

        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);   // يُسلسل إنشاءات المستخدم الواحد: السقف لا يُتجاوز بالتزامن

            if (UserCollection::query()->where('user_id', $user->id)->count() >= (int) config('engagement.collections.max_per_user')) {
                throw new EngagementException('بلغت الحد الأقصى لعدد المجموعات ('.(int) config('engagement.collections.max_per_user').'). احذف مجموعة لإنشاء أخرى.');
            }

            $collection = new UserCollection;
            $collection->forceFill(['user_id' => $user->id] + $data)->save();

            return $collection;
        });
    }

    public function update(User $user, string $publicId, string $title, ?string $description = null): UserCollection
    {
        $this->guard->assertCanInteract($user);
        $data = $this->normalize($title, $description);
        $collection = $this->owned($user, $publicId);
        $collection->forceFill($data)->save();

        return $collection;
    }

    public function delete(User $user, string $publicId): void
    {
        $this->guard->assertCanInteract($user);
        $collection = $this->owned($user, $publicId);

        DB::transaction(function () use ($collection) {
            UserCollectionItem::query()->where('user_collection_id', $collection->id)->delete();
            $collection->delete();
        });
    }

    /** مجموعة المستخدم بمعرّفها العام، وإلا ModelNotFoundException (404) سواء لم توجد أو كانت لغيره. */
    public function owned(User $user, string $publicId, bool $lock = false): UserCollection
    {
        $query = UserCollection::query()->where('user_id', $user->id)->where('public_id', $publicId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array{changed: bool} */
    public function add(User $user, string $collectionPublicId, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $collectionPublicId, $work) {
            $collection = $this->owned($user, $collectionPublicId, true);
            $visible = (bool) CreatorPuzzle::query()->whereKey($work->id)->value('is_visible');

            if (! $visible) {
                throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
            }

            if (UserCollectionItem::query()->where('user_collection_id', $collection->id)->where('creator_puzzle_id', $work->id)->exists()) {
                return ['changed' => false];
            }

            if (UserCollectionItem::query()->where('user_collection_id', $collection->id)->count() >= (int) config('engagement.collections.max_items')) {
                throw new EngagementException('امتلأت هذه المجموعة (الحد الأقصى '.(int) config('engagement.collections.max_items').' أحجية).');
            }

            $item = new UserCollectionItem;
            $item->forceFill(['user_collection_id' => $collection->id, 'creator_puzzle_id' => $work->id, 'created_at' => now()])->save();
            $collection->touch();

            return ['changed' => true];
        });
    }

    /** إنشاء مجموعة سريع من صفحة الأحجية ثم إضافتها إليها (معاملة واحدة: لا مجموعة فارغة تُترك عند فشل الإضافة). */
    public function createAndAdd(User $user, string $title, CreatorPuzzle $work): UserCollection
    {
        return DB::transaction(function () use ($user, $title, $work) {
            $collection = $this->create($user, $title);
            $this->add($user, $collection->public_id, $work);

            return $collection;
        });
    }

    public function remove(User $user, string $collectionPublicId, string $workPublicId): bool
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $collectionPublicId, $workPublicId) {
            $collection = $this->owned($user, $collectionPublicId, true);
            $workId = CreatorPuzzle::query()->where('public_id', $workPublicId)->value('id');

            if ($workId === null) {
                return false;
            }

            $deleted = UserCollectionItem::query()->where('user_collection_id', $collection->id)->where('creator_puzzle_id', $workId)->delete();

            if ($deleted > 0) {
                $collection->touch();
            }

            return $deleted > 0;
        });
    }

    /**
     * مجموعات المستخدم لمنتقي «أضف إلى مجموعة»: استعلام واحد (has_work بمقطع فرعي) بلا استعلام لكل مجموعة.
     *
     * @return Collection<int, UserCollection>
     */
    public function pickerFor(User $user, int $workId): Collection
    {
        return UserCollection::query()->where('user_id', $user->id)
            ->select('id', 'public_id', 'title')
            ->withExists(['items as has_work' => fn ($q) => $q->where('creator_puzzle_id', $workId)])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit((int) config('engagement.collections.max_per_user'))
            ->get();
    }

    /** @return array{title: string, description: string|null} */
    protected function normalize(string $title, ?string $description): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $description = $description === null ? null : trim((string) preg_replace('/[ \t]+/u', ' ', $description));
        $description = ($description === '' || $description === null) ? null : $description;

        Validator::make(['title' => $title, 'description' => $description], self::rules(), [], ['title' => 'عنوان المجموعة', 'description' => 'وصف المجموعة'])->validate();

        return ['title' => $title, 'description' => $description];
    }
}
`````

### `app/Services/Engagement/CommunityFavoriteService.php`

`sha256: b8cec431606b0198791536b717f8a6edf420ba97fe45d32d335aebc17e3e44d6`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CreatorPuzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - المفضلة (مفهوم واحد: «محفوظة»؛ لا إعجاب منفصل). **بلا سلطة اقتصادية** ولا أثر على التقدّم.
 *
 *  - خاصة: ما يحفظه مستخدم لا يراه غيره. الرقم المجمَّع (favorites_count) وحده علني.
 *  - فريدة لكل (مستخدم، عمل) بقيد القاعدة؛ تكرار الإضافة آمن (idempotent) ولا يضاعف العدّاد.
 *  - **لا مفضلة ذاتية** (قرار متحفظ): صاحب العمل لا يضخّم رقم عمله بنفسه.
 *  - إضافة جديدة تشترط عملًا ظاهرًا؛ الإزالة مسموحة دائمًا (حتى لو أُخفي العمل) كي ينظّف المستخدم قائمته.
 *  - صف المفضلة يبقى مع الإخفاء ويعود الظهور بالاستعادة طبيعيًا (انظر FavoritesPage: المخفي لا يُعرض ولا يُلعب).
 */
class CommunityFavoriteService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, count: int} */
    public function add(User $user, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $this->guard->assertVisible($locked);

            if ($this->guard->ownerId($locked) === $user->id) {
                throw new EngagementException('لا يمكنك حفظ أحجيتك في مفضلتك.');
            }

            if (CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->exists()) {
                return ['changed' => false, 'count' => $this->count($locked->id)];
            }

            $row = new CommunityPuzzleFavorite;
            $row->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $locked->id, 'created_at' => now()])->save();
            EngagementCounters::adjust('creator_puzzles', $locked->id, ['favorites_count' => 1]);

            return ['changed' => true, 'count' => $this->count($locked->id)];
        });
    }

    /** @return array{changed: bool, count: int} */
    public function remove(User $user, CreatorPuzzle $work): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $deleted = CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->delete();

            if ($deleted > 0) {
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['favorites_count' => -1]);
            }

            return ['changed' => $deleted > 0, 'count' => $this->count($locked->id)];
        });
    }

    public function isFavorite(User $user, int $workId): bool
    {
        return CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->exists();
    }

    protected function count(int $workId): int
    {
        return (int) DB::table('creator_puzzles')->where('id', $workId)->value('favorites_count');
    }
}
`````

### `app/Services/Engagement/CommunityQualityService.php`

`sha256: 0a685ffb5128ae4d405cf67172bbaea6b74a2a3fbc17205816848973c0166142`

`````php
<?php

namespace App\Services\Engagement;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E29 - جودة أحجيات المجتمع: ترتيب «الأعلى تقييمًا» بدرجة انكماش بايزية بسيطة وقابلة للشرح (انظر config/engagement.php).
 *
 *  score = (ratings_sum + prior_mean × prior_weight) / (ratings_count + prior_weight)
 *
 *  - لا ORDER BY AVG وحده أبدًا: تقييم واحد بخمس نجوم لا يتفوق على 4.8 من 500 تقييم.
 *  - حدّ أدنى لعدد التقييمات (min_ratings) قبل الأهلية للترتيب.
 *  - الكاش يحمل **معرّفات ودرجات فقط** (لا أسماء ولا هويات تقييم)؛ الظهور يُفحص حيًّا بكل طلب بمن يستهلكه (CommunityDiscovery)، فالمخفي يختفي فورًا ولو بقي بالكاش.
 *  - TTL قصير ولا إبطال يدوي: أسوأ حالة تأخير = TTL لتغيّر الترتيب فقط (لا لظهور محتوى مخفي).
 *  - لا أثر اقتصادي لأي ترتيب أو وسم هنا (عرض فقط).
 */
class CommunityQualityService
{
    public function minRatings(): int
    {
        return max(1, (int) config('engagement.top_rated.min_ratings', 5));
    }

    public function eligible(int $count): bool
    {
        return $count >= $this->minRatings();
    }

    /** الدرجة المنكمشة. حتمية: نفس المدخلات = نفس الناتج. */
    public function score(int $count, int $sum): float
    {
        $mean = (float) config('engagement.top_rated.prior_mean', 3.5);
        $weight = max(0.0, (float) config('engagement.top_rated.prior_weight', 5));
        $denominator = $count + $weight;

        return $denominator > 0 ? ($sum + $mean * $weight) / $denominator : 0.0;
    }

    /** متوسط خام بخانة عشرية، أو null إن قلّ العدد عن حدّ العرض (عيّنة صغيرة لا تُعرض كمتوسط). */
    public function displayAverage(int $count, int $sum): ?float
    {
        return $count >= max(1, (int) config('engagement.ratings.display_min', 3)) ? round($sum / $count, 1) : null;
    }

    /** ملخّص جودة المبدع على أعماله الظاهرة، أو null إن قلّ مجموع تقييماته عن الحدّ. @return array{average: float, count: int}|null */
    public function creatorSummary(int $count, int $sum): ?array
    {
        if ($count < max(1, (int) config('engagement.creator_quality.min_ratings', 5))) {
            return null;
        }

        return ['average' => round($sum / $count, 1), 'count' => $count];
    }

    /**
     * أفضل الأعمال الظاهرة المؤهلة بالدرجة. معرّفات + درجات فقط.
     *
     * @return array<int, array{score: float, count: int, average: float}> مرتبة تنازليًا بالدرجة ثم عدد التقييمات ثم الأحدث.
     */
    public function topRated(): array
    {
        return CachedSnapshot::remember('discover.community.top_rated', (int) config('engagement.top_rated.cache_ttl', 300), fn () => $this->computeTopRated());
    }

    /** @return array<int, array{score: float, count: int, average: float}> */
    public function computeTopRated(): array
    {
        $mean = (float) config('engagement.top_rated.prior_mean', 3.5);
        $weight = max(0.0, (float) config('engagement.top_rated.prior_weight', 5));
        $rows = DB::table('creator_puzzles')
            ->where('is_visible', true)
            ->where('ratings_count', '>=', $this->minRatings())
            ->selectRaw('id, ratings_count, ratings_sum, (ratings_sum + ?) / (ratings_count + ?) as weighted', [$mean * $weight, $weight])   // قيم الإعداد عبر ربط معاملات لا دمج نصي
            ->orderByRaw('weighted DESC')->orderByDesc('ratings_count')->orderByDesc('id')
            ->limit(max(1, (int) config('engagement.top_rated.pool', 40)))
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->id => [
            'score' => $this->score((int) $r->ratings_count, (int) $r->ratings_sum),
            'count' => (int) $r->ratings_count,
            'average' => round(((int) $r->ratings_sum) / (int) $r->ratings_count, 1),
        ]])->all();
    }
}
`````

### `app/Services/Engagement/CommunityRatingService.php`

`sha256: ce6d5819ff06502c0e9eb1b424d9f5806c4e049f48416eaf6a28c2167d32abb3`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorPuzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - تقييم أحجيات المجتمع (نجوم 1–5). **بلا سلطة اقتصادية**: لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات ولا أي أثر على التقدّم.
 *
 *  - الأهلية: مستخدم موثَّق غير مجمَّد، ليس صاحب العمل، وله **محاولة واحدة على الأقل** على العمل (لا يُشترط الحل: الصعبة تُقيَّم أيضًا).
 *  - الهدف: العمل الثابت CreatorPuzzle لا نسخة الأحجية القانونية: اعتماد نسخة جديدة لا يمسّ تاريخ التقييمات.
 *  - تقييم واحد لكل (مستخدم، عمل) بقيد فريد بالقاعدة؛ التعديل يغيّر الصف نفسه؛ الحذف يزيله.
 *  - المصدر الوحيد للحقيقة صفوف التقييم؛ ratings_count/ratings_sum على العمل مشتقّان، يُحدَّثان بمعاملة واحدة مع الصف تحت قفل صف العمل (تحديث ذرّي).
 *  - عمل مخفي/مؤرشف/لمبدع معلَّق: لا تقييم جديد ولا تعديل؛ التقييمات القديمة تبقى محفوظة ولا تُعرض (لا يُعرض المحتوى أصلًا). الحذف الذاتي مسموح.
 */
class CommunityRatingService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, rating: int, count: int, average: float|null} */
    public function set(User $user, CreatorPuzzle $work, int $rating): array
    {
        $this->guard->assertCanInteract($user);

        $min = (int) config('engagement.ratings.min', 1);
        $max = (int) config('engagement.ratings.max', 5);

        if ($rating < $min || $rating > $max) {
            throw new EngagementException("قيمة التقييم من {$min} إلى {$max}.");
        }

        return DB::transaction(function () use ($user, $work, $rating) {
            $locked = $this->guard->lockWork($work->id);
            $this->guard->assertVisible($locked);

            if ($this->guard->ownerId($locked) === $user->id) {
                throw new EngagementException('لا يمكنك تقييم أحجيتك.');
            }

            if (! CommunityPuzzleAttempt::query()->where('creator_puzzle_id', $locked->id)->where('user_id', $user->id)->exists()) {
                throw new EngagementException('جرّب الأحجية أولًا ليتاح لك تقييمها.');
            }

            $existing = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->lockForUpdate()->first();
            $changed = false;

            if ($existing === null) {
                $row = new CommunityPuzzleRating;
                $row->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $locked->id, 'rating' => $rating])->save();
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_count' => 1, 'ratings_sum' => $rating]);
                $changed = true;
            } elseif ((int) $existing->rating !== $rating) {
                $delta = $rating - (int) $existing->rating;
                $existing->forceFill(['rating' => $rating])->save();
                EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_sum' => $delta]);
                $changed = true;
            }

            return ['changed' => $changed] + $this->summary($locked->id, $rating);
        });
    }

    /** يحذف تقييم المستخدم نفسه. @return bool هل حُذف صف فعلًا (idempotent: لا شيء للحذف = false) */
    public function remove(User $user, CreatorPuzzle $work): bool
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $work) {
            $locked = $this->guard->lockWork($work->id);
            $row = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $locked->id)->lockForUpdate()->first();

            if ($row === null) {
                return false;
            }

            $value = (int) $row->rating;
            $row->delete();
            EngagementCounters::adjust('creator_puzzles', $locked->id, ['ratings_count' => -1, 'ratings_sum' => -$value]);

            return true;
        });
    }

    /** تقييم المستخدم الحالي للعمل أو null. */
    public function current(User $user, int $workId): ?int
    {
        $value = CommunityPuzzleRating::query()->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->value('rating');

        return $value === null ? null : (int) $value;
    }

    /** @return array{rating: int, count: int, average: float|null} */
    protected function summary(int $workId, int $rating): array
    {
        $row = DB::table('creator_puzzles')->where('id', $workId)->first(['ratings_count', 'ratings_sum']);
        $count = (int) ($row->ratings_count ?? 0);

        return ['rating' => $rating, 'count' => $count, 'average' => $count > 0 ? round(((int) $row->ratings_sum) / $count, 1) : null];
    }
}
`````

### `app/Services/Engagement/CreatorFollowService.php`

`sha256: fce778b066f481a72122725babb069ab2eccbe0a31d176ccc4fd4d319311a494`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - متابعة المبدعين. **بلا سلطة اقتصادية** ولا ميزة مدفوعة.
 *
 *  - يتابع المستخدم **مبدعًا معتمَدًا** حسابه غير مجمَّد فقط (تمامًا كشرط ظهور صفحته العامة)؛ لا متابعة ذاتية؛ سقف لعدد ما يتابعه (config).
 *  - متابعة واحدة لكل (مستخدم، مبدع) بقيد القاعدة؛ التكرار آمن ولا يضاعف followers_count.
 *  - الخصوصية: قائمة ما يتابعه المستخدم خاصة؛ هوية المتابعين لا تُعرض لأحد. العدد المجمَّع وحده علني.
 *  - تعليق المبدع/تجميده لاحقًا: صفوف المتابعة تبقى، لكنه غير قابل للمتابعة علنًا ومنشوراته تختفي بقاعدة الظهور E28. إلغاء المتابعة مسموح دائمًا.
 */
class CreatorFollowService
{
    public function __construct(protected EngagementGuard $guard) {}

    /** @return array{changed: bool, followers: int} */
    public function follow(User $user, CreatorProfile $creator): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $creator) {
            $locked = CreatorProfile::query()->whereKey($creator->id)->lockForUpdate()->first();
            $creatorFrozen = $locked !== null && (bool) User::query()->whereKey($locked->user_id)->value('is_frozen');

            if ($locked === null || ! $locked->isApproved() || $creatorFrozen) {
                throw new EngagementException('هذا المبدع غير متاح للمتابعة حاليًا.');
            }

            if ((int) $locked->user_id === $user->id) {
                throw new EngagementException('لا يمكنك متابعة نفسك.');
            }

            if (CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $locked->id)->exists()) {
                return ['changed' => false, 'followers' => $this->followers($locked->id)];
            }

            if (CreatorFollow::query()->where('user_id', $user->id)->count() >= (int) config('engagement.follows.max_per_user', 500)) {
                throw new EngagementException('بلغت الحد الأقصى لعدد المبدعين الذين يمكنك متابعتهم.');
            }

            $row = new CreatorFollow;
            $row->forceFill(['user_id' => $user->id, 'creator_profile_id' => $locked->id, 'created_at' => now()])->save();
            EngagementCounters::adjust('creator_profiles', $locked->id, ['followers_count' => 1]);

            return ['changed' => true, 'followers' => $this->followers($locked->id)];
        });
    }

    /** @return array{changed: bool, followers: int} */
    public function unfollow(User $user, CreatorProfile $creator): array
    {
        $this->guard->assertCanInteract($user);

        return DB::transaction(function () use ($user, $creator) {
            $locked = CreatorProfile::query()->whereKey($creator->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['changed' => false, 'followers' => 0];
            }

            $deleted = CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $locked->id)->delete();

            if ($deleted > 0) {
                EngagementCounters::adjust('creator_profiles', $locked->id, ['followers_count' => -1]);
            }

            return ['changed' => $deleted > 0, 'followers' => $this->followers($locked->id)];
        });
    }

    public function isFollowing(User $user, int $creatorProfileId): bool
    {
        return CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', $creatorProfileId)->exists();
    }

    protected function followers(int $creatorProfileId): int
    {
        return (int) DB::table('creator_profiles')->where('id', $creatorProfileId)->value('followers_count');
    }
}
`````

### `app/Services/Engagement/CreatorPublicationAnnouncer.php`

`sha256: 512f9f8fb60d89f47a1fff7b2b033b3b7d14327cf673c66c4415f6da8d30fb40`

`````php
<?php

namespace App\Services\Engagement;

use App\Jobs\DispatchCreatorPublicationChunk;
use App\Models\CreatorFollow;
use App\Models\CreatorPuzzleRevision;
use Throwable;

/**
 * E29 - نقطة الإعلان الوحيدة عن «نشر جديد» لمتابعي المبدع. تُستدعى من CreatorReviewService **بعد** إتمام معاملة الاعتماد (بعد الـcommit).
 *
 *  - فشل الإعلان بأي شكل لا يتراجع عنه الاعتماد (try/catch + report): النشر أهم من الإشعار.
 *  - لا متابعون ← لا Job إطلاقًا (استعلام exists واحد على فهرس المبدع).
 *  - التوزيع نفسه بدفعات بمؤشر id عبر الطابور (DispatchCreatorPublicationChunk): لا حلقة على كل المتابعين ولا داخل معاملة طلب.
 *  - لا إشعار على التقييم أو المفضلة أو اللعب أبدًا؛ إشعار واحد لكل (نسخة منشورة، متابع).
 */
class CreatorPublicationAnnouncer
{
    public function announce(CreatorPuzzleRevision $revision, int $creatorProfileId, bool $isUpdate): void
    {
        try {
            if ($isUpdate && ! (bool) config('engagement.notifications.on_revision', true)) {
                return;
            }

            if (! CreatorFollow::query()->where('creator_profile_id', $creatorProfileId)->exists()) {
                return;
            }

            DispatchCreatorPublicationChunk::dispatch((int) $revision->id, $isUpdate)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/Engagement/EngagementCleanup.php`

`sha256: dad588cc277ebd5cbcecab68f46bbdd7c7e0168a8ffd0343650ba669791fd729`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CreatorFollow;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - عند حذف حساب: بياناته الشخصية الخاصة (مفضلة، متابعات، مجموعات) تُحذف **مع إنقاص العدّادات العامة**، فلا يبقى رقم عام ينسب لمن لم يعد موجودًا.
 * التقييمات تبقى مجهولة (user_id → NULL بقيد القاعدة) لأنها مساهمة في المتوسط لا تحمل هوية: فالمجمَّع صحيح دون إعادة حساب.
 * يُستدعى من مستمع deleting على User (نمط E19 نفسه) ولا يمنع الحذف إن فشل: قيود الحذف التتابعي بالقاعدة تضمن عدم بقاء بيانات شخصية،
 * وأي انحراف عدّاد ينتج يلتقطه community:reconcile-engagement.
 */
class EngagementCleanup
{
    public function purgeUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            CommunityPuzzleFavorite::query()->where('user_id', $user->id)->select('id', 'creator_puzzle_id')->chunkById(500, function ($rows) {
                foreach ($rows->groupBy('creator_puzzle_id') as $workId => $group) {
                    EngagementCounters::adjust('creator_puzzles', (int) $workId, ['favorites_count' => -$group->count()]);
                }

                CommunityPuzzleFavorite::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

            CreatorFollow::query()->where('user_id', $user->id)->select('id', 'creator_profile_id')->chunkById(500, function ($rows) {
                foreach ($rows->groupBy('creator_profile_id') as $profileId => $group) {
                    EngagementCounters::adjust('creator_profiles', (int) $profileId, ['followers_count' => -$group->count()]);
                }

                CreatorFollow::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

            $collectionIds = UserCollection::query()->where('user_id', $user->id)->pluck('id');
            UserCollectionItem::query()->whereIn('user_collection_id', $collectionIds)->delete();
            UserCollection::query()->whereIn('id', $collectionIds)->delete();
        });
    }
}
`````

### `app/Services/Engagement/EngagementCounters.php`

`sha256: 24900d92b118ef0d796770346faffd6c7f92fb33f52482d8c053570ff47d40a9`

`````php
<?php

namespace App\Services\Engagement;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E29 - التحديث الذرّي الوحيد لعدّادات التفاعل المشتقّة. تعبير SQL واحد (col = col + n) لا قراءة-ثم-كتابة، فلا يضيع تحديث تحت التزامن،
 * والنقصان محصور عند الصفر (CASE) فلا يصير سالبًا أبدًا ولو انحرف العدّاد. الأعمدة من قائمة بيضاء ثابتة بالكود (لا مدخلات).
 * تحديث عبر منشئ الاستعلام لا النموذج: لا أحداث نماذج ولا لمس updated_at ولا إعادة اشتقاق is_visible.
 */
final class EngagementCounters
{
    private const ALLOWED = [
        'creator_puzzles' => ['ratings_count', 'ratings_sum', 'favorites_count'],
        'creator_profiles' => ['followers_count'],
    ];

    /** @param array<string, int> $deltas عمود => فرق (موجب أو سالب) */
    public static function adjust(string $table, int $id, array $deltas): void
    {
        $sets = [];

        foreach ($deltas as $column => $delta) {
            if (! in_array($column, self::ALLOWED[$table] ?? [], true)) {
                throw new InvalidArgumentException("Counter {$table}.{$column} is not adjustable.");
            }

            $delta = (int) $delta;

            if ($delta === 0) {
                continue;
            }

            $sets[$column] = DB::raw($delta > 0
                ? "{$column} + {$delta}"
                : "CASE WHEN {$column} >= ".(-$delta)." THEN {$column} - ".(-$delta).' ELSE 0 END');
        }

        if ($sets !== []) {
            DB::table($table)->where('id', $id)->update($sets);
        }
    }
}
`````

### `app/Services/Engagement/EngagementException.php`

`sha256: ba50f1908e26eb813138a0d47deaa7772655b8bd36b5e04bbc1dd278def8e5d6`

`````php
<?php

namespace App\Services\Engagement;

use RuntimeException;

/** E29 - خطأ مجال متوقَّع بالتفاعل (رسالته عربية آمنة للعرض). الأخطاء غير المتوقعة لا تستعمل هذا الصنف. */
class EngagementException extends RuntimeException {}
`````

### `app/Services/Engagement/EngagementGuard.php`

`sha256: bbc772ad2690b41aa4a103797cd5c771e7780ce40da75f28f42ab2ff75f0b386`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;

/**
 * E29 - قواعد الأهلية المشتركة لكل التفاعلات. تقرأ الحالة **من القاعدة لحظة الفعل** (لا من نسخة مستخدم قديمة بالذاكرة):
 * حساب جُمِّد بعد بدء الجلسة لا يتفاعل. التفاعل = مستخدم موثَّق بريده وغير مجمَّد؛ ولا شيء منه يلمس الاقتصاد أو التقدّم.
 */
class EngagementGuard
{
    public function assertCanInteract(User $user): void
    {
        $row = User::query()->whereKey($user->id)->first(['id', 'email_verified_at', 'is_frozen']);

        if ($row === null || $row->email_verified_at === null) {
            throw new EngagementException('وثّق بريدك الإلكتروني أولًا لتتمكن من التفاعل.');
        }

        if ((bool) $row->is_frozen) {
            throw new EngagementException('حسابك مقيَّد مؤقتًا ولا يمكنه التفاعل الآن.');
        }
    }

    /** مالك العمل (user_id) لمنع التفاعل الذاتي: تضخيم الأرقام بنفس صاحب المحتوى. */
    public function ownerId(CreatorPuzzle $work): int
    {
        if ($work->relationLoaded('creator') && $work->creator !== null) {
            return (int) $work->creator->user_id;
        }

        return (int) CreatorProfile::query()->whereKey($work->creator_profile_id)->value('user_id');
    }

    /** قفل صف العمل داخل معاملة: يُسلسل كتابات التفاعل على العمل الواحد ويقرأ is_visible الحيّة. */
    public function lockWork(int $workId): CreatorPuzzle
    {
        $locked = CreatorPuzzle::query()->whereKey($workId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
        }

        return $locked;
    }

    public function assertVisible(CreatorPuzzle $locked): void
    {
        if (! $locked->is_visible) {
            throw new EngagementException('هذه الأحجية غير متاحة حاليًا.');
        }
    }
}
`````

### `app/Services/Engagement/EngagementLists.php`

`sha256: ab5f16c2c3a910f89a939a070f265faca81906fb5ee95f6c41290072c124519f`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Models\UserCollection;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * E29 - قوائم المستخدم (المفضلة، عناصر المجموعة، خلاصة المتابَعين). كلها مرقَّمة بحدود ثابتة وباستعلام قراءة واحد + تحميل مسبق مجمَّع (لا N+1)،
 * وتعيد **كل** الأعمال بما فيها غير الظاهرة (is_visible=false) كي يعرض العرض مكانًا بديلًا «غير متاح حاليًا» دون تسريب عنوانها أو سبب إخفائها.
 * عدا الخلاصة: تعيد الظاهر فقط (ما يُنشر الآن)، وتعيد فحص CreatorPuzzle::visible() بكل قراءة.
 */
class EngagementLists
{
    protected const COLUMNS = [
        'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title',
        'creator_puzzles.published_category_id', 'creator_puzzles.published_difficulty', 'creator_puzzles.published_at', 'creator_puzzles.plays_count',
        'creator_puzzles.ratings_count', 'creator_puzzles.ratings_sum', 'creator_puzzles.favorites_count', 'creator_puzzles.is_visible',
    ];

    protected const WITH = ['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'];

    public function favorites(User $user, int $perPage): LengthAwarePaginator
    {
        return CreatorPuzzle::query()
            ->join('community_puzzle_favorites as f', 'f.creator_puzzle_id', '=', 'creator_puzzles.id')
            ->where('f.user_id', $user->id)
            ->select(array_merge(self::COLUMNS, ['f.id as favorite_id']))
            ->with(self::WITH)
            ->orderByDesc('f.id')
            ->paginate($perPage)->withQueryString();
    }

    public function collectionItems(UserCollection $collection, int $perPage): LengthAwarePaginator
    {
        return CreatorPuzzle::query()
            ->join('user_collection_items as i', 'i.creator_puzzle_id', '=', 'creator_puzzles.id')
            ->where('i.user_collection_id', $collection->id)
            ->select(array_merge(self::COLUMNS, ['i.id as item_id']))
            ->with(self::WITH)
            ->orderByDesc('i.id')
            ->paginate($perPage)->withQueryString();
    }

    /** أحدث منشورات المبدعين الذين يتابعهم المستخدم: ظاهر الآن فقط، الأحدث نشرًا أولًا ثم id كمفتاح ثانوي ثابت، بمؤشر (cursor) مستقر عند النشر الجديد. */
    public function following(User $user, int $perPage): CursorPaginator
    {
        return CreatorPuzzle::query()->visible()
            ->whereIn('creator_puzzles.creator_profile_id', fn ($q) => $q->select('creator_profile_id')->from('creator_follows')->where('user_id', $user->id))
            ->select(self::COLUMNS)
            ->with(self::WITH)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->cursorPaginate($perPage)->withQueryString();
    }
}
`````

### `app/Services/Engagement/EngagementReconciler.php`

`sha256: 46c80d9e3371cf37e3101e6152c42a622c10fb9c55af5cd2cb0a3767b91d5e38`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use Illuminate\Support\Facades\DB;

/**
 * E29 - مصالحة العدّادات المشتقّة مع صفوف التفاعل (المصدر الوحيد للحقيقة). على الطلب فقط، لا جدولة دقيقة ولا مسح كامل مجدول.
 *
 *  - يمرّ بالأعمال والمبدعين على دفعات بمؤشر id (ذاكرة ثابتة)، وبكل دفعة 2–3 استعلامات تجميع محصورة بمعرّفات الدفعة.
 *  - dry-run: يكشف الانحراف فقط. الإصلاح: يعيد حساب الصف **تحت قفله** من الصفوف المصدر ويكتب القيم المطلقة (لا يتعارض مع كتابات حيّة).
 *  - لا يلمس أي بيانات تفاعل ولا الاقتصاد: يكتب أعمدة العدّادات حصرًا.
 */
class EngagementReconciler
{
    /** @return array{works: int, creators: int, drift: list<array{table: string, id: int, column: string, stored: int, actual: int}>, fixed: int} */
    public function run(bool $dryRun = true, int $chunk = 500): array
    {
        $chunk = max(10, $chunk);
        $drift = [];
        $fixed = 0;
        $works = 0;
        $creators = 0;

        CreatorPuzzle::query()->select('id', 'ratings_count', 'ratings_sum', 'favorites_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$works, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $works += count($ids);

            $ratings = DB::table('community_puzzle_ratings')->whereIn('creator_puzzle_id', $ids)->groupBy('creator_puzzle_id')
                ->selectRaw('creator_puzzle_id as id, COUNT(*) as c, COALESCE(SUM(rating), 0) as s')->get()->keyBy('id');
            $favorites = DB::table('community_puzzle_favorites')->whereIn('creator_puzzle_id', $ids)->groupBy('creator_puzzle_id')
                ->selectRaw('creator_puzzle_id as id, COUNT(*) as c')->get()->keyBy('id');

            foreach ($rows as $row) {
                $actual = [
                    'ratings_count' => (int) ($ratings[$row->id]->c ?? 0),
                    'ratings_sum' => (int) ($ratings[$row->id]->s ?? 0),
                    'favorites_count' => (int) ($favorites[$row->id]->c ?? 0),
                ];

                $rowDrift = false;

                foreach ($actual as $column => $value) {
                    if ((int) $row->{$column} !== $value) {
                        $drift[] = ['table' => 'creator_puzzles', 'id' => (int) $row->id, 'column' => $column, 'stored' => (int) $row->{$column}, 'actual' => $value];
                        $rowDrift = true;
                    }
                }

                if ($rowDrift && ! $dryRun) {
                    $this->repairWork((int) $row->id);
                    $fixed++;
                }
            }
        });

        CreatorProfile::query()->select('id', 'followers_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$creators, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $creators += count($ids);

            $follows = DB::table('creator_follows')->whereIn('creator_profile_id', $ids)->groupBy('creator_profile_id')
                ->selectRaw('creator_profile_id as id, COUNT(*) as c')->get()->keyBy('id');

            foreach ($rows as $row) {
                $actual = (int) ($follows[$row->id]->c ?? 0);

                if ((int) $row->followers_count !== $actual) {
                    $drift[] = ['table' => 'creator_profiles', 'id' => (int) $row->id, 'column' => 'followers_count', 'stored' => (int) $row->followers_count, 'actual' => $actual];

                    if (! $dryRun) {
                        $this->repairCreator((int) $row->id);
                        $fixed++;
                    }
                }
            }
        });

        return ['works' => $works, 'creators' => $creators, 'drift' => $drift, 'fixed' => $fixed];
    }

    protected function repairWork(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('creator_puzzles')->where('id', $id)->lockForUpdate()->first(['id']);

            $r = DB::table('community_puzzle_ratings')->where('creator_puzzle_id', $id)->selectRaw('COUNT(*) as c, COALESCE(SUM(rating), 0) as s')->first();
            $f = DB::table('community_puzzle_favorites')->where('creator_puzzle_id', $id)->count();

            DB::table('creator_puzzles')->where('id', $id)->update(['ratings_count' => (int) $r->c, 'ratings_sum' => (int) $r->s, 'favorites_count' => (int) $f]);
        });
    }

    protected function repairCreator(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('creator_profiles')->where('id', $id)->lockForUpdate()->first(['id']);
            DB::table('creator_profiles')->where('id', $id)->update(['followers_count' => DB::table('creator_follows')->where('creator_profile_id', $id)->count()]);
        });
    }
}
`````

### `app/Services/Engagement/EngagementViewerState.php`

`sha256: 107d0378314a366b54eb58e15dad037e7241cb0723ba3fba72345d3679b87a13`

`````php
<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - حالة المشاهد (تقييمي/محفوظ) لقائمة أعمال بدفعة استعلامات **ثابتة العدد** (لا exists() لكل بطاقة).
 * خاصة بالمستخدم دائمًا (WHERE user_id): لا تُخزَّن بكاش عام ولا تُشارَك بين المستخدمين.
 */
class EngagementViewerState
{
    /** @param list<int> $workIds @return array<int, true> معرّفات الأعمال التي حفظها المستخدم */
    public function favoriteIds(?User $user, array $workIds): array
    {
        if ($user === null || $workIds === []) {
            return [];
        }

        return array_fill_keys(
            CommunityPuzzleFavorite::query()->where('user_id', $user->id)->whereIn('creator_puzzle_id', $workIds)->pluck('creator_puzzle_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
    }

    /** @param list<int> $workIds @return array<int, int> عمل => تقييم المستخدم */
    public function ratings(?User $user, array $workIds): array
    {
        if ($user === null || $workIds === []) {
            return [];
        }

        return CommunityPuzzleRating::query()->where('user_id', $user->id)->whereIn('creator_puzzle_id', $workIds)->pluck('rating', 'creator_puzzle_id')
            ->map(fn ($r) => (int) $r)->all();
    }

    /**
     * حالة المشاهد لعمل واحد باستعلام **واحد** (ثلاث استعلامات فرعية): تقييمي الحالي، هل حفظته، هل جرّبته (شرط أهلية التقييم).
     * «جرّبته» = محاولة واحدة على الأقل على العمل الثابت (creator_puzzle_id) عبر كل نسخه، فتعديل المبدع لا يلغي أهلية من لعب سابقًا.
     *
     * @return array{rating: int|null, favorite: bool, played: bool}
     */
    public function forWork(?User $user, int $workId): array
    {
        if ($user === null) {
            return ['rating' => null, 'favorite' => false, 'played' => false];
        }

        $row = DB::query()
            ->selectSub(DB::table('community_puzzle_ratings')->select('rating')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'rating')
            ->selectSub(DB::table('community_puzzle_favorites')->selectRaw('1')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'favorite')
            ->selectSub(DB::table('community_puzzle_attempts')->selectRaw('1')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'played')
            ->first();

        return [
            'rating' => $row?->rating === null ? null : (int) $row->rating,
            'favorite' => $row?->favorite !== null,
            'played' => $row?->played !== null,
        ];
    }
}
`````

### `tests/Feature/EngagementE29/E29AnalyticsUiTest.php`

`sha256: 9aa33a31fa6930a1644142f4969390b4f3ea7e93d48eaa06a766526c4f850f93`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\UserCollection;
use App\Services\Creators\CreatorAnalyticsService;
use Database\Seeders\DemoEngagementSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_stats(\App\Models\User $creator): array
{
    Cache::flush();

    return app(CreatorAnalyticsService::class)->forCreator(e29_profile($creator));
}

test('AA12-121: creator analytics expose followers, favorites received, ratings, average and top work (aggregates only)', function () {
    config(['engagement.top_rated.min_ratings' => 3, 'engagement.ratings.display_min' => 3]);
    $creator = e28_creator();
    $best = e29_work($creator, ['title' => 'أفضل أعمالي']);
    $other = e29_work($creator, ['title' => 'عمل آخر']);
    e29_ratings($best, [5, 5, 4]);
    e29_ratings($other, [3]);
    e29_favorite(e16User(['name' => 'اسم لا يجب أن يظهر']), $best);
    e29_follow(e16User(), $creator);
    e29_follow(e16User(), $creator);

    $stats = e29_stats($creator);

    expect($stats['followers'])->toBe(2)->and($stats['favorites_received'])->toBe(1)->and($stats['ratings_count'])->toBe(4)->and($stats['average_rating'])->toBe(4.3)
        ->and($stats['top_rated_work']['title'])->toBe('أفضل أعمالي')->and($stats['top_rated_work']['count'])->toBe(3);
    expect(json_encode($stats))->not->toContain('اسم لا يجب أن يظهر')->and(json_encode($stats))->not->toContain('@');
});

test('AA12-122: analytics hide the average below the minimum sample and ignore hidden works', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 5]);

    $stats = e29_stats($creator);
    expect($stats['average_rating'])->toBeNull()->and($stats['ratings_count'])->toBe(2)->and($stats['top_rated_work'])->toBeNull();

    e29_ratings($work, [5, 5, 5]);
    expect(e29_stats($creator)['average_rating'])->toBe(5.0);

    e29_hide($work);
    $hidden = e29_stats($creator);
    expect($hidden['ratings_count'])->toBe(0)->and($hidden['favorites_received'])->toBe(0);
});

test('AA12-123: analytics reuse the E28 cache (second read costs zero queries) and the dashboard renders them', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_follow(e16User(), $creator);
    e29_ratings($work, [4, 4, 4]);
    Cache::flush();

    $profile = e29_profile($creator);
    $first = app(CreatorAnalyticsService::class)->forCreator($profile);
    $queries = e27_queries(fn () => app(CreatorAnalyticsService::class)->forCreator($profile));

    expect($queries)->toBe(0)->and($first['followers'])->toBe(1);
    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk()->assertSee('متابعون')->assertSee('حفظوا أعمالك')->assertSee('متوسط التقييم')->assertSee('4.0 من 5');
});

test('AA12-124: the admin summary aggregates follows, ratings, favorites, collections, top rated and most followed (cached, no identities)', function () {
    config(['engagement.top_rated.min_ratings' => 3]);
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'الأعلى تقييمًا للإدارة']);
    e29_ratings($work, [5, 5, 4]);
    e29_favorite($fan = e16User(['name' => 'معجب خاص']), $work);
    e29_follow($fan, $creator);
    e29_collection($fan, 'مجموعة المعجب');
    Cache::flush();

    $s = app(CreatorAnalyticsService::class)->adminSummary();

    expect($s['total_follows'])->toBe(1)->and($s['total_ratings'])->toBe(3)->and($s['total_favorites'])->toBe(1)->and($s['total_collections'])->toBe(1)
        ->and($s['top_rated'][0]['title'])->toBe('الأعلى تقييمًا للإدارة')->and($s['most_followed'][0]['followers'])->toBe(1);
    expect(json_encode($s))->not->toContain('معجب خاص')->and(e27_queries(fn () => app(CreatorAnalyticsService::class)->adminSummary()))->toBe(0);
});

test('AA12-125: the creator page shows the quality summary only past the minimum, and follow states per viewer', function () {
    config(['engagement.creator_quality.min_ratings' => 5]);
    $creator = e28_creator();
    $slug = e29_profile($creator)->slug;
    $work = e29_work($creator);
    e29_ratings($work, [5, 5, 5, 5]);

    expect($this->get(route('creators.show', $slug))->assertOk()->getContent())->not->toContain('متوسط تقييم أعماله')->toContain('سجّل الدخول للمتابعة')->toContain('0 متابعين');

    e29_ratings($work, [4]);
    $html = $this->get(route('creators.show', $slug))->assertOk()->getContent();
    expect($html)->toContain('متوسط تقييم أعماله')->toContain('4.8 من 5');

    $viewer = e16User();
    expect($this->actingAs($viewer)->get(route('creators.show', $slug))->getContent())->toContain(route('creators.follow', $slug))->not->toContain('إلغاء متابعة هذا المبدع')->not->toContain('تتابعه');
    e29_follow($viewer, $creator);
    expect($this->actingAs($viewer)->get(route('creators.show', $slug))->getContent())->toContain('إلغاء متابعة هذا المبدع')->toContain('تتابعه')->toContain('1 متابع');
    $self = $this->actingAs($creator)->get(route('creators.show', $slug))->getContent();
    expect($self)->not->toContain(route('creators.follow', $slug))->not->toContain('سجّل الدخول للمتابعة');
    expect($this->actingAs(e16User(['email_verified_at' => null]))->get(route('creators.show', $slug))->getContent())->toContain('وثّق بريدك الإلكتروني لتتمكن من المتابعة');
});

test('AA12-126: a small sample is never marketed as a 5.0 average', function () {
    $work = e29_work();
    e29_ratings($work, [5]);

    $show = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    $index = $this->get(route('community.puzzles.index'))->assertOk()->getContent();

    foreach ([$show, $index] as $html) {
        expect($html)->not->toContain('متوسط التقييم')->toContain('1 تقييم');
    }

    e29_ratings($work, [5, 5]);
    expect($this->get(route('community.puzzles.show', $work->public_id))->getContent())->toContain('متوسط التقييم 5.0 من 5 بحسب 3 تقييم');
});

test('AA12-127: the rating control is a keyboard-accessible radio group with a legend and text labels (not colour alone)', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);
    e29_rate($viewer, $work, 3);

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    $panel = e29_section($html, 'رأي المجتمع') ?: $html;

    expect($html)->toContain('<legend')->toContain('تقييمك لهذه الأحجية')->toContain('role="radiogroup"')->toContain('dir="rtl"')
        ->and(substr_count($html, 'class="peer sr-only"'))->toBe(5)
        ->and($html)->toContain('3 من 5')->toContain('تقييمك الحالي: 3 من 5')->toContain('peer-focus-visible:ring-2')
        ->and(preg_match_all('/<input type="radio" name="rating" id="rating-\d" value="\d"[^>]*checked/', $html))->toBe(1);
});

test('AA12-128: a user who has not played sees the gate message instead of the stars', function () {
    $work = e29_work();
    $viewer = e16User();

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect($html)->toContain('جرّب الأحجية ولو بمحاولة واحدة')->not->toContain('role="radiogroup"');
});

test('AA12-129: the owner and unverified users see no interaction controls on the puzzle page', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $owner = $this->actingAs($creator)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($owner)->toContain('هذه أحجيتك؛ لا يمكنك تقييمها')->not->toContain('role="radiogroup"')->not->toContain('احفظ في المفضلة');

    $unverified = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($unverified)->toContain('يلزم بريد إلكتروني موثَّق')->not->toContain('role="radiogroup"');
});

test('AA12-130: the community and discover pages offer the private-list entries only to verified users', function () {
    $guest = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($guest)->not->toContain(route('following.index'));
    $user = $this->actingAs(e16User())->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($user)->toContain(route('following.index'))->toContain(route('favorites.index'))->toContain(route('collections.index'));
    $disc = $this->actingAs(e16User())->get(route('discover.index'))->assertOk()->getContent();
    expect($disc)->toContain(route('following.index'))->toContain(route('collections.index'));
});

test('AA12-131: a saved work is marked on the card for its viewer only', function () {
    $work = e29_work(null, ['title' => 'عمل للعلامة']);
    $viewer = e16User();
    e29_favorite($viewer, $work);

    expect($this->actingAs($viewer)->get(route('community.puzzles.index'))->getContent())->toContain('محفوظة');
    $other = $this->actingAs(e16User())->get(route('community.puzzles.index'))->getContent();
    expect(e29_section($other, 'أحجيات المجتمع'))->not->toContain('محفوظة');
});

test('AA13-132: the demo seeder is idempotent, uses only official services, keeps counters exact and touches no economy', function () {
    $this->seed(DemoEngagementSeeder::class);
    $snap = fn () => [CommunityPuzzleRating::query()->count(), CommunityPuzzleFavorite::query()->count(), CreatorFollow::query()->count(), UserCollection::query()->count(), \App\Models\UserCollectionItem::query()->count(), \App\Models\User::query()->count()];
    $first = $snap();

    $this->seed(DemoEngagementSeeder::class);

    expect($snap())->toBe($first)->and($first[0])->toBeGreaterThan(0)->and($first[1])->toBeGreaterThan(0)->and($first[2])->toBeGreaterThan(0)->and($first[3])->toBeGreaterThanOrEqual(2)
        ->and(\App\Models\XpTransaction::query()->count())->toBe(0)->and(\App\Models\GemTransaction::query()->count())->toBe(0)->and(\App\Models\PuzzleAttempt::query()->count())->toBe(0);
    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0);
});

test('AA13-133: the engagement demo seeder refuses to run in production', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => app(DemoEngagementSeeder::class)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA13-134: the engagement indexes and unique constraints exist (schema contract)', function () {
    $indexes = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexes('community_puzzle_ratings'))->toContain('community_ratings_user_work_unique')
        ->and($indexes('community_puzzle_favorites'))->toContain('community_favorites_user_work_unique')
        ->and($indexes('creator_follows'))->toContain('creator_follows_user_creator_unique')->toContain('creator_follows_fanout_index')
        ->and($indexes('user_collection_items'))->toContain('collection_items_collection_work_unique')
        ->and($indexes('creator_puzzles'))->toContain('creator_puzzles_rated_visible_index')
        ->and($indexes('creator_profiles'))->toContain('creator_profiles_followed_index');
});
`````

### `tests/Feature/EngagementE29/E29CollectionTest.php`

`sha256: 6ca2e55a15d31279e75489532bfbfbf740a37ed164ed2ff74d88a938c419aca4`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Services\Engagement\CollectionService;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA6-59: a verified user creates a private collection with a ULID public id', function () {
    $user = e16User();

    $this->actingAs($user)->post(route('collections.store'), ['title' => 'ألغاز المساء', 'description' => 'مجموعة هادئة.'])->assertRedirect();

    $c = UserCollection::query()->where('user_id', $user->id)->firstOrFail();
    expect($c->title)->toBe('ألغاز المساء')->and(strlen($c->public_id))->toBe(26)->and(\Illuminate\Support\Str::isUlid($c->public_id))->toBeTrue();
});

test('AA6-60: the title is validated (required, min, max)', function () {
    $user = e16User();

    foreach (['', ' ', 'أ', str_repeat('ب', 61)] as $bad) {
        $this->actingAs($user)->post(route('collections.store'), ['title' => $bad])->assertSessionHasErrors('title');
    }

    $this->actingAs($user)->post(route('collections.store'), ['title' => 'عنوان صالح', 'description' => str_repeat('و', 301)])->assertSessionHasErrors('description');
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-61: markup in the title or description is rejected', function () {
    $user = e16User();

    $this->actingAs($user)->post(route('collections.store'), ['title' => '<script>alert(1)</script>'])->assertSessionHasErrors('title');
    $this->actingAs($user)->post(route('collections.store'), ['title' => 'عنوان', 'description' => '<img src=x onerror=alert(1)>'])->assertSessionHasErrors('description');
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-62: a collection is private (guests are sent to login)', function () {
    $owner = e16User();
    $c = e29_collection($owner);

    $this->get(route('collections.index'))->assertRedirect(route('login'));
    $this->get(route('collections.show', $c->public_id))->assertRedirect(route('login'));
    $this->get(route('collections.edit', $c->public_id))->assertRedirect(route('login'));
});

test('AA6-63: the owner can view the collection and its items', function () {
    $owner = e16User();
    $c = e29_collection($owner, 'مجموعة العرض');
    $work = e29_work(null, ['title' => 'عنصر داخل المجموعة']);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->get(route('collections.index'))->assertOk()->assertSee('مجموعة العرض');
    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('عنصر داخل المجموعة')->assertSee('إزالة من المجموعة');
});

test('AA6-64: another user is denied every collection route (404, nothing changes)', function () {
    $owner = e16User();
    $other = e16User();
    $c = e29_collection($owner, 'خاصة بصاحبها');
    $work = e29_work();

    $this->actingAs($other)->get(route('collections.show', $c->public_id))->assertNotFound();
    $this->actingAs($other)->get(route('collections.edit', $c->public_id))->assertNotFound();
    $this->actingAs($other)->put(route('collections.update', $c->public_id), ['title' => 'اختطاف العنوان'])->assertNotFound();
    $this->actingAs($other)->delete(route('collections.destroy', $c->public_id))->assertNotFound();
    $this->actingAs($other)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertNotFound();

    expect(UserCollection::query()->find($c->id)->title)->toBe('خاصة بصاحبها')->and(UserCollectionItem::query()->count())->toBe(0);
});

test('AA6-65: the owner updates title and description', function () {
    $owner = e16User();
    $c = e29_collection($owner);

    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان جديد', 'description' => 'وصف جديد'])->assertRedirect(route('collections.show', $c->public_id));

    expect($c->fresh()->title)->toBe('عنوان جديد')->and($c->fresh()->description)->toBe('وصف جديد')->and($c->fresh()->public_id)->toBe($c->public_id);
});

test('AA6-66: deleting a collection deletes memberships only, never works, favorites or ratings', function () {
    $owner = e29_player_who_played($work = e29_work());
    e29_rate($owner, $work, 4);
    e29_favorite($owner, $work);
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->delete(route('collections.destroy', $c->public_id))->assertRedirect(route('collections.index'));

    expect(UserCollection::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(CreatorPuzzleExists($work))->toBeTrue()->and(CommunityPuzzleFavorite::query()->count())->toBe(1)->and(CommunityPuzzleRating::query()->count())->toBe(1)
        ->and(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 1]);
});

function CreatorPuzzleExists($work): bool
{
    return \App\Models\CreatorPuzzle::query()->whereKey($work->id)->exists();
}

test('AA6-67: adding a puzzle to an existing collection or to a new one from the puzzle page', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'قائمة');

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertRedirect()->assertSessionHas('engagement_status');
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['new_title' => 'قائمة سريعة جديدة'])->assertRedirect();

    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(1)
        ->and(UserCollection::query()->where('user_id', $owner->id)->where('title', 'قائمة سريعة جديدة')->first()->items()->count())->toBe(1);
});

test('AA6-68: a duplicate item is prevented (service and database)', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id]);
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertRedirect();

    expect(UserCollectionItem::query()->count())->toBe(1);
    expect(fn () => (new UserCollectionItem)->forceFill(['user_collection_id' => $c->id, 'creator_puzzle_id' => $work->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA6-69: removing a puzzle from a collection works', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertRedirect(route('collections.show', $c->public_id));

    expect(UserCollectionItem::query()->count())->toBe(0);
});

test('AA6-70: the per-collection item cap is enforced', function () {
    config(['engagement.collections.max_items' => 2]);
    $owner = e16User();
    $c = e29_collection($owner);
    $creator = e28_creator();
    [$a, $b, $d] = [e29_work($creator), e29_work($creator), e29_work($creator)];
    $svc = app(CollectionService::class);

    $svc->add($owner, $c->public_id, $a->fresh());
    $svc->add($owner, $c->public_id, $b->fresh());
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $d->public_id), ['collection' => $c->public_id])->assertSessionHas('error');

    expect(UserCollectionItem::query()->count())->toBe(2);
});

test('AA6-71: the per-user collection cap is enforced', function () {
    config(['engagement.collections.max_per_user' => 2]);
    $owner = e16User();

    e29_collection($owner, 'الأولى');
    e29_collection($owner, 'الثانية');
    $this->actingAs($owner)->post(route('collections.store'), ['title' => 'الثالثة المرفوضة'])->assertSessionHas('error');

    expect(UserCollection::query()->where('user_id', $owner->id)->count())->toBe(2);
});

test('AA6-72: hiding a work preserves the membership; restoring brings it back', function () {
    $owner = e16User();
    $work = e29_work(null, ['title' => 'عنوان عضوية مخفية']);
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    e29_hide($work);
    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect(UserCollectionItem::query()->count())->toBe(1)->and($html)->toContain('هذا المحتوى غير متاح حاليًا')->and($html)->not->toContain('عنوان عضوية مخفية')->and($html)->not->toContain('مخالفة تجريبية');

    e29_unhide($work);
    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('عنوان عضوية مخفية')->assertDontSee('هذا المحتوى غير متاح حاليًا');
});

test('AA6-73: a hidden item cannot be played through its collection', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());
    e29_hide($work);

    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect(e29_section($html, 'أحجيات المجموعة'))->not->toContain(route('community.puzzles.show', $work->public_id));
    $this->actingAs($owner)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs($owner)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $other = e16User();
    expect(fn () => app(CollectionService::class)->add($other, e29_collection($other)->public_id, $work->fresh()))->toThrow(\App\Services\Engagement\EngagementException::class);
});

test('AA6-74: no public collection route exists and every collection route sits behind auth', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'collections') || str_contains($r->uri(), '/collections'));

    expect($routes->count())->toBeGreaterThan(5);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth');
    }

    $owner = e16User();
    $c = e29_collection($owner, 'اسم فريد لمجموعة خاصة');
    $this->get('/collections/'.$c->public_id)->assertRedirect(route('login'));
    $this->get('/c/'.$c->public_id)->assertNotFound();
    $this->get(route('creators.show', 'x'.$c->public_id))->assertNotFound();
});

test('AA6-75: the cap/quick-create flow cannot leave an empty collection behind when the add fails', function () {
    $owner = e16User();
    $work = e29_work();
    e29_hide($work);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['new_title' => 'لا يجب أن تبقى'])->assertNotFound();
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-76: the add form needs exactly one of an existing collection or a new title', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), [])->assertSessionHasErrors('collection');
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id, 'new_title' => 'كلاهما'])->assertSessionHasErrors('collection');
    expect(UserCollectionItem::query()->count())->toBe(0);
});
`````

### `tests/Feature/EngagementE29/E29FavoriteTest.php`

`sha256: 7003d543e28a00125a674a3f981417d798510bfca8c0d82736a409d785d30817`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA2-21: a guest cannot favorite', function () {
    $work = e29_work();

    $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect(route('login'));
    $this->get(route('favorites.index'))->assertRedirect(route('login'));
    expect(CommunityPuzzleFavorite::query()->count())->toBe(0);
});

test('AA2-22: a verified user can favorite a visible community puzzle', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect()->assertSessionHas('engagement_status');

    expect(CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $work->id)->count())->toBe(1)
        ->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA2-23: a duplicate favorite is safe and idempotent', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id));
    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
    expect(fn () => (new CommunityPuzzleFavorite)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA2-24: unfavorite removes the row and the count (idempotent)', function () {
    $work = e29_work();
    $user = e16User();
    e29_favorite($user, $work);

    $this->actingAs($user)->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertRedirect();
    $this->actingAs($user)->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(e29_counters($work)['favorites'])->toBe(0);
});

test('AA2-25: the public favorites count equals the number of saves and shows no identities', function () {
    $work = e29_work();
    $users = [e16User(['name' => 'حافظ سري واحد']), e16User(['name' => 'حافظ سري اثنان']), e16User(['name' => 'حافظ سري ثلاثة'])];

    foreach ($users as $u) {
        e29_favorite($u, $work);
    }

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect(e29_counters($work)['favorites'])->toBe(3)->and($html)->toContain('3 حفظوها')
        ->and($html)->not->toContain('حافظ سري')->and($html)->not->toContain($users[0]->email);
});

test('AA2-26: the creator cannot favorite their own work (conservative rule)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $this->actingAs($creator)->post(route('community.puzzles.favorite.store', $work->public_id))->assertSessionHas('error');

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(e29_counters($work)['favorites'])->toBe(0);
});

test('AA2-27: the favorites page is private to its owner (newest saved first, empty state with CTA)', function () {
    $creator = e28_creator();
    $first = e29_work($creator, ['title' => 'أحجية محفوظة أولًا']);
    $second = e29_work($creator, ['title' => 'أحجية محفوظة ثانيًا']);
    $user = e16User();

    $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertSee('لم تحفظ أي أحجية بعد')->assertSee('تصفّح أحجيات المجتمع');

    e29_favorite($user, $first);
    e29_favorite($user, $second);

    $html = $this->actingAs($user)->get(route('favorites.index'))->assertOk()->getContent();
    expect(strpos($html, 'أحجية محفوظة ثانيًا'))->toBeLessThan(strpos($html, 'أحجية محفوظة أولًا'));
});

test('AA2-28: another user can never see someone else\'s favorites list', function () {
    $work = e29_work(null, ['title' => 'عنوان خاص بمفضلة أ']);
    $a = e16User();
    $b = e16User();
    e29_favorite($a, $work);

    $this->actingAs($b)->get(route('favorites.index'))->assertOk()->assertDontSee('عنوان خاص بمفضلة أ');
    $this->actingAs($b)->get(route('favorites.index', ['user' => $a->id, 'user_id' => $a->id]))->assertOk()->assertDontSee('عنوان خاص بمفضلة أ');

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'favorites'))->values()->all();
    expect($uris)->toBe(['favorites']);
});

test('AA2-29: a hidden favorite is not playable and shows only a neutral placeholder', function () {
    $work = e29_work(null, ['title' => 'عنوان سيُخفى إشرافيًا']);
    $user = e16User();
    e29_favorite($user, $work);
    e29_hide($work);

    $html = $this->actingAs($user)->get(route('favorites.index'))->assertOk()->getContent();
    expect($html)->toContain('هذا المحتوى غير متاح حاليًا')->and($html)->not->toContain('عنوان سيُخفى إشرافيًا')->and($html)->not->toContain('مخالفة تجريبية');

    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs($user)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
});

test('AA2-30: hiding keeps the favorite row and the public counter', function () {
    $work = e29_work();
    $user = e16User();
    e29_favorite($user, $work);

    e29_hide($work);

    expect(CommunityPuzzleFavorite::query()->where('user_id', $user->id)->count())->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA2-31: restoring a hidden work brings it back into the favorites list', function () {
    $work = e29_work(null, ['title' => 'تعود بعد الاستعادة']);
    $user = e16User();
    e29_favorite($user, $work);
    e29_hide($work);
    $this->actingAs($user)->get(route('favorites.index'))->assertDontSee('تعود بعد الاستعادة');

    e29_unhide($work);

    $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertSee('تعود بعد الاستعادة')->assertDontSee('هذا المحتوى غير متاح حاليًا');
});

test('AA2-32: a favorite grants zero rewards, XP, quests or streak', function () {
    $work = e29_work();
    $user = e16User();
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();   // يثبّت صف التقدّم الكسول قبل اللقطة
    $before = e28_world($user);

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect();
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();

    expect(e28_world($user))->toBe($before);
});
`````

### `tests/Feature/EngagementE29/E29FollowTest.php`

`sha256: b67883befd80e6450754eb071820b14fd8c68ca746485a7b0c18cab1764f0ba3`

`````php
<?php

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementException;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_slug(\App\Models\User $creator): string
{
    return $creator->creatorProfile()->value('slug');
}

test('AA3-33: a verified user follows an approved creator', function () {
    $creator = e28_creator();
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)))->assertRedirect()->assertSessionHas('engagement_status');

    expect(CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', e29_profile($creator)->id)->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
});

test('AA3-34: a creator cannot follow themselves', function () {
    $creator = e28_creator();

    $this->actingAs($creator)->post(route('creators.follow', e29_slug($creator)))->assertSessionHas('error');

    expect(CreatorFollow::query()->count())->toBe(0)->and(e29_followers($creator))->toBe(0);
});

test('AA3-35: a pending creator cannot be followed (404, nothing stored)', function () {
    $applicant = e16User();
    $profile = app(CreatorApplicationService::class)->apply($applicant, 'مبدع بانتظار الاعتماد ويحب الألغاز الذهنية كثيرًا.');
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', $profile->slug))->assertNotFound();
    expect(CreatorFollow::query()->count())->toBe(0);
    expect(fn () => app(CreatorFollowService::class)->follow($user, $profile))->toThrow(EngagementException::class);
});

test('AA3-36: a suspended creator cannot be newly followed, existing follows are kept and hidden from the feed', function () {
    $creator = e28_creator();
    e29_work($creator);
    $follower = e16User();
    e29_follow($follower, $creator);

    $admin = e28_reviewer('administrator');
    app(CreatorApplicationService::class)->suspend(e29_profile($creator), $admin, 'تعليق تجريبي للاختبار فقط.');

    $newcomer = e16User();
    $this->actingAs($newcomer)->post(route('creators.follow', e29_slug($creator)))->assertNotFound();
    expect(CreatorFollow::query()->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
    $this->actingAs($follower)->get(route('following.index'))->assertOk()->assertSee('لا جديد الآن');
});

test('AA3-37: unfollow works and is idempotent', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);

    $this->actingAs($user)->delete(route('creators.unfollow', e29_slug($creator)))->assertRedirect();
    $this->actingAs($user)->delete(route('creators.unfollow', e29_slug($creator)))->assertRedirect();

    expect(CreatorFollow::query()->count())->toBe(0)->and(e29_followers($creator))->toBe(0);
});

test('AA3-38: a duplicate follow is safe', function () {
    $creator = e28_creator();
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)));
    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)))->assertRedirect();

    expect(CreatorFollow::query()->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
    expect(fn () => (new CreatorFollow)->forceFill(['user_id' => $user->id, 'creator_profile_id' => e29_profile($creator)->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA3-39: followers_count equals the follow rows through follow and unfollow', function () {
    $creator = e28_creator();
    [$a, $b, $c] = [e16User(), e16User(), e16User()];

    e29_follow($a, $creator);
    e29_follow($b, $creator);
    e29_follow($c, $creator);
    expect(e29_followers($creator))->toBe(3);

    app(CreatorFollowService::class)->unfollow($b, e29_profile($creator));
    expect(e29_followers($creator))->toBe(2)->and(CreatorFollow::query()->where('creator_profile_id', e29_profile($creator)->id)->count())->toBe(2);
});

test('AA3-40: follower identities are never public (only the aggregate count)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $follower = e16User(['name' => 'متابع سري للغاية']);
    e29_follow($follower, $creator);

    foreach ([$this->get(route('creators.show', e29_slug($creator))), $this->actingAs($creator)->get(route('creators.show', e29_slug($creator))), $this->get(route('community.puzzles.show', $work->public_id))] as $response) {
        $html = $response->assertOk()->getContent();
        expect($html)->not->toContain('متابع سري للغاية')->and($html)->not->toContain($follower->email);
    }

    $html = $this->get(route('creators.show', e29_slug($creator)))->getContent();
    expect($html)->toContain('1 متابع');
});

test('AA3-41: the following list is private to its owner', function () {
    $creator = e28_creator();
    e29_work($creator, ['title' => 'منشور من مبدع يتابعه أ']);
    $a = e16User();
    $b = e16User();
    e29_follow($a, $creator);

    $this->get(route('following.index'))->assertRedirect(route('login'));
    $this->actingAs($a)->get(route('following.index'))->assertOk()->assertSee('منشور من مبدع يتابعه أ');
    $this->actingAs($b)->get(route('following.index', ['user_id' => $a->id]))->assertOk()->assertDontSee('منشور من مبدع يتابعه أ')->assertSee('لا تتابع أي مبدع بعد');
});

test('AA3-42: a frozen user cannot follow or unfollow', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $user->forceFill(['is_frozen' => true])->save();

    $this->actingAs($user->fresh())->delete(route('creators.unfollow', e29_slug($creator)));
    $this->actingAs($user->fresh())->post(route('creators.follow', e29_slug(e28_creator())));

    expect(CreatorFollow::query()->count())->toBe(1);
    expect(fn () => app(CreatorFollowService::class)->unfollow($user->fresh(), e29_profile($creator)))->toThrow(EngagementException::class);
    expect(fn () => e29_follow($user->fresh(), e28_creator()))->toThrow(EngagementException::class);
});

// ───────────── AA4: الخلاصة ─────────────

function e29_publish_at(\App\Models\CreatorPuzzle $work, string $when): void
{
    \Illuminate\Support\Facades\DB::table('creator_puzzles')->where('id', $work->id)->update(['published_at' => $when]);
}

test('AA4-43: a followed creator\'s publication appears in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator, ['title' => 'عمل جديد للمتابَع']);

    $this->actingAs($user)->get(route('following.index'))->assertOk()->assertSee('عمل جديد للمتابَع')->assertSee('من المجتمع');
});

test('AA4-44: an unfollowed creator\'s publication does not appear', function () {
    $followed = e28_creator();
    $other = e28_creator();
    $user = e16User();
    e29_follow($user, $followed);
    e29_work($other, ['title' => 'عمل مبدع غير متابَع']);
    e29_work($followed, ['title' => 'عمل مبدع متابَع']);

    $this->actingAs($user)->get(route('following.index'))->assertOk()->assertSee('عمل مبدع متابَع')->assertDontSee('عمل مبدع غير متابَع');
});

test('AA4-45: a hidden work does not appear in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $work = e29_work($creator, ['title' => 'عمل مخفي من الخلاصة']);
    e29_hide($work);

    $html = $this->actingAs($user)->get(route('following.index'))->assertOk()->getContent();
    expect(e29_feed_section($html))->toBe('')->and($html)->toContain('لا جديد الآن');
});

test('AA4-46: a suspended creator\'s content does not appear in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator, ['title' => 'عمل مبدع معلَّق']);
    app(CreatorApplicationService::class)->suspend(e29_profile($creator), e28_reviewer('administrator'), 'تعليق تجريبي للاختبار فقط.');

    $html = $this->actingAs($user)->get(route('following.index'))->assertOk()->getContent();
    expect(e29_feed_section($html))->toBe('')->and($html)->toContain('لا جديد الآن');
});

test('AA4-47: the feed is newest published first', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $old = e29_work($creator, ['title' => 'الأقدم نشرًا']);
    $mid = e29_work($creator, ['title' => 'المتوسط نشرًا']);
    $new = e29_work($creator, ['title' => 'الأحدث نشرًا']);
    e29_publish_at($old, now()->subDays(3)->toDateTimeString());
    e29_publish_at($mid, now()->subDays(2)->toDateTimeString());
    e29_publish_at($new, now()->subDay()->toDateTimeString());

    $section = e29_feed_section($this->actingAs($user)->get(route('following.index'))->assertOk()->getContent());
    expect(strpos($section, 'الأحدث نشرًا'))->toBeLessThan(strpos($section, 'المتوسط نشرًا'))->and(strpos($section, 'المتوسط نشرًا'))->toBeLessThan(strpos($section, 'الأقدم نشرًا'));
});

test('AA4-48: cursor pagination is stable (no repeats or gaps) even when something new is published between pages', function () {
    config(['engagement.pagination.following' => 2]);
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $works = [];

    foreach (range(1, 5) as $i) {
        $works[$i] = e29_work($creator, ['title' => "عنصر الخلاصة رقم {$i}"]);
        e29_publish_at($works[$i], now()->subDays(10 - $i)->toDateTimeString());   // رقم أكبر = أحدث
    }

    $page1 = $this->actingAs($user)->get(route('following.index'))->assertOk();
    $section1 = e29_feed_section($page1->getContent());
    expect($section1)->toContain('عنصر الخلاصة رقم 5')->toContain('عنصر الخلاصة رقم 4')->not->toContain('عنصر الخلاصة رقم 3');

    $late = e29_work($creator, ['title' => 'عنصر نُشر أثناء التصفح']);   // أحدث من كل شيء
    preg_match('/href="([^"]*cursor=[^"]+)"/', html_entity_decode($page1->getContent()), $m);
    expect($m)->not->toBeEmpty();

    $section2 = e29_feed_section($this->actingAs($user)->get($m[1])->assertOk()->getContent());
    expect($section2)->toContain('عنصر الخلاصة رقم 3')->toContain('عنصر الخلاصة رقم 2')
        ->not->toContain('عنصر الخلاصة رقم 5')->not->toContain('عنصر الخلاصة رقم 4')->not->toContain('عنصر نُشر أثناء التصفح');
});

test('AA4-49: the feed has no N+1 (same queries for 2 and 12 works)', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator);
    e29_work($creator);

    $this->actingAs($user)->get(route('following.index'))->assertOk();   // تسخين: صفوف كسولة/كاش لمرة واحدة لا تُحسب
    $two = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_work(e28_creator()); }
    foreach (CreatorProfile::query()->where('status', 'approved')->where('id', '>', e29_profile($creator)->id)->get() as $p) {
        app(CreatorFollowService::class)->follow($user, $p);
    }

    $twelve = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    expect($twelve)->toBe($two);
});
`````

### `tests/Feature/EngagementE29/E29IntegrityTest.php`

`sha256: 2c4dc86585c3ecff6030521bd497229b4c64a5af3a592a8b6da5463c61a5584a`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA10-99: a duplicate rating submission never double counts', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    foreach (range(1, 5) as $i) { e29_rate($user, $work, 4); }

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 0]);
    // محاكاة سباق خاسر: الصف موجود فعلًا -> القيد الفريد يمنع الثاني، والعدّاد لا يتحرك
    expect(fn () => (new CommunityPuzzleRating)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id, 'rating' => 4])->save())->toThrow(\Illuminate\Database\QueryException::class);
    expect(e29_counters($work)['count'])->toBe(1);
});

test('AA10-100: a duplicate favorite never double counts', function () {
    $work = e29_work();
    $user = e16User();

    foreach (range(1, 5) as $i) { e29_favorite($user, $work); }

    expect(e29_counters($work)['favorites'])->toBe(1)->and(CommunityPuzzleFavorite::query()->count())->toBe(1);
});

test('AA10-101: a duplicate follow never double counts', function () {
    $creator = e28_creator();
    $user = e16User();

    foreach (range(1, 5) as $i) { e29_follow($user, $creator); }

    expect(e29_followers($creator))->toBe(1)->and(CreatorFollow::query()->count())->toBe(1);
});

test('AA10-102: deleting can never drive a counter negative, even if the counter had drifted', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $rater = e29_player_who_played($work);
    $fav = e16User();
    $follower = e16User();
    e29_rate($rater, $work, 5);
    e29_favorite($fav, $work);
    e29_follow($follower, $creator);

    DB::table('creator_puzzles')->where('id', $work->id)->update(['ratings_count' => 0, 'ratings_sum' => 2, 'favorites_count' => 0]);
    DB::table('creator_profiles')->where('id', e29_profile($creator)->id)->update(['followers_count' => 0]);

    app(CommunityRatingService::class)->remove($rater, $work->fresh());
    app(CommunityFavoriteService::class)->remove($fav, $work->fresh());
    app(CreatorFollowService::class)->unfollow($follower, e29_profile($creator));

    expect(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0])->and(e29_followers($creator))->toBe(0);
});

function e29_corrupt(CreatorPuzzle $work, \App\Models\User $creator): void
{
    DB::table('creator_puzzles')->where('id', $work->id)->update(['ratings_count' => 40, 'ratings_sum' => 7, 'favorites_count' => 9]);
    DB::table('creator_profiles')->where('id', e29_profile($creator)->id)->update(['followers_count' => 77]);
}

test('AA10-103: reconcile --dry-run detects the mismatch and changes nothing', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 3]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    e29_corrupt($work, $creator);

    $exit = Artisan::call('community:reconcile-engagement', ['--dry-run' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)->and($out)->toContain('انحراف مكتشف: 4')->and($out)->toContain('dry-run')
        ->and(e29_counters($work))->toBe(['count' => 40, 'sum' => 7, 'favorites' => 9])->and(e29_followers($creator))->toBe(77);
});

test('AA10-104: reconcile fix mode repairs every counter from the rows and is idempotent', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 3]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    e29_corrupt($work, $creator);

    expect(Artisan::call('community:reconcile-engagement'))->toBe(0);
    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 8, 'favorites' => 1])->and(e29_followers($creator))->toBe(1);

    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0)->and(Artisan::output())->toContain('انحراف مكتشف: 0');
    expect(Artisan::call('community:reconcile-engagement'))->toBe(0)->and(e29_counters($work)['count'])->toBe(2);
});

test('AA10-105: hide and restore keep every rating, favorite and follow (nothing is deleted or double counted)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 4]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    $before = [e29_counters($work), e29_followers($creator)];

    e29_hide($work);
    expect(CommunityPuzzleRating::query()->count())->toBe(2)->and(e29_counters($work))->toBe($before[0]);
    e29_unhide($work);

    expect(e29_counters($work))->toBe($before[0])->and(e29_followers($creator))->toBe($before[1]);
    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('حفظوها');
});

test('AA10-106: suspending a creator keeps follows and ratings; restoring brings them back to the feed', function () {
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'يعود بعد الاستعادة']);
    $follower = e16User();
    e29_follow($follower, $creator);
    e29_ratings($work, [5]);
    $admin = e28_reviewer('administrator');

    app(CreatorApplicationService::class)->suspend(e29_profile($creator), $admin, 'تعليق تجريبي للاختبار فقط.');
    expect(CreatorFollow::query()->count())->toBe(1)->and(CommunityPuzzleRating::query()->count())->toBe(1);
    expect(e29_feed_section($this->actingAs($follower)->get(route('following.index'))->getContent()))->toBe('');
    $this->actingAs($follower)->get(route('creators.show', e29_profile($creator)->slug))->assertNotFound();

    app(CreatorApplicationService::class)->restore(e29_profile($creator)->fresh(), $admin, 'استعادة تجريبية.');

    expect(e29_feed_section($this->actingAs($follower)->get(route('following.index'))->getContent()))->toContain('يعود بعد الاستعادة');
});

test('AA10-107: deleting a user removes private lists, fixes counters, and keeps their rating anonymously in the aggregate', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $other = e29_player_who_played($work);
    e29_rate($user, $work, 5);
    e29_rate($other, $work, 3);
    e29_favorite($user, $work);
    e29_follow($user, $creator);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());

    $user->delete();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(CreatorFollow::query()->count())->toBe(0)
        ->and(UserCollection::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(e29_counters($work))->toBe(['count' => 2, 'sum' => 8, 'favorites' => 0])->and(e29_followers($creator))->toBe(0)
        ->and(CommunityPuzzleRating::query()->whereNull('user_id')->count())->toBe(1);
    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0);
});

test('AA10-108: deleting a work (admin hard delete) cascades its interactions and leaves no orphan rows', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);
    e29_favorite($user, $work);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());

    DB::table('creator_puzzles')->where('id', $work->id)->delete();

    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(UserCollection::query()->count())->toBe(1);
});

test('AA10-109: the whole engagement flow touches no wallet, XP, quest, streak, achievement, campaign or official attempt', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();
    $before = e28_world($user);
    $creatorBefore = e28_world($creator);

    e29_rate($user, $work, 5);
    e29_favorite($user, $work);
    e29_follow($user, $creator);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());
    e28_published($creator, 'text');   // إشعار نشر لمتابع
    foreach (['favorites.index', 'following.index', 'collections.index', 'discover.index'] as $name) { $this->actingAs($user)->get(route($name))->assertOk(); }

    expect(e28_world($user))->toBe($before)->and(e28_world($creator))->toBe($creatorBefore);
});

test('AA10-110: the reconciler repairs a large chunked set (chunk size smaller than the table)', function () {
    $creator = e28_creator();
    $works = collect(range(1, 6))->map(fn () => e29_work($creator));
    $works->each(fn ($w) => e29_favorite(e16User(), $w));
    $works->each(fn ($w) => DB::table('creator_puzzles')->where('id', $w->id)->update(['favorites_count' => 50]));

    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true, '--chunk' => 50]))->toBe(1);
    expect(Artisan::call('community:reconcile-engagement', ['--chunk' => 50]))->toBe(0);
    expect($works->every(fn ($w) => e29_counters($w)['favorites'] === 1))->toBeTrue();
});
`````

### `tests/Feature/EngagementE29/E29NotificationTest.php`

`sha256: 40a3d297c2fdca7886893a8aed1a0f072fa787b319770c024afd965846cc3b89`

`````php
<?php

use App\Jobs\DispatchCreatorPublicationChunk;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_notes(User $user, string $type = 'creator_new_publication')
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', $type)->get();
}

/** يرسل عملًا للمراجعة دون اعتماد (للتحكم بلحظة الاعتماد). */
function e29_pending_for(User $creator, array $over = []): CreatorPuzzleRevision
{
    return e28_pending($creator, 'text', $over);
}

test('AA5-50: a new approved publication queues the fan-out job for followers only when there are followers', function () {
    Queue::fake();
    $creator = e28_creator();
    $lonely = e28_creator();
    e29_follow(e16User(), $creator);

    e28_published($creator, 'text', ['title' => 'له متابعون']);
    e28_published($lonely, 'text', ['title' => 'بلا متابعين']);

    Queue::assertPushed(DispatchCreatorPublicationChunk::class, 1);
});

test('AA5-51: a notification failure never rolls back the publication', function () {
    $creator = e28_creator();
    e29_follow(e16User(), $creator);

    $this->mock(NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andReturnUsing(function ($user, $type) {
            if ($type === NotificationType::CreatorNewPublication) {
                throw new RuntimeException('simulated fan-out failure');
            }

            return DispatchResult::Created;
        });
    });

    $work = e28_published($creator, 'text', ['title' => 'نشر رغم فشل الإشعار']);

    expect($work->fresh()->is_visible)->toBeTrue()->and($work->fresh()->published_puzzle_id)->not->toBeNull()
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->value('status'))->toBe('approved');
});

test('AA5-52: a follower receives exactly one correct internal notification', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);

    $work = e28_published($creator, 'text', ['title' => 'لغز الإشعار الصحيح']);

    $notes = e29_notes($follower);
    expect($notes)->toHaveCount(1);
    $data = $notes[0]->data;
    expect($data['category'])->toBe('social')->and($data['title'])->toContain('لغز الإشعار الصحيح')->and($data['body'])->toContain('بلا جوائز اقتصادية')
        ->and($data['action_route'])->toBe('community.puzzles.show')->and($data['action_params']['work'])->toBe($work->public_id)
        ->and($notes[0]->idempotency_key)->toStartWith('creator-publication:');
});

test('AA5-53: a non-follower receives nothing, and neither does the creator', function () {
    $creator = e28_creator();
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);

    e28_published($creator, 'text');

    expect(e29_notes($stranger))->toHaveCount(0)->and(e29_notes($creator))->toHaveCount(0)->and(e29_notes($follower))->toHaveCount(1);
});

test('AA5-54: running the same job twice never duplicates a notification', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $work = e28_published($creator, 'text');
    $revisionId = (int) $work->fresh()->published_revision_id;

    (new DispatchCreatorPublicationChunk($revisionId))->handle(app(NotificationDispatcher::class));
    (new DispatchCreatorPublicationChunk($revisionId))->handle(app(NotificationDispatcher::class));

    expect(e29_notes($follower))->toHaveCount(1);
});

test('AA5-55: a publication hidden before the worker runs is not announced', function () {
    Queue::fake();
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $work = e28_published($creator, 'text');
    Queue::assertPushed(DispatchCreatorPublicationChunk::class);

    e29_hide($work);   // قبل أن يعمل العامل

    (new DispatchCreatorPublicationChunk((int) $work->fresh()->published_revision_id))->handle(app(NotificationDispatcher::class));

    expect(e29_notes($follower))->toHaveCount(0);
});

test('AA5-56: a large follower set is processed in bounded chunks chained by id cursor', function () {
    config(['player_notifications.chunk_size' => 3]);
    Queue::fake();
    $creator = e28_creator();
    $followers = collect(range(1, 8))->map(fn () => e16User());
    $followers->each(fn ($u) => e29_follow($u, $creator));

    $work = e28_published($creator, 'text');
    $revisionId = (int) $work->fresh()->published_revision_id;

    $runs = 0;
    $job = new DispatchCreatorPublicationChunk($revisionId);

    while ($job !== null && $runs < 10) {
        Queue::fake();   // يسجّل ما يُدفع من الدفعة التالية فقط
        $job->handle(app(NotificationDispatcher::class));
        $runs++;
        $job = Queue::pushed(DispatchCreatorPublicationChunk::class)->first();
    }

    expect($runs)->toBe(3)->and(DatabaseNotification::query()->where('type_key', 'creator_new_publication')->count())->toBe(8);
});

test('AA5-57: favoriting never notifies anyone', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $before = DatabaseNotification::query()->count();

    e29_favorite(e16User(), $work);

    expect(DatabaseNotification::query()->count())->toBe($before);
});

test('AA5-58: rating or playing never notifies anyone', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $before = DatabaseNotification::query()->count();

    e29_ratings($work, [5, 4, 3]);

    expect(DatabaseNotification::query()->count())->toBe($before);
});

test('AA5-59: the existing social preference and the global switch are respected (no second preference system)', function () {
    $creator = e28_creator();
    $muted = e16User();
    $active = e16User();
    e29_follow($muted, $creator);
    e29_follow($active, $creator);
    app(NotificationPreferenceService::class)->update($muted, ['social_enabled' => false]);

    e28_published($creator, 'text');

    expect(e29_notes($muted))->toHaveCount(0)->and(e29_notes($active))->toHaveCount(1);
});

test('AA5-60: frozen or unverified followers are skipped and a new revision notifies again once', function () {
    $creator = e28_creator();
    $frozen = e16User();
    $ok = e16User();
    e29_follow($frozen, $creator);
    e29_follow($ok, $creator);
    $frozen->forceFill(['is_frozen' => true])->save();

    $work = e28_published($creator, 'text', ['title' => 'النشر الأول']);
    expect(e29_notes($frozen))->toHaveCount(0)->and(e29_notes($ok))->toHaveCount(1);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'النسخة المحدَّثة']));
    $revision = app(CreatorPuzzleService::class)->submit($creator, $work->public_id);
    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    expect(e29_notes($ok))->toHaveCount(2);
});

test('AA5-61: the revision notification can be switched off by config', function () {
    config(['engagement.notifications.on_revision' => false]);
    $creator = e28_creator();
    $ok = e16User();
    e29_follow($ok, $creator);
    $work = e28_published($creator, 'text');
    expect(e29_notes($ok))->toHaveCount(1);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'نسخة بلا إشعار']));
    app(CreatorReviewService::class)->approve(app(CreatorPuzzleService::class)->submit($creator, $work->public_id), e28_reviewer());

    expect(e29_notes($ok))->toHaveCount(1);
});
`````

### `tests/Feature/EngagementE29/E29PerformanceTest.php`

`sha256: 6bad92eed47afea8310f397f0369fe7aa306a96d6bada6e881f42545e2ba195b`

`````php
<?php

use App\Models\CreatorProfile;
use App\Services\Engagement\CreatorFollowService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

/*
| E29 - ميزانيات الاستعلام. الأرقام المثبَّتة قياسات فعلية (لا تقديرية) مع هامش صغير؛ تتبع الحالة لا حجم البيانات:
| ما يهم بالاختبارات: **تساوي** العدد بين حجمين مختلفين (لا N+1) + سقف مطلق يلتقط أي انجراف كبير مستقبلًا.
*/

test('AA11-105: the puzzle page query count is bounded and independent of rating volume', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);
    $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());

    e29_ratings($work, array_fill(0, 25, 4));
    $many = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());
    $this->app['auth']->forgetGuards();
    $guest = e27_queries(fn () => $this->get(route('community.puzzles.show', $work->public_id))->assertOk());

    // القياس الفعلي: لاعب 15 (E28 كان يقيس 17 بحالة محلولة)، ضيف 5 = رقم E28 نفسه: التفاعل لا يضيف استعلامًا واحدًا للضيف (المتوسط والعدّادات أعمدة العمل نفسه).
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(17)->and($guest)->toBe(5);
});

test('AA11-106: the favorites list is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    $creator = e28_creator();
    foreach (range(1, 2) as $i) { e29_favorite($user, e29_work($creator)); }
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($user, e29_work(e28_creator())); }
    $twelve = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-107: the following feed is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    foreach (range(1, 2) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $this->actingAs($user)->get(route('following.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $twelve = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-108: the creator page is bounded for guests and signed-in viewers', function () {
    $creator = e28_creator();
    $slug = e29_profile($creator)->slug;
    e29_work($creator);
    $viewer = e16User();
    $this->get(route('creators.show', $slug))->assertOk();
    $guestOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->actingAs($viewer)->get(route('creators.show', $slug))->assertOk();
    $userOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($viewer, e29_work($creator)); }
    $userMany = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->app['auth']->forgetGuards();

    // الضيف 7 = رقم E28 نفسه (لا استعلام تفاعل للضيف)؛ المسجَّل +استعلامان فقط فوق الجرس/التقدّم: حالة المتابعة (exists) + مفضلة البطاقات (دفعة واحدة).
    expect($userMany)->toBe($userOne)->and($userMany)->toBeLessThanOrEqual(18)->and($guestOne)->toBe(7);
});

test('AA11-109: 1 vs 12 community cards cost the same queries (no N+1), saved state is one batched query', function () {
    $creator = e28_creator();
    $viewer = e16User();
    e29_favorite($viewer, e29_work($creator));
    $this->actingAs($viewer)->get(route('community.puzzles.index'))->assertOk();
    $one = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    foreach (range(1, 11) as $i) { $w = e29_work(e28_creator()); $i % 2 ? e29_favorite($viewer, $w) : e29_ratings($w, [5, 4]); }
    $twelve = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    expect($twelve)->toBe($one)->and($twelve)->toBeLessThanOrEqual(16);
});

test('AA11-110: the discover page query count is bounded with engagement signals present', function () {
    $viewer = e16User();
    $creator = e28_creator();
    e29_follow($viewer, $creator);
    e29_work($creator);
    foreach (range(1, 6) as $i) { $w = e29_work(e28_creator()); e29_ratings($w, [5, 5, 4, 5, 5]); e29_favorite($viewer, $w); }
    e27_cold();
    $this->actingAs($viewer)->get(route('discover.index'))->assertOk();
    $warm = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());
    e27_cold();
    $cold = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());

    // القياس: دافئ 27، بارد 34 لمستخدم مسجَّل لديه متابعة ومفضلة وأعمال مقيَّمة. (+3 فوق E28: الأعلى تقييمًا، من أتابعهم، تفضيلات المشاهد.)
    expect($warm)->toBeLessThanOrEqual(30)->and($cold)->toBeLessThanOrEqual(38);
});

test('AA11-111: the star control and engagement panel render for a player without extra per-card work', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect($html)->toContain('id="engagement"')->toContain('role="radiogroup"')->toContain('name="rating"')->toContain('احفظ في المفضلة')->toContain('أضف إلى مجموعة');
    expect(substr_count($html, 'type="radio" name="rating"'))->toBe(5);
});
`````

### `tests/Feature/EngagementE29/E29RatingTest.php`

`sha256: 436717c28fe4da598b403aa1e5511f170f0bd0460f4b1587e7470caa03db0307`

`````php
<?php

use App\Models\CommunityPuzzleRating;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Engagement\CommunityRatingService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA1-1: a guest cannot rate (redirected to login, nothing stored)', function () {
    $work = e29_work();

    $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertRedirect(route('login'));
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-2: an unverified user cannot rate', function () {
    $work = e29_work();
    $user = e16User(['email_verified_at' => null]);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertRedirect(route('verification.notice'));
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-3: a frozen user cannot rate', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    $user->forceFill(['is_frozen' => true])->save();

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4]);
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
    expect(fn () => e29_rate($user->fresh(), $work, 4))->toThrow(\App\Services\Engagement\EngagementException::class);
});

test('AA1-4: a user with no attempt cannot rate', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertSessionHas('error');
    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(e29_counters($work)['count'])->toBe(0);
});

test('AA1-5: a user who attempted (even wrongly) can rate', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertRedirect()->assertSessionHas('engagement_status');
    expect(CommunityPuzzleRating::query()->where('user_id', $user->id)->value('rating'))->toBe(4);
});

test('AA1-6: the creator cannot rate their own work', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $this->actingAs($creator)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertSessionHas('error');
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-7: a rating below 1 is rejected server-side', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    foreach ([0, -1, 'abc', '', null] as $bad) {
        $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => $bad])->assertSessionHasErrors('rating');
    }

    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-8: a rating above 5 is rejected server-side (HTTP and service)', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 6])->assertSessionHasErrors('rating');
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4.5])->assertSessionHasErrors('rating');
    expect(fn () => app(CommunityRatingService::class)->set($user, $work->fresh(), 99))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-9: a rating is stored once per user and work', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3]);
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3]);

    expect(CommunityPuzzleRating::query()->where('user_id', $user->id)->count())->toBe(1)->and(e29_counters($work)['count'])->toBe(1);
});

test('AA1-10: a second PUT updates the same rating row', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 2]);
    $id = CommunityPuzzleRating::query()->where('user_id', $user->id)->value('id');
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5]);

    $row = CommunityPuzzleRating::query()->where('user_id', $user->id)->get();
    expect($row)->toHaveCount(1)->and((int) $row[0]->id)->toBe((int) $id)->and((int) $row[0]->rating)->toBe(5);
});

test('AA1-11: deleting a rating works and is idempotent', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);

    $this->actingAs($user)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();
    $this->actingAs($user)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0]);
});

test('AA1-12: the database itself rejects a duplicate (user, work) rating', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);

    expect(fn () => (new CommunityPuzzleRating)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id, 'rating' => 5])->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA1-13: ratings_count equals the number of rating rows', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 3, 1]);

    expect(e29_counters($work)['count'])->toBe(4)->and(CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->count())->toBe(4);
});

test('AA1-14: ratings_sum equals the sum of the rating rows', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 3, 1]);

    expect(e29_counters($work)['sum'])->toBe(13)->and((int) CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->sum('rating'))->toBe(13);
});

test('AA1-15: the average is sum / count rounded to one decimal', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 4]);

    expect($work->fresh()->averageRating())->toBe(4.3);
    expect(e29_work()->averageRating())->toBeNull();
});

test('AA1-16: changing a rating adjusts the aggregate by the difference only', function () {
    $work = e29_work();
    [$a, $b] = e29_ratings($work, [5, 3]);

    e29_rate($b, $work, 1);

    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 6, 'favorites' => 0]);
});

test('AA1-17: deleting a rating removes it from the aggregate', function () {
    $work = e29_work();
    [$a, $b] = e29_ratings($work, [5, 3]);

    app(CommunityRatingService::class)->remove($a, $work->fresh());

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 3, 'favorites' => 0]);
});

test('AA1-18: a hidden work cannot be newly rated, while old ratings are kept and removable', function () {
    $work = e29_work();
    [$old] = e29_ratings($work, [5]);
    $late = e29_player_who_played($work);
    e29_hide($work);

    expect(fn () => e29_rate($late, $work, 4))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(fn () => e29_rate($old, $work, 1))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(e29_counters($work)['count'])->toBe(1);

    $this->actingAs($old)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();
    expect(e29_counters($work)['count'])->toBe(0);
});

test('AA1-19: publishing a new revision keeps the existing ratings and counters', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 4]);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'عنوان معدّل بنسخة جديدة']));
    $submitted = app(CreatorPuzzleService::class)->submit($creator, $work->public_id);
    app(CreatorReviewService::class)->approve($submitted, e28_reviewer());

    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 9, 'favorites' => 0])->and(CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->count())->toBe(2);
});

test('AA1-20: rating grants zero economy, XP, quest, streak or achievement effect', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertOk();   // الصفحة نفسها تُنشئ صف التقدّم الكسول؛ نثبّت اللقطة بعدها
    $before = e28_world($user);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertSessionHas('engagement_status');
    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertOk();

    expect(e28_world($user))->toBe($before);
});
`````

### `tests/Feature/EngagementE29/E29SecurityTest.php`

`sha256: 044126e5583311ce16b283d5d7f88578c2eb1f978b3114757943cec441b5519e`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA9-88: rating IDOR is denied: another user can neither read, overwrite nor delete someone else\'s rating', function () {
    $work = e29_work();
    [$a, $b] = [e29_player_who_played($work), e29_player_who_played($work)];
    e29_rate($a, $work, 5);

    $this->actingAs($b)->delete(route('community.puzzles.rating.destroy', $work->public_id), ['user_id' => $a->id, 'id' => CommunityPuzzleRating::query()->value('id')])->assertRedirect();
    $this->actingAs($b)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 1, 'user_id' => $a->id])->assertRedirect();

    $rows = CommunityPuzzleRating::query()->orderBy('user_id')->get(['user_id', 'rating'])->mapWithKeys(fn ($r) => [(int) $r->user_id => (int) $r->rating])->all();
    expect($rows)->toBe([$a->id => 5, $b->id => 1])->and(e29_counters($work)['sum'])->toBe(6);
});

test('AA9-89: favorite IDOR is denied (a user only ever touches their own row)', function () {
    $work = e29_work();
    [$a, $b] = [e16User(), e16User()];
    e29_favorite($a, $work);

    $this->actingAs($b)->delete(route('community.puzzles.favorite.destroy', $work->public_id), ['user_id' => $a->id])->assertRedirect();
    $this->actingAs($b)->post(route('community.puzzles.favorite.store', $work->public_id), ['user_id' => $a->id]);

    expect(CommunityPuzzleFavorite::query()->where('user_id', $a->id)->count())->toBe(1)->and(CommunityPuzzleFavorite::query()->where('user_id', $b->id)->count())->toBe(1);
});

test('AA9-90: follow ownership is safe (a client-supplied user_id is ignored)', function () {
    $creator = e28_creator();
    [$a, $b] = [e16User(), e16User()];

    $this->actingAs($b)->post(route('creators.follow', e29_profile($creator)->slug), ['user_id' => $a->id, 'follower_id' => $a->id])->assertRedirect();

    expect(CreatorFollow::query()->pluck('user_id')->all())->toBe([$b->id]);
    $this->actingAs($a)->delete(route('creators.unfollow', e29_profile($creator)->slug))->assertRedirect();
    expect(CreatorFollow::query()->count())->toBe(1);
});

test('AA9-91: collection IDOR is denied on every verb (including item removal)', function () {
    $owner = e16User();
    $intruder = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'لا تُمس');
    app(\App\Services\Engagement\CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($intruder)->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertNotFound();
    $this->actingAs($intruder)->delete(route('collections.destroy', $c->public_id))->assertNotFound();
    $this->actingAs($intruder)->put(route('collections.update', $c->public_id), ['title' => 'مخترَق'])->assertNotFound();

    expect(UserCollection::query()->find($c->id)->title)->toBe('لا تُمس')->and(UserCollectionItem::query()->count())->toBe(1);
});

test('AA9-92: CSRF protects every mutating engagement route (419 without a token, nothing changes)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $c = e29_collection($user);
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($user);
        $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertStatus(419);
        $this->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertStatus(419);
        $this->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertStatus(419);
        $this->post(route('creators.follow', e29_profile($creator)->slug))->assertStatus(419);
        $this->delete(route('creators.unfollow', e29_profile($creator)->slug))->assertStatus(419);
        $this->post(route('collections.store'), ['title' => 'بلا رمز'])->assertStatus(419);
        $this->put(route('collections.update', $c->public_id), ['title' => 'بلا رمز'])->assertStatus(419);
        $this->delete(route('collections.destroy', $c->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertStatus(419);
        $this->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CommunityPuzzleRating::query()->count() + CommunityPuzzleFavorite::query()->count() + CreatorFollow::query()->count() + UserCollectionItem::query()->count())->toBe(0)
        ->and(UserCollection::query()->find($c->id)->title)->not->toBe('بلا رمز');
});

test('AA9-93: the named rate limiters throttle (rating, favorite, follow, collection actions) per user, not per IP', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $other = e29_player_who_played($work);

    $this->actingAs($user);
    foreach (range(1, 20) as $i) { $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4]); }
    $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertStatus(429);
    $this->actingAs($other)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3])->assertStatus(302);   // مستخدم آخر من نفس العنوان غير مخنوق

    $this->actingAs($user);
    foreach (range(1, 30) as $i) { $this->post(route('community.puzzles.favorite.store', $work->public_id)); }
    $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertStatus(429);

    foreach (range(1, 15) as $i) { $this->post(route('creators.follow', e29_profile($creator)->slug)); }
    $this->post(route('creators.follow', e29_profile($creator)->slug))->assertStatus(429);

    foreach (range(1, 30) as $i) { $this->post(route('collections.store'), ['title' => 'مجموعة '.$i.' اختبار']); }
    $this->post(route('collections.store'), ['title' => 'الحادية والثلاثون'])->assertStatus(429);
    expect(UserCollection::query()->where('user_id', $user->id)->count())->toBeLessThanOrEqual((int) config('engagement.collections.max_per_user'));
});

test('AA9-94: there is no GET mutation: only read pages answer GET, mutations answer 405', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e16User();
    $c = e29_collection($user);
    $readActions = ['index', 'feed', 'create', 'show', 'edit'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_contains((string) $route->getActionName(), 'Controllers\\Engagement\\')) {
            continue;
        }

        $checked++;
        $method = explode('@', (string) $route->getActionName())[1] ?? '';

        if (! in_array($method, $readActions, true)) {
            expect($route->methods())->not->toContain('GET')->and($route->methods())->not->toContain('HEAD');
        }
    }

    expect($checked)->toBeGreaterThan(15);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/favorite')->assertStatus(405);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/rating?rating=5')->assertStatus(405);
    $this->actingAs($user)->get('/creators/'.e29_profile($creator)->slug.'/follow')->assertStatus(405);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/collections')->assertStatus(405);
    expect(CommunityPuzzleFavorite::query()->count() + CreatorFollow::query()->count())->toBe(0);
});

test('AA9-95: a collection title or description with markup is escaped on every page (defence in depth)', function () {
    $user = e16User();
    $c = new UserCollection;
    $c->forceFill(['user_id' => $user->id, 'title' => '<script>alert("xss")</script>', 'description' => '<img src=x onerror=alert(1)>'])->save();
    $work = e29_work();
    app(\App\Services\Engagement\CollectionService::class)->add($user, $c->public_id, $work->fresh());

    foreach ([route('collections.index'), route('collections.show', $c->public_id), route('collections.edit', $c->public_id), route('community.puzzles.show', $work->public_id)] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert("xss")</script>')->and($html)->not->toContain('<img src=x onerror=alert(1)>');
    }

    expect($this->actingAs($user)->get(route('collections.index'))->getContent())->toContain('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
});

test('AA9-96: mass assignment cannot set any counter or engagement row', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['ratings_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['ratings_sum' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['favorites_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => e29_profile($creator)->fill(['followers_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CommunityPuzzleRating::query()->create(['user_id' => 1, 'creator_puzzle_id' => $work->id, 'rating' => 5]))->toThrow(MassAssignmentException::class);
    expect(fn () => UserCollection::query()->create(['user_id' => 1, 'title' => 'x']))->toThrow(MassAssignmentException::class);
    expect(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0]);
});

test('AA9-97: the client cannot set the average or the rating aggregate through the request', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), [
        'rating' => 4, 'average' => 5, 'average_rating' => 5, 'ratings_count' => 500, 'ratings_sum' => 2500, 'favorites_count' => 99,
    ])->assertRedirect();

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 0])->and($work->fresh()->averageRating())->toBe(4.0);
});

test('AA9-98: the client cannot set followers_count or the favorites counter through the request', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_profile($creator)->slug), ['followers_count' => 999])->assertRedirect();
    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id), ['favorites_count' => 999])->assertRedirect();

    expect(e29_followers($creator))->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA9-99: unverified, guest and frozen users are blocked on the private lists too', function () {
    $unverified = e16User(['email_verified_at' => null]);
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();

    $pages = ['favorites.index', 'following.index', 'collections.index', 'collections.create'];

    foreach ($pages as $name) {   // الضيف أولًا (actingAs يبقى فعّالًا بعد استدعائه)
        $this->get(route($name))->assertRedirect(route('login'));
    }

    foreach ($pages as $name) {
        $this->actingAs($unverified)->get(route($name))->assertRedirect(route('verification.notice'));
    }

    foreach ($pages as $name) {
        $this->actingAs($frozen->fresh())->get(route($name))->assertRedirect();
    }
});

test('AA9-100: no rating can be reported and no rating row is ever exposed publicly', function () {
    $work = e29_work();
    [$user] = e29_ratings($work, [5, 4, 3, 5, 4]);

    expect(array_keys(\App\Models\CommunityPuzzleReport::REASONS))->not->toContain('rating');
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName())->filter()->all();
    expect(collect($names)->filter(fn ($n) => str_contains($n, 'rating') && str_contains($n, 'report'))->all())->toBe([]);

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->not->toContain($user->email)->and($html)->not->toContain((string) $user->name);
});
`````

### `tests/Feature/EngagementE29/E29TopRatedDiscoveryTest.php`

`sha256: 6e26eb06d849f296427a52022e2fdafccdb736d0281a841c3347dd0055fea231`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\PuzzleCategory;
use App\Services\Creators\CommunityDiscovery;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Discovery\DiscoveryService;
use App\Services\Engagement\CommunityQualityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_top(): array
{
    return app(CommunityQualityService::class)->computeTopRated();
}

function e29_titles(array $cards): array
{
    return array_map(fn ($c) => $c->title, $cards);
}

test('AA7-75: a single 5-star rating never dominates a well-rated work (shrinkage + minimum sample)', function () {
    $one = e29_work(null, ['title' => 'تقييم واحد خمس نجوم']);
    e29_ratings($one, [5]);
    $many = e29_work(null, ['title' => 'تقييمات كثيرة موثوقة']);
    e29_ratings($many, [5, 4, 5, 4, 4, 5, 4, 5, 4, 4]);

    expect(array_keys(e29_top()))->toBe([$many->id]);

    config(['engagement.top_rated.min_ratings' => 1]);
    expect(array_keys(e29_top()))->toBe([$many->id, $one->id]);
});

test('AA7-76: the minimum sample is enforced', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5]);
    expect(e29_top())->toBe([]);

    e29_ratings($work, [5]);
    expect(array_keys(e29_top()))->toBe([$work->id]);
});

test('AA7-77: the weighted score is deterministic and matches the documented formula', function () {
    $q = app(CommunityQualityService::class);

    expect($q->score(5, 25))->toBe(4.25)->and($q->score(5, 25))->toBe($q->score(5, 25))
        ->and(round($q->score(500, 2400), 2))->toBe(4.79)->and($q->score(0, 0))->toBe(3.5);
    expect($q->score(500, 2400))->toBeGreaterThan($q->score(5, 25));   // الكثرة الموثوقة تتفوق على عيّنة صغيرة كاملة
});

test('AA7-78: a hidden work is excluded from the ranking', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    expect(array_keys(e29_top()))->toBe([$work->id]);

    e29_hide($work);

    expect(e29_top())->toBe([]);
});

test('AA7-79: a suspended creator\'s works are excluded', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 5, 5, 5, 5]);

    app(CreatorApplicationService::class)->suspend(e29_profile($creator), e28_reviewer('administrator'), 'تعليق تجريبي للاختبار فقط.');

    expect(e29_top())->toBe([]);
});

test('AA7-80: the cache stores only safe identifiers and scores', function () {
    $work = e29_work(null, ['title' => 'عنوان لا يجب تخزينه بالكاش']);
    e29_ratings($work, [5, 4, 5, 4, 5]);
    e27_cold();

    $stored = app(CommunityQualityService::class)->topRated();
    $cached = Cache::get('discover.community.top_rated');

    expect($cached)->not->toBeNull()->and($cached)->toBe($stored)->and(array_keys($cached))->toBe([$work->id]);

    foreach ($cached as $id => $row) {
        expect(is_int($id))->toBeTrue()->and(array_keys($row))->toBe(['score', 'count', 'average']);
    }

    $json = json_encode($cached);
    expect($json)->not->toContain('عنوان لا يجب')->and($json)->not->toContain('@');
});

test('AA7-81: visibility is re-checked on every read even while the cache still holds the id', function () {
    $work = e29_work(null, ['title' => 'يختفي رغم الكاش']);
    e29_ratings($work, [5, 5, 5, 5, 5]);
    e27_cold();
    $viewer = e16User();

    expect(e29_titles(app(CommunityDiscovery::class)->sections($viewer)['top_rated']))->toBe(['يختفي رغم الكاش']);

    e29_hide($work);   // لا نمسح الكاش

    expect(array_keys(Cache::get('discover.community.top_rated')))->toBe([$work->id])
        ->and(app(CommunityDiscovery::class)->sections($viewer)['top_rated'])->toBe([]);
});

test('AA7-82: the Top Rated surface has zero reward, XP or progression effect', function () {
    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    $viewer = e16User();
    $this->actingAs($viewer)->get(route('discover.index'))->assertOk()->assertSee('الأعلى تقييمًا من المجتمع');   // تسخين + يثبّت صف التقدّم الكسول
    $before = e28_world($viewer);

    $this->actingAs($viewer)->get(route('discover.index'))->assertOk();

    expect(e28_world($viewer))->toBe($before);
});

test('AA7-83: the top-rated card states the rating basis honestly (average and sample size)', function () {
    $work = e29_work(null, ['title' => 'عنوان بتقييم صادق']);
    e29_ratings($work, [5, 4, 5, 4, 5]);

    $html = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e29_section($html, 'الأعلى تقييمًا من المجتمع'))->toContain('عنوان بتقييم صادق')->toContain('4.6 من 5')->toContain('5 تقييم');
});

// ───────────── AA8: الاكتشاف ─────────────

/** @return array{list<CreatorPuzzle>, PuzzleCategory, PuzzleCategory} اثنا عشر عملًا لمبدعين مختلفين؛ الأحدث الأعلى رقمًا. */
function e29_world_of_twelve(): array
{
    $x = e28_category(['name' => 'تصنيف س']);
    $y = e28_category(['name' => 'تصنيف ص']);
    $works = [];

    foreach (range(1, 12) as $i) {
        $cat = in_array($i, [3, 9], true) ? $y : $x;
        $works[$i] = e29_work(null, ['title' => "عمل رقم {$i}", 'puzzle_category_id' => $cat->id]);
        DB::table('creator_puzzles')->where('id', $works[$i]->id)->update(['published_at' => now()->subHours(40 - $i)]);
    }

    DB::table('creator_puzzles')->where('id', $works[1]->id)->update(['plays_count' => 5]);

    return [$works, $x, $y];
}

test('AA8-83: a favorite signal never leaks another user\'s data and only reorders the viewer\'s own candidates', function () {
    [$works] = e29_world_of_twelve();
    e27_cold();
    $a = e16User(['name' => 'مفضِّل خاص جدًا']);
    $b = e16User();
    $c = e16User();

    $baseline = e29_titles(app(CommunityDiscovery::class)->sections($b)['for_you']);
    expect($baseline[0] ?? null)->toBe('عمل رقم 1');   // بلا إشارة: الأكثر لعبًا أولًا

    e29_favorite($a, $works[9]);   // مفضلة مستخدم آخر لا تغيّر شيئًا لـ b
    expect(e29_titles(app(CommunityDiscovery::class)->sections($b)['for_you']))->toBe($baseline);

    e29_favorite($c, $works[9]);   // مفضلة المشاهد نفسه (تصنيف ص) تعيد ترتيب مرشَّحيه: العمل 3 (تصنيف ص) يتقدّم
    $reordered = e29_titles(app(CommunityDiscovery::class)->sections($c)['for_you']);
    expect($reordered[0] ?? null)->toBe('عمل رقم 3')->and($reordered)->toContain('عمل رقم 1');

    $html = $this->actingAs($b)->get(route('discover.index'))->assertOk()->getContent();
    expect($html)->not->toContain('مفضِّل خاص جدًا');
});

test('AA8-84: content of a followed creator is eligible for the follower only', function () {
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'من مبدع أتابعه']);
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);
    e27_cold();

    expect(e29_titles(app(CommunityDiscovery::class)->sections($follower)['following']))->toBe(['من مبدع أتابعه'])
        ->and(app(CommunityDiscovery::class)->sections($stranger)['following'])->toBe([])
        ->and(app(CommunityDiscovery::class)->sections(null)['following'])->toBe([]);

    $hub = app(DiscoveryService::class)->hub($follower);
    expect(collect($hub['sections'])->pluck('key')->all())->toContain('community-following');
    expect(collect(app(DiscoveryService::class)->hub($stranger)['sections'])->pluck('key')->all())->not->toContain('community-following');
});

test('AA8-85: creator diversity holds: a prolific top-rated creator cannot monopolise the section', function () {
    config(['engagement.top_rated.min_ratings' => 1]);
    $prolific = e28_creator();

    foreach (range(1, 5) as $i) {
        $w = e29_work($prolific, ['title' => "غزير {$i}"]);
        e29_ratings($w, [5]);
    }

    $other = e29_work(null, ['title' => 'مبدع آخر']);
    e29_ratings($other, [4]);
    e27_cold();

    $titles = e29_titles(app(CommunityDiscovery::class)->sections(e16User())['top_rated']);
    $fromProlific = count(array_filter($titles, fn ($t) => str_starts_with($t, 'غزير')));

    expect($fromProlific)->toBe((int) config('creators.discovery.per_creator_cap'))->and($titles)->toContain('مبدع آخر');
});

test('AA8-86: private semantics unaffected: private collections never surface in search or discovery', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'قافلة الخفاء الكبرى');
    app(\App\Services\Engagement\CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    foreach ([$owner, e16User()] as $viewer) {
        // صفحة البحث تردّد كلمة البحث نفسها؛ نبحث بكلمة واحدة من العنوان ونتحقق أن العنوان الكامل لا يظهر نتيجةً أبدًا.
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'الخفاء']))->assertOk()->assertDontSee('قافلة الخفاء الكبرى');
        $this->actingAs($viewer)->get(route('discover.index'))->assertOk()->assertDontSee('قافلة الخفاء الكبرى');
    }
});

test('AA8-87: the official discovery sections are unaffected by any community engagement', function () {
    $puzzle = \App\Models\Puzzle::factory()->create();
    $official = fn () => collect(app(DiscoveryService::class)->hub(null)['sections'])->reject(fn ($s) => str_starts_with($s['key'], 'community-'))->values()->all();
    e27_cold();
    $before = $official();

    $work = e29_work();
    e29_ratings($work, [5, 5, 5, 5, 5]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), e28_creator());
    e27_cold();

    expect($official())->toEqual($before);
});
`````

### `tests/Feature/EngagementE29/_support.php`

`sha256: 019b1f09b4dda7475dbb5b9269f3c98ef3b0f7a4b207ec7dc0ed37437d9faeb3`

`````php
<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorVisibilityService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;

/*
| E29 - مساعدات مشتركة لاختبارات التفاعل المجتمعي. كل شيء على SQLite وكاش array وطابور sync؛ لا شبكة.
| كل التهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات) كي تختبر الاختبارات المسار الحقيقي.
| تعتمد على مساعدات E28 (e28_creator/e28_published/...) و E27 (e27_queries/e27_cold).
*/

require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e29_seed')) {
    /** يبذر الأدوار (مطلوب لمراجع E28). يُستدعى من beforeEach بكل ملف. */
    function e29_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** عمل مجتمع منشور لمبدع جديد (أو معطى). */
    function e29_work(?User $creator = null, array $over = []): CreatorPuzzle
    {
        return e28_published($creator ?? e28_creator(), 'text', $over);
    }

    /** لاعب موثَّق جرّب العمل مرة بإجابة خاطئة (يكفي لأهلية التقييم: المحاولة لا الحل). */
    function e29_player_who_played(CreatorPuzzle $work, ?User $user = null): User
    {
        $user ??= e16User();
        app(CommunityPlayService::class)->attempt($user, $work->fresh(), 'إجابة خاطئة تمامًا');

        return $user;
    }

    function e29_rate(User $user, CreatorPuzzle $work, int $rating): array
    {
        return app(CommunityRatingService::class)->set($user, $work->fresh(), $rating);
    }

    /** عدد من اللاعبين يقيّم كلٌّ منهم العمل بقيمة من المصفوفة (يجرّبون ثم يقيّمون). @return list<User> */
    function e29_ratings(CreatorPuzzle $work, array $values): array
    {
        $users = [];

        foreach ($values as $value) {
            $user = e29_player_who_played($work);
            e29_rate($user, $work, $value);
            $users[] = $user;
        }

        return $users;
    }

    function e29_favorite(User $user, CreatorPuzzle $work): array
    {
        return app(CommunityFavoriteService::class)->add($user, $work->fresh());
    }

    function e29_follow(User $user, User $creator): array
    {
        return app(CreatorFollowService::class)->follow($user, $creator->creatorProfile()->first());
    }

    function e29_collection(User $user, string $title = 'مجموعتي المفضلة'): \App\Models\UserCollection
    {
        return app(CollectionService::class)->create($user, $title);
    }

    function e29_profile(User $creator): CreatorProfile
    {
        return $creator->creatorProfile()->first();
    }

    /** يصالح ظهور أعمال مستخدم (كما يفعل التجميد/الاستعادة). */
    function e29_reconcile_visibility(User $creator): void
    {
        app(CreatorVisibilityService::class)->reconcileForUser($creator->fresh());
    }

    /** لقطة خام لعدّادات عمل/مبدع. */
    function e29_counters(CreatorPuzzle $work): array
    {
        $row = CreatorPuzzle::query()->whereKey($work->id)->first(['ratings_count', 'ratings_sum', 'favorites_count']);

        return ['count' => (int) $row->ratings_count, 'sum' => (int) $row->ratings_sum, 'favorites' => (int) $row->favorites_count];
    }

    function e29_followers(User $creator): int
    {
        return (int) CreatorProfile::query()->whereKey(e29_profile($creator)->id)->value('followers_count');
    }

    /** إخفاء إشرافي رسمي لعمل. */
    function e29_hide(CreatorPuzzle $work, ?User $moderator = null): void
    {
        $moderator ??= e28_reviewer();
        app(CreatorVisibilityService::class)->hide($work->fresh(), $moderator, 'مخالفة تجريبية للاختبار.');
    }

    function e29_unhide(CreatorPuzzle $work, ?User $moderator = null): void
    {
        $moderator ??= e28_reviewer();
        app(CreatorVisibilityService::class)->restore($work->fresh(), $moderator);
    }

    /** يستخرج قسمًا من الصفحة بوسمه (aria-label) كي لا تلتبس عناوين بطاقاته بنص جرس الإشعارات الذي قد يحمل العناوين نفسها. '' إن لم يوجد. */
    function e29_section(string $html, string $label): string
    {
        return preg_match('/<section aria-label="'.preg_quote($label, '/').'".*?<\/section>/su', $html, $m) === 1 ? $m[0] : '';
    }

    /** قسم الخلاصة. */
    function e29_feed_section(string $html): string
    {
        return e29_section($html, 'أحدث أحجيات المتابَعين');
    }
}
`````


---

## B) MODIFIED FILES — ملفات كاملة (استبدال) (20 ملفًا)

### `.env.example`

`sha256: 40bc10fa86581a7b6341a729639153a42716d2195bfbdbe8afac787f86bfe28a`

`````dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb

# E21: Laravel Reverb (خادم WebSocket من الطرف الأول). عبّئ القيم محليًا/بالإنتاج: لا أسرار حقيقية هنا.
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"

# ==============================================================================
# E24: الأداء / التوسّع / الموثوقية / المراقبة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# ==============================================================================
# CACHE_STORE=database يتطلب جدولَي cache وcache_locks (تنشئهما migration 2026_10_18_000001 بعد php artisan migrate).
# توصية الإنتاج متعدد الخوادم (توصية بنية تحتية، غير مفروضة محليًا): CACHE_STORE=redis و SESSION_DRIVER=redis و QUEUE_CONNECTION=redis
# مع REDIS_CLIENT=phpredis؛ أبقِ الجلسات/الكاش/الطوابير على قواعد Redis (DB) منفصلة. المجدول onOneServer يحتاج كاشًا مشتركًا بين الخوادم.
# DB_QUEUE_RETRY_AFTER=90          # يجب أن يظل أكبر من timeout أي Job (80ث) كي لا تُلتقط مهمة ما زالت تعمل
# REDIS_QUEUE_RETRY_AFTER=90

# خلف موازن حِمل/وكيل عكسي: بدونها يرى التطبيق IP الوكيل بدل IP العميل (يفسد حدود المعدّل وبصمات الاحتيال).
# فارغ/غير معرَّف = لا وكلاء موثوقة (آمن افتراضيًا)؛ "*" = كل الوكلاء (فقط إن كان التطبيق غير قابل للوصول إلا عبر الموازن)؛ أو قائمة IP/CIDR مفصولة بفواصل.
# TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12

# المجدول: نفّذ كل مهمة على خادم واحد فقط عند تعدّد الخوادم (يحتاج كاشًا مشتركًا). على خادم واحد لا أثر له.
# PERF_SCHEDULER_ONE_SERVER=true

# تتبّع الجهاز (الاحتيال): مرة كل N ثانية لكل (مستخدم، IP، جهاز) بدل كل طلب. 0 = السلوك القديم (كل طلب).
# PERF_DEVICE_SIGHTING_THROTTLE=60

# مدد الكاش للعرض فقط (ثوانٍ، محدودة دائمًا). لا تُخزَّن بيانات صلاحيات أو أرصدة أو مكافآت.
# PERF_LEVEL_CATALOG_TTL=3600
# PERF_BRANDING_URLS_TTL=600
# PERF_LEADERBOARD_TTL=60

# المراقبة: معرّف الطلب X-Request-ID، وسجل الطلبات البطيئة (لا أجسام/كوكيز/رؤوس/سلسلة استعلام). 0 = تعطيل السجل البطيء.
# PERF_REQUEST_ID=true
# PERF_SLOW_REQUEST_MS=1500
# PERF_SLOW_REQUEST_SAMPLE=1.0
# فحص الجاهزية GET /ready (قاعدة البيانات + الكاش) ومهلته؛ الرمز اختياري (الرأس X-Health-Token). GET /up الأصلي للحياة فقط.
# PERF_READINESS_TIMEOUT_MS=1500
# PERF_READINESS_TOKEN=

# مهل اتصال PHP -> Reverb للبثّ المتزامن (ثوانٍ). قصيرة كي لا يحجز Reverb المعلّق عامل PHP-FPM ثلاثين ثانية.
# REVERB_HTTP_CONNECT_TIMEOUT=1
# REVERB_HTTP_TIMEOUT=3

# ==============================================================================
# E25: التشغيل بالإنتاج / الأمان / النشر / الاستعادة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# القيم هنا placeholders فقط: لا أسرار حقيقية بهذا الملف ولا بأي ملف بالمستودع. أسرار الإنتاج من مدير أسرار المزوّد أو متغيرات بيئة محميّة (docs/environment-variables.md).
# ==============================================================================
# ملخص عقد الإنتاج (php artisan production:check يفحصه ويعطي PASS/WARN/FAIL بلا طباعة أي قيمة سرية):
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://<نطاقك>  SESSION_SECURE_COOKIE=true
#   DB_CONNECTION=mysql (أو pgsql)  QUEUE_CONNECTION=database|redis  MAIL_MAILER=smtp  LOG_STACK=daily
#   BROADCAST_CONNECTION=reverb مع REVERB_SCHEME=https و REVERB_HOST=<نطاق> و REVERB_ALLOWED_ORIGINS=<نطاقك>

# كوكي الجلسة: إلزامي true بالإنتاج على HTTPS (يمنع إرسالها عبر HTTP). محليًا اتركه غير مضبوط.
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax

# هوية الإصدار (اختيارية): تُضبط عند كل نشر (SHA مختصر أو وسم). تظهر في /ready ومركز العمليات وكل سطر سجل (release). الصيغة [A-Za-z0-9._-] حتى 64 خانة.
# APP_RELEASE=
# RELEASE_SHA=

# السجلات: بالإنتاج استعمل daily مع احتفاظ محدود (القناة single بلا تدوير ولا حد حجم). LOG_LEVEL=info أو warning بدل debug.
# LOG_STACK=daily
# LOG_DAILY_DAYS=14

# Reverb: قيّد الأصول المسموحة لاتصالات WebSocket (أسماء مضيفين مفصولة بفواصل، بلا http://). الافتراضي * (يسمح للجميع).
# REVERB_ALLOWED_ORIGINS=ahjiyat.example.com

# أول مدير بالإنتاج فقط (AdminUserSeeder): لا كلمة مرور افتراضية بالإنتاج. ضعهما مؤقتًا بالبيئة لأول تشغيل ثم احذفهما فورًا. ≥ 12 خانة.
# ADMIN_BOOTSTRAP_EMAIL=
# ADMIN_BOOTSTRAP_PASSWORD=

# نبض المجدول وتنبيهات الطابور (ثوانٍ؛ كلها اختيارية).
# OPS_SCHEDULER_STALE_SECONDS=300
# OPS_SCHEDULER_DOWN_SECONDS=900
# OPS_READY_FAILS_ON_SCHEDULER=false     # true: /ready يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد)
# OPS_QUEUE_BACKLOG_WARN_SECONDS=900
# OPS_FAILED_JOBS_WARN=1

# الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
# OPS_ALLOW_DESTRUCTIVE_COMMANDS=false

# E26 - أمان التطبيق (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/security-e26.md.
# SECURITY_LOGIN_MAX_ATTEMPTS=5              # محاولات دخول فاشلة لكل (بريد+IP) قبل القفل المؤقت
# SECURITY_LOGIN_DECAY_SECONDS=300           # مدة القفل بالثواني
# SECURITY_LOGIN_IP_MAX_ATTEMPTS=30          # سقف الفشل لكل IP (رش كلمات المرور). أعلى بكثير كي لا يضرّ شبكة مشتركة
# SECURITY_API_EXPIRY_MINUTES=43200       # عمر رمز API (30 يومًا). 0 = بلا انتهاء (غير مستحسن)
# SECURITY_CSP_BASELINE=true                 # CSP أساسي (frame-ancestors/base-uri/object-src) لا يمسّ تنفيذ السكربتات
# REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
# SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
# SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)

# E27 - البحث العام والاكتشاف والرائج والتوصيات (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/search-discovery-e27.md.
# SEARCH_DRIVER=database                     # السائق الوحيد حاليًا. قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع). محرك خارجي = تنفيذ جديد لـSearchBackend
# SEARCH_MIN_LENGTH=2                        # أقل طول للمصطلح (حروف بعد التطبيع)
# SEARCH_MIN_LENGTH_GUEST_PLAYERS=3          # الضيف يحتاج 3 أحرف للبحث عن لاعبين (يحدّ التخمين)
# SEARCH_PARTIAL_MAX_ID=10000                # أكبر معرّف بجدول يُسمح عنده بالمطابقة الجزئية (وسط الكلمة/الحروف العربية المتقاربة). فوقه: بادئة العمود فقط. هذا مؤشر الانتقال لمحرك بحث/FULLTEXT
# SEARCH_TRENDING_WINDOW_DAYS=7              # نافذة «الرائج»
# SEARCH_TRENDING_TTL=300                    # عمر لقطة الرائج بالثواني (معرّفات ودرجات فقط)
# SEARCH_TRENDING_MIN_PLAYERS=2              # أقل عدد لاعبين مميَّزين كي تظهر أحجية بالرائج

# E28 - منظومة المبدعين وأحجيات المجتمع (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/creator-ecosystem-e28.md.
# أحجيات المجتمع بلا مكافآت اقتصادية بأي إعداد (جواهر/XP = 0 ثابت بالكود، لا مفتاح بيئة له).
# CREATOR_PUZZLE_MAX_ATTEMPTS=3              # عدد محاولات اللاعب لكل أحجية مجتمع (سيرفري، لا يحدده المبدع)
# CREATOR_MAX_WORKS=100                      # سقف أعمال المبدع الواحد
# CREATOR_MAX_OPEN_DRAFTS=20                 # سقف المسودات المفتوحة لكل مبدع
# CREATOR_MAX_PENDING=5                      # سقف الأعمال قيد المراجعة معًا لكل مبدع
# CREATOR_IMAGE_MAX_KB=4096                  # أقصى حجم صورة أحجية (كيلوبايت). JPEG/PNG/WEBP فقط، لا SVG
# CREATOR_ANALYTICS_TTL=120                  # عمر لقطة تحليلات المبدع بالثواني (أرقام مجمَّعة فقط)
# CREATOR_TRENDING_MIN_PLAYERS=2             # أقل عدد لاعبين مميَّزين كي يظهر عمل بقسم «رائج من المجتمع»

# E29 - التفاعل المجتمعي: التقييمات والمفضلة ومتابعة المبدعين والمجموعات الشخصية والأعلى تقييمًا (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/community-engagement-e29.md.
# التفاعل بلا أي سلطة اقتصادية بأي إعداد (لا جواهر/XP/مهام/سلسلة/إنجازات/صدارة): لا مفتاح بيئة لذلك.
# ENGAGEMENT_RATING_DISPLAY_MIN=3            # أقل عدد تقييمات لإظهار «المتوسط» علنًا (تحته يُعرض العدد فقط)
# ENGAGEMENT_TOP_MIN_RATINGS=5               # أقل عدد تقييمات كي يدخل العمل ترتيب «الأعلى تقييمًا»
# ENGAGEMENT_TOP_PRIOR_MEAN=3.5              # المتوسط الافتراضي للانكماش البايزي: score = (sum + mean*weight) / (count + weight)
# ENGAGEMENT_TOP_PRIOR_WEIGHT=5              # وزن المتوسط الافتراضي (كم «تقييمًا وهميًا»)؛ كلما كبر احتاج الوصول للقمة تقييمات أكثر
# ENGAGEMENT_TOP_CACHE_TTL=300               # عمر لقطة الأعلى تقييمًا بالثواني (معرّفات ودرجات فقط؛ الظهور يُفحص حيًّا)
# ENGAGEMENT_CREATOR_MIN_RATINGS=5           # أقل مجموع تقييمات لإظهار ملخّص جودة المبدع بصفحته العامة
# ENGAGEMENT_MAX_FOLLOWS=500                 # سقف من يتابعهم المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTIONS=20              # سقف مجموعات المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTION_ITEMS=100        # سقف عناصر المجموعة الواحدة
# ENGAGEMENT_NOTIFY_ON_REVISION=true         # إشعار المتابعين أيضًا عند اعتماد نسخة جديدة لعمل منشور سابقًا
`````

### `routes/web.php`

`sha256: 6cc538e380e280d566b571660ecb3d7a8b909ba25ed43cf04d2231669ef801a6`

`````php
<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignStepController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\ProfileCustomizationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PuzzleController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SeasonController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// E27: البحث العام والاكتشاف (قراءة فقط، عامة للضيوف). /search/suggest نقطة JSON للاقتراحات الفورية بمحدّد معدّل أضيق للضيف.
Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->middleware('throttle:global-search')->name('search.index');
Route::get('/search/suggest', [\App\Http\Controllers\SearchController::class, 'suggest'])->middleware('throttle:search-suggest')->name('search.suggest');
Route::get('/discover', [\App\Http\Controllers\DiscoverController::class, 'index'])->middleware('throttle:discover')->name('discover.index');

Route::get('/puzzles', [PuzzleController::class, 'index'])->name('puzzles.index');
Route::get('/puzzles/category/{category:slug}', [PuzzleController::class, 'index'])->name('puzzles.category');
Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show'])->name('puzzles.show');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

Route::get('/store', [StoreController::class, 'index'])->name('store.index');
Route::get('/store/items/{item:slug}', [StoreController::class, 'show'])->name('store.items.show');

Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');

// E17: المنافسات العامة (قراءة فقط) - قائمة وصفحة حدث مع الترتيب
Route::get('/competitions', [\App\Http\Controllers\CompetitionController::class, 'index'])->name('competitions.index');
// E19: الفرق. الصفحات الحرفية (create/mine/invitations/leaderboard) تسبق {team:slug} حتى لا يلتقطها المسار الديناميكي (والمعرّفات محجوزة بـconfig/teams.php).
Route::get('/teams', [\App\Http\Controllers\TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/leaderboard', [\App\Http\Controllers\TeamController::class, 'leaderboard'])->name('teams.leaderboard');
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/create', [\App\Http\Controllers\TeamController::class, 'create'])->name('teams.create');
    Route::get('/teams/mine', [\App\Http\Controllers\TeamController::class, 'mine'])->name('teams.mine');
    Route::get('/teams/invitations', [\App\Http\Controllers\TeamMembershipController::class, 'invitations'])->name('teams.invitations');
});
// E20: تحدّيات الفرق (حرفية قبل {team:slug}) وبطولات الفرق. الصفحات الحرفية بين الفرق: challenges محجوز كمعرّف.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'index'])->name('teams.challenges.index');
    Route::get('/teams/challenges/create', [\App\Http\Controllers\TeamChallengeController::class, 'create'])->name('teams.challenges.create');
});
Route::get('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'show'])->name('teams.challenges.show');
Route::get('/team-championships', [\App\Http\Controllers\TeamChampionshipController::class, 'index'])->name('team-championships.index');
Route::get('/team-championships/{championship:slug}', [\App\Http\Controllers\TeamChampionshipController::class, 'show'])->name('team-championships.show');
// E21: الدردشة (مباشرة/فريق/عامة) + عمليات الرسائل. كلها لموثَّق غير مجمَّد؛ التعديلات POST/PATCH/DELETE فقط (لا GET يغيّر حالة). /teams/{team}/chat لا يتعارض مع /teams/{team}.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/messages', [\App\Http\Controllers\Chat\DirectMessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'show'])->name('messages.direct');
    Route::post('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'send'])->middleware('throttle:chat-dm-send')->name('messages.direct.send');
    Route::get('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'show'])->name('teams.chat');
    Route::post('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'send'])->middleware('throttle:chat-team-send')->name('teams.chat.send');
    Route::get('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'show'])->name('community.chat');
    Route::post('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'send'])->middleware('throttle:chat-global-send')->name('community.chat.send');

    Route::get('/chat/threads/{thread:public_id}/messages', [\App\Http\Controllers\Chat\ChatMessageController::class, 'older'])->name('chat.messages');
    Route::post('/chat/threads/{thread:public_id}/read', [\App\Http\Controllers\Chat\ChatMessageController::class, 'read'])->middleware('throttle:chat-read')->name('chat.read');
    Route::patch('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'update'])->middleware('throttle:chat-edit')->name('chat.messages.update');
    Route::delete('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'destroy'])->middleware('throttle:chat-edit')->name('chat.messages.destroy');
    Route::post('/chat/messages/{message}/report', [\App\Http\Controllers\Chat\ChatMessageController::class, 'report'])->middleware('throttle:chat-report')->name('chat.messages.report');
    Route::post('/chat/messages/{message}/hide', [\App\Http\Controllers\Chat\ChatMessageController::class, 'hide'])->middleware('throttle:chat-moderate')->name('chat.messages.hide');
    Route::post('/chat/messages/{message}/restore', [\App\Http\Controllers\Chat\ChatMessageController::class, 'restore'])->middleware('throttle:chat-moderate')->name('chat.messages.restore');
});
Route::get('/teams/{team:slug}', [\App\Http\Controllers\TeamController::class, 'show'])->name('teams.show');

// E18: قاعة الأمجاد (حرفية قبل {event:slug} حتى لا يلتقطها المسار الديناميكي)
Route::get('/competitions/hall-of-fame', [\App\Http\Controllers\CompetitionHallOfFameController::class, 'index'])->name('competitions.hall-of-fame');
Route::get('/competitions/{event:slug}', [\App\Http\Controllers\CompetitionController::class, 'show'])->name('competitions.show');
Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');

Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
Route::get('/campaigns/{campaign:slug}', [CampaignController::class, 'show'])->name('campaigns.show');

Route::get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
Route::get('/seasons/{season:slug}', [SeasonController::class, 'show'])->name('seasons.show');

Route::get('/players/{user:public_id}', [PlayerProfileController::class, 'show'])->name('players.show');
Route::get('/players/{user:public_id}/competitive', [\App\Http\Controllers\PlayerCompetitiveController::class, 'show'])->name('players.competitive');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset-submit')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:email-verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification')->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:account-sensitive')->name('profile.update');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:password-confirm');

    Route::middleware(['verified', 'account.active'])->group(function () {
        // E19: إجراءات الفرق (POST/PATCH/DELETE فقط عدا صفحة الإدارة). التفويض من الدور الفعلي بالقاعدة، لا من الطلب.
        // {user:public_id} ليس ابنًا للفريق بعلاقة (Team::users) فيُعطَّل الربط المحصور هناك والخدمة تتحقق من العضوية؛ أما {invitation}/{joinRequest} فمحصوران بعلاقتي الفريق: 404 تلقائي لمورد فريق آخر.
        Route::post('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'store'])->middleware('throttle:team-challenge-create')->name('teams.challenges.store');
        Route::post('/teams/challenges/{challenge:public_id}/accept', [\App\Http\Controllers\TeamChallengeController::class, 'accept'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.accept');
        Route::post('/teams/challenges/{challenge:public_id}/decline', [\App\Http\Controllers\TeamChallengeController::class, 'decline'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.decline');
        Route::delete('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'cancel'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.cancel');
        Route::put('/teams/challenges/{challenge:public_id}/roster', [\App\Http\Controllers\TeamChallengeController::class, 'roster'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.roster');
        Route::post('/teams/challenges/{challenge:public_id}/start', [\App\Http\Controllers\TeamChallengePlayController::class, 'start'])->middleware('throttle:competitive-play')->name('teams.challenges.start');
        Route::post('/teams/challenges/{challenge:public_id}/submit', [\App\Http\Controllers\TeamChallengePlayController::class, 'submit'])->middleware('throttle:competitive-play')->name('teams.challenges.submit');
        Route::post('/teams', [\App\Http\Controllers\TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
        Route::post('/teams/invitations/{invitation:public_id}/accept', [\App\Http\Controllers\TeamMembershipController::class, 'accept'])->middleware('throttle:team-actions')->name('teams.invitations.accept');
        Route::post('/teams/invitations/{invitation:public_id}/decline', [\App\Http\Controllers\TeamMembershipController::class, 'decline'])->middleware('throttle:team-actions')->name('teams.invitations.decline');
        Route::post('/teams/{team:slug}/join', [\App\Http\Controllers\TeamMembershipController::class, 'join'])->middleware('throttle:team-join')->name('teams.join');
        Route::post('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'requestJoin'])->middleware('throttle:team-join')->name('teams.requests.store');
        Route::delete('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'cancelRequest'])->middleware('throttle:team-actions')->name('teams.requests.cancel');
        Route::delete('/teams/{team:slug}/membership', [\App\Http\Controllers\TeamMembershipController::class, 'leave'])->middleware('throttle:team-actions')->name('teams.leave');
        Route::get('/teams/{team:slug}/manage', [\App\Http\Controllers\TeamManagementController::class, 'manage'])->name('teams.manage');
        Route::patch('/teams/{team:slug}', [\App\Http\Controllers\TeamManagementController::class, 'update'])->middleware('throttle:team-actions')->name('teams.update');
        Route::post('/teams/{team:slug}/deactivate', [\App\Http\Controllers\TeamManagementController::class, 'deactivate'])->middleware('throttle:team-actions')->name('teams.deactivate');
        Route::post('/teams/{team:slug}/invitations/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'invite'])->middleware('throttle:team-invite')->withoutScopedBindings()->name('teams.invitations.store');
        Route::delete('/teams/{team:slug}/invitations/{invitation:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'cancelInvitation'])->middleware('throttle:team-actions')->name('teams.invitations.cancel');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/accept', [\App\Http\Controllers\TeamManagementController::class, 'acceptRequest'])->middleware('throttle:team-actions')->name('teams.requests.accept');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/decline', [\App\Http\Controllers\TeamManagementController::class, 'declineRequest'])->middleware('throttle:team-actions')->name('teams.requests.decline');
        Route::delete('/teams/{team:slug}/members/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'removeMember'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.remove');
        Route::patch('/teams/{team:slug}/members/{user:public_id}/role', [\App\Http\Controllers\TeamManagementController::class, 'changeRole'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.role');
        Route::post('/teams/{team:slug}/transfer/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'transfer'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.transfer');

        Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('puzzles.attempt');
        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])
            ->middleware('throttle:puzzle-hint')->name('puzzles.hint');

        Route::post('/puzzles/{puzzle}/session', [GameSessionController::class, 'store'])
            ->middleware('throttle:game-session-start')->name('game-sessions.start');
        Route::post('/game-sessions/{session}/reveal', [GameSessionController::class, 'reveal'])
            ->middleware('throttle:game-session-reveal')->name('game-sessions.reveal');

        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])
            ->middleware('throttle:game-actions')->name('challenges.join');

        Route::get('/campaigns/{campaign:slug}/steps/{step}', [CampaignStepController::class, 'show'])
            ->name('campaigns.steps.show');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/complete', [CampaignStepController::class, 'complete'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.complete');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/reflect', [CampaignStepController::class, 'reflect'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.reflect');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/attempt', [CampaignStepController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('campaigns.steps.attempt');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/session', [CampaignStepController::class, 'startSession'])
            ->middleware('throttle:game-session-start')->name('campaigns.steps.session');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

        Route::get('/redemption', [RedemptionController::class, 'index'])->name('redemption.index');
        Route::get('/redemption/create', [RedemptionController::class, 'create'])->name('redemption.create');
        Route::post('/redemption', [RedemptionController::class, 'store'])
            ->middleware('throttle:redemption')->name('redemption.store');

        Route::post('/store/items/{item}/purchase', [StoreController::class, 'purchase'])
            ->middleware('throttle:store-purchase')->name('store.items.purchase');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::get('/profile/customize', [ProfileCustomizationController::class, 'edit'])->name('profile.customize');
        Route::post('/profile/cosmetics/{item}/equip', [ProfileCustomizationController::class, 'equip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.equip');
        Route::delete('/profile/cosmetics/{slot}/unequip', [ProfileCustomizationController::class, 'unequip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.unequip');
        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
            ->middleware('throttle:account-sensitive')->name('profile.visibility.update');

        // E12: صفحة التقدُّم - قراءة فقط، لا Mutation عبر أي مسار هنا.
        Route::get('/progress', [\App\Http\Controllers\PlayerProgressionController::class, 'show'])->name('progress.show');
        Route::get('/quests', [\App\Http\Controllers\PlayerQuestsController::class, 'show'])->name('quests.show');

        // E15: مركز الإشعارات. GET = عرض فقط. كل تعديل POST/PUT/DELETE بـCSRF وبملكية صارمة داخل المتحكّم.
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->whereUuid('id')->name('notifications.destroy');

        // E16: الأصدقاء والحظر. GET = عرض فقط. كل تعديل POST/PATCH/DELETE بـCSRF؛ الطرف الآخر بـpublic_id من المسار، والطرف الحالي
        // دائمًا المستخدم المصادَق (لا user_id من النموذج). إرسال الطلبات بتحديد معدّل ثنائي.
        Route::prefix('friends')->name('friends.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FriendsController::class, 'index'])->name('index');
            Route::get('/search', [\App\Http\Controllers\PlayerSearchController::class, 'index'])->middleware('throttle:player-search')->name('search');
            Route::patch('/settings', [\App\Http\Controllers\FriendsController::class, 'updateSettings'])->middleware('throttle:friend-actions')->name('settings');

            Route::post('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'store'])->middleware('throttle:friend-requests')->name('requests.store');
            Route::post('/requests/{user:public_id}/accept', [\App\Http\Controllers\FriendRequestController::class, 'accept'])->middleware('throttle:friend-actions')->name('requests.accept');
            Route::post('/requests/{user:public_id}/decline', [\App\Http\Controllers\FriendRequestController::class, 'decline'])->middleware('throttle:friend-actions')->name('requests.decline');
            Route::delete('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'cancel'])->middleware('throttle:friend-actions')->name('requests.cancel');
            Route::delete('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'destroy'])->middleware('throttle:friend-actions')->name('blocks.destroy');
            Route::post('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'store'])->middleware('throttle:friend-actions')->name('blocks.store');
            Route::delete('/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'removeFriend'])->middleware('throttle:friend-actions')->name('remove');
        });

        // E17-A: تحدّيات الأصدقاء. الخصم بـpublic_id من المسار والتحدي بـpublic_id (ULID)، والمصادَق هو الطرف دائمًا. GET = عرض فقط.
        Route::prefix('friends/challenges')->name('friends.challenges.')->group(function () {
            $c = \App\Http\Controllers\FriendChallengeController::class;

            Route::get('/', [$c, 'index'])->name('index');
            Route::get('/new/{user:public_id}', [$c, 'create'])->name('create');
            Route::post('/new/{user:public_id}', [$c, 'store'])->middleware('throttle:friend-challenges')->name('store');
            Route::get('/{challenge:public_id}', [$c, 'show'])->name('show');
            Route::post('/{challenge:public_id}/accept', [$c, 'accept'])->middleware('throttle:friend-actions')->name('accept');
            Route::post('/{challenge:public_id}/decline', [$c, 'decline'])->middleware('throttle:friend-actions')->name('decline');
            Route::delete('/{challenge:public_id}', [$c, 'cancel'])->middleware('throttle:friend-actions')->name('cancel');
            Route::post('/{challenge:public_id}/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::post('/{challenge:public_id}/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });

        // E17-B/C: تسجيل ولعب المنافسات. الحدث بـslug من المسار؛ لا score/winner/rank من العميل.
        Route::prefix('competitions/{event:slug}')->name('competitions.')->group(function () {
            $c = \App\Http\Controllers\CompetitionPlayController::class;

            Route::post('/register', [$c, 'register'])->middleware('throttle:competitive-register')->name('register');
            Route::delete('/register', [$c, 'leave'])->middleware('throttle:competitive-register')->name('leave');
            Route::post('/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::get('/play', [$c, 'play'])->name('play');
            Route::post('/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });


    });





});
// E14 (بند 605): تحويل نقر إعلان - عام، معرِّف Creative فقط، لا رابط من الاستعلام.
Route::get('/ads/click/{creative}', \App\Http\Controllers\AdClickController::class)
    ->middleware('throttle:ad-click')->name('ads.click');

// E28: منظومة المبدعين وأحجيات المجتمع (ملف مسارات مستقل بالسياق web نفسه).
require __DIR__.'/creator.php';
require __DIR__.'/engagement.php';
`````

### `app/Providers/AppServiceProvider.php`

`sha256: 96aea0acbf75e4b0de40d3c7754e0bcd85e010a9f7f1f097fb89057b45b50804`

`````php
<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Teams\TeamService;
use App\Support\Security\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use App\Support\ReleaseInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
        });
    }

    public function boot(): void
    {
        // E22: قائمة المحادثات الجانبية لصفحة الغرفة (قراءة فقط، بلا إنشاء غرف عند العرض).
        \Illuminate\Support\Facades\View::composer('chat.room', fn ($view) => $view->with('sidebar', app(\App\Services\Chat\ChatSidebar::class)->for(auth()->user())));

        // E19-E20: قبل حذف أي حساب يُعالَج ما يملكه من فرق (نقل الملكية لأقدم مشرف/عضو، وإلا أرشفة): لا فريق يتيم بلا مالك.
        // فشل المعالجة لا يمنع الحذف (owner_id يصير NULL بقيد القاعدة والفريق يبقى قابلًا لتدخل الإدارة).
        User::deleting(function (User $user) {
            try {
                app(TeamService::class)->releaseOwnershipFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E29: قبل حذف أي حساب تُنقَص عدّادات ما تفاعل معه (مفضلة/متابعات) فتبقى المجمَّعات دقيقة؛ الصفوف نفسها تتتابع بقيد القاعدة، والتقييمات تبقى مجهولة الهوية.
        // فشل التنظيف لا يمنع الحذف؛ community:reconcile-engagement يصلح أي انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Engagement\EngagementCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });

        $this->registerRateLimiters();
        $this->registerOperationalGuards();
    }

    /**
     * E25: حواجز التشغيل بالإنتاج.
     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
     */
    protected function registerOperationalGuards(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));

        if (($release = ReleaseInfo::short()) !== null) {
            Log::shareContext(['release' => $release]);
        }
    }

    /**
     * E8: بنية Rate Limiting مركزية واحدة - بدل أرقام مبعثرة (throttle:20,1)
     * بكل Route مباشرة. المفتاح: user ID للمصادَق عليهم (بند 26 - نفس سلوك
     * Laravel الافتراضي أصلاً بمجموعات auth/verified)، أو IP للضيوف حصراً
     * (تسجيل/استرجاع كلمة سر - لا يوجد مستخدم مصادَق عليه بعد أصلاً).
     */
    protected function registerRateLimiters(): void
    {
        // E8: لم تكن محمية إطلاقاً سابقاً (بند 21) - تسجيل حساب جديد.
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });

        // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
            ];
        });

        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
        RateLimiter::for('password-reset-submit', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
        });

        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
        RateLimiter::for('account-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('puzzle-hint', function (Request $request) {
            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // أفعال اللعب (بند 24) - نفس الأرقام الحالية بالضبط، فقط مُسمّاة
        // ومركزية الآن. لا نخفّضها - قد تكسر لعبًا طبيعيًا سريعًا.
        RateLimiter::for('puzzle-attempt', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-start', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-reveal', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E14 (بند 606): تحويل نقر الإعلان - عام (قد يشمل زائرًا غير مسجَّل)، معدَّل معقول يمنع إساءة الاستخدام بلا إزعاج تصفُّح عادي.
        RateLimiter::for('ad-click', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // طلبات الاستبدال (بند 25) - نفس الحد الحالي: 5 كل ساعة.
        RateLimiter::for('redemption', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->user()?->id ?: $request->ip());
        });

                // E10: مشتريات المتجر الافتراضي - مفتاح المستخدم (لا IP، دائماً
        // مُصادَق عليه بهذه المرحلة).
        RateLimiter::for('store-purchase', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        // E16: الأصدقاء. إرسال الطلبات بالدقيقة وبالساعة (مع cooldown إعادة الإرسال داخل FriendshipService)؛ بقية الإجراءات
        // والبحث أوسع. المفتاح المستخدم المصادَق (لا IP). لا يمنع الاستخدام الطبيعي.
        RateLimiter::for('friend-requests', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(10)->by("friend-req-min:{$key}"), Limit::perHour(60)->by("friend-req-hour:{$key}")];
        });

        RateLimiter::for('friend-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E17: إنشاء تحدٍّ (حد ضيق: يولّد إشعارًا لشخص آخر)، تسجيل المنافسات، ولعب/إرسال نتيجة، وبقية إجراءات التحديات.
        RateLimiter::for('friend-challenges', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(5)->by("fc-min:{$key}"), Limit::perHour(20)->by("fc-hour:{$key}")];
        });

        RateLimiter::for('competitive-register', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // E19: الفرق. إنشاء نادر؛ الدعوات/الطلبات تحت حد يمنع الإزعاج؛ إجراءات الإدارة والقبول معتدلة.
        RateLimiter::for('team-create', fn (Request $request) => [Limit::perMinute(2)->by('tc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('tch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-invite', fn (Request $request) => [Limit::perMinute(10)->by('ti:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('tih:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-join', fn (Request $request) => [Limit::perMinute(8)->by('tj:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('tjh:'.($request->user()?->id ?: $request->ip()))]);
        // E20: تحدّيات الفرق (الإنشاء نادر نسبيًا؛ القبول/الرفض/الإلغاء/الروستر معتدلة؛ اللعب بحد competitive-play القائم).
        RateLimiter::for('team-challenge-create', fn (Request $request) => [Limit::perMinute(4)->by('tcc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('tcch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-challenge-actions', fn (Request $request) => Limit::perMinute(30)->by('tca:'.($request->user()?->id ?: $request->ip())));
        // E21: دردشة. الإرسال حسب النوع (العامة أشد + حماية دفعات)، والبلاغ والتعديل/الحذف والقراءة بحدود منفصلة. المفتاح: المستخدم لا IP.
        RateLimiter::for('chat-dm-send', fn (Request $request) => Limit::perMinute(30)->by('cdm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-team-send', fn (Request $request) => Limit::perMinute(30)->by('ctm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-global-send', fn (Request $request) => [
            Limit::perMinute(10)->by('cgm:'.($request->user()?->id ?: $request->ip())),
            Limit::perSecond(3, 10)->by('cgb:'.($request->user()?->id ?: $request->ip())),     // دفعة: 3 رسائل/10 ثوانٍ
        ]);
        RateLimiter::for('chat-report', fn (Request $request) => [Limit::perMinute(5)->by('crm:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('crh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('chat-edit', fn (Request $request) => Limit::perMinute(30)->by('ced:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-moderate', fn (Request $request) => Limit::perMinute(30)->by('cmo:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(120)->by('crd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('team-actions', fn (Request $request) => Limit::perMinute(40)->by('ta:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('competitive-play', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('player-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
        RateLimiter::for('global-search', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));

        // E28: المبدعون. كلها بمفتاح المستخدم لا IP. الطلب/الإنشاء/الإرسال (ويشمل السحب وإنشاء نسخة جديدة كي لا يُغرَق الإشراف بدورات إرسال/إلغاء) والبلاغات بحدّين دقيقة/ساعة.
        RateLimiter::for('creator-apply', fn (Request $request) => [Limit::perMinute(2)->by('cap:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('caph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-draft-create', fn (Request $request) => [Limit::perMinute(5)->by('cdc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('cdch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-submit', fn (Request $request) => [Limit::perMinute(5)->by('csb:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('csbh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-report', fn (Request $request) => [Limit::perMinute(5)->by('cpr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cprh:'.($request->user()?->id ?: $request->ip()))]);

        // E29: التفاعل المجتمعي. بمفتاح المستخدم لا IP (كلها خلف auth). حدّان: دقيقة (سرعة بشرية) + ساعة (سقف إغراق/نمط آلي).
        // التقييم/الحفظ يسمحان بتغيير الرأي والتنقل بين أعمال كثيرة، فالحدّ أوسع من المتابعة والمجموعات (أثرهما أثقل على بيانات الآخرين).
        RateLimiter::for('community-rating', fn (Request $request) => [Limit::perMinute(20)->by('crt:'.($request->user()?->id ?: $request->ip())), Limit::perHour(200)->by('crth:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-favorite', fn (Request $request) => [Limit::perMinute(30)->by('cfv:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('cfvh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-follow', fn (Request $request) => [Limit::perMinute(15)->by('cfl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(100)->by('cflh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-actions', fn (Request $request) => [Limit::perMinute(30)->by('cca:'.($request->user()?->id ?: $request->ip())), Limit::perHour(240)->by('ccah:'.($request->user()?->id ?: $request->ip()))]);

        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
        RateLimiter::for('cosmetic-equip', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
        
        // E12: فجوة سابقة على هذه المرحلة - مجموعة Middleware الافتراضية 'api'
        // (المُطبَّقة تلقائيًا على كل مسار بـroutes/api.php) تتضمَّن throttle:api،
        // ولم يكن هذا الاسم مُعرَّفًا إطلاقًا (لم يُختَبر أي مسار API قبل الآن).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }

}
`````

### `app/Models/CreatorProfile.php`

`sha256: 5c23c10cf8c24a858a2f33677a06eb5c7a50e0b0599945c172403c4a91824f04`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E28 - ملف المبدع (1:1 مع المستخدم). مصدر الحقيقة لأهلية الاستوديو: status = approved + حساب مفعَّل/غير مجمَّد (تفحصه CreatorEligibility).
 * لا تعيين جماعي: كل كتابة تمرّ بـCreatorApplicationService (التدقيق والإشعار والمصالحة معًا).
 */
class CreatorProfile extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_SUSPENDED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'followers_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function puzzles(): HasMany
    {
        return $this->hasMany(CreatorPuzzle::class);
    }

    /** E29: متابعات المبدع (هوية المتابعين لا تُعرض؛ followers_count عدّاد مشتقّ تكتبه CreatorFollowService). */
    public function follows(): HasMany
    {
        return $this->hasMany(CreatorFollow::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
`````

### `app/Models/CreatorPuzzle.php`

`sha256: 0a51d6129a098568bb0e5ff5915d1e9839b6685ca8f80997210a6036e47ba226`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E28 - العمل الإبداعي: هوية ثابتة (public_id) + لقطة النشر الحالية + الإخفاء/الأرشفة. المحتوى نفسه بالنسخ (CreatorPuzzleRevision).
 *
 * is_visible مشتقّة آليًا عند كل حفظ = (منشور) ∧ (غير مخفي) ∧ (غير مؤرشف): مصدر واحد للحقيقة تقرؤه كل الأسطح العامة (البحث/الاكتشاف/الصفحات)
 * بشرط واحد مفهرس بلا ضمّ. لا تُكتب يدويًا أبدًا. مصالحة التعليق/التجميد تضبط hidden_* عبر CreatorVisibilityService.
 */
class CreatorPuzzle extends Model
{
    public const HIDDEN_MODERATION = 'moderation';

    public const HIDDEN_CREATOR_SUSPENDED = 'creator_suspended';

    public const HIDDEN_ACCOUNT_FROZEN = 'account_frozen';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CreatorPuzzle $work) {
            $work->public_id ??= (string) Str::ulid();
        });

        static::saving(function (CreatorPuzzle $work) {
            $work->is_visible = $work->published_puzzle_id !== null
                && $work->hidden_at === null
                && $work->archived_at === null;
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'archived_at' => 'datetime',
            'hidden_at' => 'datetime',
            'is_visible' => 'boolean',
            'plays_count' => 'integer',
            'ratings_count' => 'integer',
            'ratings_sum' => 'integer',
            'favorites_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CreatorPuzzleRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'published_revision_id');
    }

    public function publishedPuzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class, 'published_puzzle_id');
    }

    public function publishedCategory(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'published_category_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommunityPuzzleReport::class);
    }

    /** E29: مصدر الحقيقة للتقييمات (العدّادات المجمَّعة بأعمدة العمل مشتقّة منها). */
    public function ratings(): HasMany
    {
        return $this->hasMany(CommunityPuzzleRating::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CommunityPuzzleFavorite::class);
    }

    /** متوسط التقييم بخانة عشرية واحدة، أو null بلا تقييمات. يقرأ العدّادين المجمَّعين فقط (بلا AVG/COUNT بكل بطاقة). */
    public function averageRating(): ?float
    {
        $count = (int) $this->getAttribute('ratings_count');

        return $count > 0 ? round(((int) $this->getAttribute('ratings_sum')) / $count, 1) : null;
    }

    public function scopeVisible($query)
    {
        return $query->where($query->getModel()->getTable().'.is_visible', true);
    }

    public function isPublished(): bool
    {
        return $this->published_puzzle_id !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
`````

### `app/Services/Notifications/NotificationType.php`

`sha256: 7c9918a3ff6cb2d5a331974b82567f6ab76b2de155b5b22b3ee0e792334a5a49`

`````php
<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق لأنواع الإشعارات المنفَّذة فعليًا (4). كل نوع يحدد فئته وأولويته وأيقونته ووجهته الداخلية
 * الوحيدة المسموحة ونصوصه - لا يأتي أي منها من الحدث أو من الإدارة أو من قاعدة البيانات.
 *
 * مصدر كل نوع (Event source → type):
 *  - AchievementUnlocked (حدث مجال جديد، يُطلَق بعد commit فتح الإنجاز) → achievement_unlocked
 *  - جدولة ساعية (ReEngagementService) على player_streaks → streak_at_risk
 *  - جدولة ساعية على player_streaks + مهام يومية قائمة → daily_quests_available
 *  - Illuminate\Auth\Events\PasswordReset (حدث موجود أصلًا) → security_password_reset
 *  - SeasonStarted (حدث مجال، SeasonLifecycleService: الموسم صار مباشرًا لأول مرة) → season_started
 *  - CampaignBecameAvailable (حدث مجال، CampaignLifecycleService: الحملة أُتيحت لأول مرة) → campaign_available
 *  - StageUnlockedForUser (حدث مجال لكل مستخدم، StageUnlockService بعد إكمال خطوة حقيقية) → stage_unlocked
 *  - CampaignCompletedForUser (حدث مجال لكل مستخدم، CampaignCompletionService بعد إكمال خطوة حقيقية) → campaign_completed،
 *    أو season_completed إن كانت الحملة مرتبطة بموسم منشور (القرار داخل المستمع: إشعار واحد فقط للإكمال نفسه)
 *  - LifecycleReminderService (مجدول ساعيًا، قراءة فقط) → season_ending_soon / campaign_ending_soon
 *  - FriendChallenge{Created,Accepted,Completed} (أحداث مجال بعد commit، FriendChallengeService) → friend_challenge_received/accepted/result_ready
 *  - CompetitiveRewardGranted (بعد منح فعلي ناجح بخدمة التوزيع) → competitive_reward_granted
 *  - TeamChallengeCreated/Accepted/Completed, TeamChampionshipStarted (الأمر الدوري)/Finalized (E20) → team_challenge_*, team_championship_* (التحدّيات: social؛ البطولات: competitive)
 *  - TeamInvitationCreated/Accepted, TeamJoinRequestCreated/Accepted, TeamMemberRemoved (أحداث مجال بعد commit بخدمات الفرق، E19) → team_* (فئة social؛ بلا إشعار للرفض)
 *  - CompetitiveLifecycleService (مجدول ساعيًا) → competitive_event_started/ending_soon؛ CompetitiveEventFinalized → competitive_event_result_ready
 *  - FriendRequestCreated / FriendshipAccepted (أحداث مجال بعد commit، FriendshipService) → friend_request_received / friend_request_accepted
 *  - اعتماد نشر (أول نشر أو نسخة جديدة) لمبدع له متابعون (E29، CreatorPublicationAnnouncer بعد الـcommit ← دفعات بالطابور) → creator_new_publication (فئة social؛ بلا إشعار على التقييم/المفضلة/اللعب)
 */
enum NotificationType: string
{
    case AchievementUnlocked = 'achievement_unlocked';
    case StreakAtRisk = 'streak_at_risk';
    case DailyQuestsAvailable = 'daily_quests_available';
    case SecurityPasswordReset = 'security_password_reset';
    case SeasonStarted = 'season_started';
    case CampaignAvailable = 'campaign_available';
    case StageUnlocked = 'stage_unlocked';
    case CampaignCompleted = 'campaign_completed';
    case SeasonCompleted = 'season_completed';
    case SeasonEndingSoon = 'season_ending_soon';
    case CampaignEndingSoon = 'campaign_ending_soon';
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case FriendChallengeReceived = 'friend_challenge_received';
    case FriendChallengeAccepted = 'friend_challenge_accepted';
    case FriendChallengeResultReady = 'friend_challenge_result_ready';
    case CompetitiveEventStarted = 'competitive_event_started';
    case CompetitiveEventEndingSoon = 'competitive_event_ending_soon';
    case CompetitiveEventResultReady = 'competitive_event_result_ready';
    case CompetitiveRewardGranted = 'competitive_reward_granted';
    case TeamInvitationReceived = 'team_invitation_received';
    case TeamInvitationAccepted = 'team_invitation_accepted';
    case TeamJoinRequestReceived = 'team_join_request_received';
    case TeamJoinRequestAccepted = 'team_join_request_accepted';
    case TeamMemberRemoved = 'team_member_removed';
    case TeamChallengeReceived = 'team_challenge_received';
    case TeamChallengeAccepted = 'team_challenge_accepted';
    case TeamChallengeResultReady = 'team_challenge_result_ready';
    case TeamChampionshipStarted = 'team_championship_started';
    case TeamChampionshipResultReady = 'team_championship_result_ready';
    case CreatorApproved = 'creator_approved';
    case CreatorRejected = 'creator_rejected';
    case SubmissionApproved = 'submission_approved';
    case SubmissionChangesRequested = 'submission_changes_requested';
    case SubmissionRejected = 'submission_rejected';
    case PublicationHidden = 'publication_hidden';
    case CreatorNewPublication = 'creator_new_publication';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
            self::StageUnlocked => NotificationCategory::Campaign,
            self::CampaignCompleted => NotificationCategory::Campaign,
            self::SeasonCompleted, self::SeasonEndingSoon => NotificationCategory::Season,
            self::CampaignEndingSoon => NotificationCategory::Campaign,
            self::FriendRequestReceived, self::FriendRequestAccepted, self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady,
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved,
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady,
            self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden, self::CreatorNewPublication => NotificationCategory::Social,
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted,
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => NotificationCategory::Competitive,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable, self::SeasonEndingSoon, self::CampaignEndingSoon, self::CompetitiveEventEndingSoon], true);
    }

    /** أعلى = أهم. اختيار الأولوية يتم بتقسيم الجمهور + الميزانية (راجع ReEngagementService). */
    public function priority(): int
    {
        return match ($this) {
            self::SecurityPasswordReset => 1000,
            self::StreakAtRisk => 100,
            self::DailyQuestsAvailable => 50,
            self::SeasonStarted => 20,
            self::CampaignAvailable => 15,
            self::StageUnlocked => 14,
            self::CampaignCompleted => 16,
            self::SeasonCompleted => 17,
            self::SeasonEndingSoon, self::CampaignEndingSoon => 70, // بين تحذير السلسلة (100) وتذكير المهام (50)
            self::FriendRequestReceived => 25,
            self::FriendRequestAccepted => 24,
            self::FriendChallengeReceived => 26,
            self::FriendChallengeAccepted => 23,
            self::FriendChallengeResultReady => 22,
            self::CompetitiveEventStarted => 21,
            self::CompetitiveEventEndingSoon => 70, // مع تذكيري النهاية الآخرين: بين تحذير السلسلة وتذكير المهام
            self::CompetitiveEventResultReady => 20,
            self::CompetitiveRewardGranted => 19,
            self::TeamInvitationReceived => 28,
            self::TeamJoinRequestReceived => 29,
            self::TeamInvitationAccepted => 13,
            self::TeamJoinRequestAccepted => 12,
            self::TeamMemberRemoved => 11,
            self::TeamChallengeReceived => 30,
            self::TeamChallengeAccepted => 18,
            self::TeamChallengeResultReady => 9,
            self::TeamChampionshipStarted => 8,
            self::TeamChampionshipResultReady => 7,
            self::AchievementUnlocked => 10,
            self::CreatorApproved => 6,
            self::CreatorRejected => 5,
            self::SubmissionApproved => 4,
            self::SubmissionChangesRequested => 3,
            self::SubmissionRejected => 2,
            self::PublicationHidden => 1,
            self::CreatorNewPublication => 27,
        };
    }

    /** أيقونة من سجل مغلق (رموز نصية) - لا أيقونة تأتي من قاعدة البيانات. */
    public function icon(): string
    {
        return match ($this) {
            self::AchievementUnlocked => '🏆',
            self::StreakAtRisk => '🔥',
            self::DailyQuestsAvailable => '🎯',
            self::SecurityPasswordReset => '🛡️',
            self::SeasonStarted => '🏁',
            self::CampaignAvailable => '🧭',
            self::StageUnlocked => '🔓',
            self::CampaignCompleted => '✅',
            self::SeasonCompleted => '🏅',
            self::SeasonEndingSoon, self::CampaignEndingSoon => '⏳',
            self::FriendRequestReceived, self::FriendRequestAccepted => '🤝',
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => '⚔️',
            self::CompetitiveEventStarted, self::CompetitiveEventResultReady => '🏆',
            self::CompetitiveRewardGranted => '🎁',
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved => '👥',
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => '⚔️',
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => '🏆',
            self::CompetitiveEventEndingSoon => '⏳',
            self::CreatorApproved => '🎨',
            self::CreatorRejected => '📝',
            self::SubmissionApproved => '✅',
            self::SubmissionChangesRequested => '✍️',
            self::SubmissionRejected => '📝',
            self::PublicationHidden => '🚫',
            self::CreatorNewPublication => '🆕',
        };
    }

    /** أسماء المسارات الداخلية الوحيدة المسموحة لهذا النوع (الأولى = الافتراضية). لا URL خارجي أبدًا. */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::AchievementUnlocked => ['progress.show'],
            self::StreakAtRisk => ['puzzles.index'],
            self::DailyQuestsAvailable => ['quests.show'],
            self::SecurityPasswordReset => ['profile.edit'],
            self::SeasonStarted => ['seasons.show'],
            self::CampaignAvailable => ['campaigns.show'],
            self::StageUnlocked => ['campaigns.show', 'seasons.show'],
            self::CampaignCompleted => ['campaigns.show', 'seasons.show'],
            self::SeasonCompleted, self::SeasonEndingSoon => ['seasons.show'],
            self::CampaignEndingSoon => ['campaigns.show'],
            self::FriendRequestReceived, self::FriendRequestAccepted => ['friends.index'],
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => ['friends.challenges.show'],
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted => ['competitions.show'],
            self::TeamInvitationReceived => ['teams.invitations'],
            self::TeamInvitationAccepted, self::TeamJoinRequestReceived => ['teams.manage', 'teams.show'],
            self::TeamJoinRequestAccepted => ['teams.show'],
            self::TeamMemberRemoved => ['teams.index'],
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => ['teams.challenges.show'],
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => ['team-championships.show'],
            self::CreatorApproved => ['creator.dashboard'],
            self::CreatorRejected => ['creator.apply'],
            self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden => ['creator.puzzles.index'],
            self::CreatorNewPublication => ['community.puzzles.show'],
        };
    }

    public function defaultRoute(): string
    {
        return $this->allowedRoutes()[0];
    }

    public function title(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'إنجاز جديد: '.($params['name'] ?? ''),
            self::StreakAtRisk => 'حافظ على سلسلتك اليومية',
            self::DailyQuestsAvailable => 'مهام اليوم جاهزة',
            self::SecurityPasswordReset => 'أُعيد تعيين كلمة مرور حسابك',
            self::SeasonStarted => 'بدأ موسم: '.($params['name'] ?? ''),
            self::CampaignAvailable => 'حملة جديدة متاحة: '.($params['name'] ?? ''),
            self::StageUnlocked => 'تم فتح مرحلة جديدة لك: '.($params['stage'] ?? ''),
            self::CampaignCompleted => 'أكملت الحملة بنجاح: '.($params['campaign'] ?? ''),
            self::SeasonCompleted => 'أكملت الموسم بنجاح: '.($params['season'] ?? ''),
            self::SeasonEndingSoon => 'ينتهي الموسم قريبًا: '.($params['name'] ?? ''),
            self::CampaignEndingSoon => 'تنتهي الحملة قريبًا: '.($params['name'] ?? ''),
            self::FriendRequestReceived => 'أرسل لك '.($params['name'] ?? '').' طلب صداقة',
            self::FriendRequestAccepted => 'قبل '.($params['name'] ?? '').' طلب صداقتك',
            self::FriendChallengeReceived => 'تحدّاك '.($params['name'] ?? '').' في أحجية',
            self::FriendChallengeAccepted => 'قبل '.($params['name'] ?? '').' تحدّيك',
            self::FriendChallengeResultReady => 'نتيجة التحدي جاهزة',
            self::CompetitiveEventStarted => 'بدأت المنافسة: '.($params['title'] ?? ''),
            self::CompetitiveEventEndingSoon => 'تنتهي المنافسة قريبًا: '.($params['title'] ?? ''),
            self::CompetitiveEventResultReady => 'نتائج المنافسة جاهزة: '.($params['title'] ?? ''),
            self::CompetitiveRewardGranted => 'حصلت على جائزة '.($params['placement'] ?? '').' في '.($params['title'] ?? ''),
            self::TeamInvitationReceived => 'دعاك '.($params['name'] ?? '').' للانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamInvitationAccepted => 'انضم '.($params['name'] ?? '').' إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestReceived => 'طلب '.($params['name'] ?? '').' الانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestAccepted => 'قُبل طلب انضمامك إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamMemberRemoved => 'لم تعد عضوًا في فريق «'.($params['team'] ?? '').'»',
            self::TeamChallengeReceived => 'تحدّاكم فريق «'.($params['team'] ?? '').'» بمباراة',
            self::TeamChallengeAccepted => 'قَبِل فريق «'.($params['team'] ?? '').'» تحدّيكم',
            self::TeamChallengeResultReady => 'نتيجة مباراتكم ضد «'.($params['team'] ?? '').'» جاهزة',
            self::TeamChampionshipStarted => 'بدأت بطولة الفرق: '.($params['title'] ?? ''),
            self::TeamChampionshipResultReady => 'اعتُمدت نتائج بطولة الفرق: '.($params['title'] ?? ''),
            self::CreatorApproved => 'تمت الموافقة على انضمامك كمبدع',
            self::CreatorRejected => 'لم يُقبل طلب انضمامك كمبدع',
            self::SubmissionApproved => 'اعتُمدت أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionChangesRequested => 'طُلبت تعديلات على أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionRejected => 'لم تُعتمد أحجيتك: '.($params['title'] ?? ''),
            self::PublicationHidden => 'أُخفيت أحجيتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' أحجيته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' أحجية جديدة: '.($params['title'] ?? ''),
        };
    }

    public function body(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'فتحتَ إنجازًا جديدًا. تصفّح تقدّمك لترى بقية الإنجازات.',
            self::StreakAtRisk => 'سلسلتك الحالية '.(int) ($params['streak'] ?? 0).' يومًا. حلّ أحجية واحدة قبل نهاية اليوم لتستمر.',
            self::DailyQuestsAvailable => 'مهام اليوم متاحة الآن. حلّ أحجية وتقدّم نحو أهدافك اليومية.',
            self::SecurityPasswordReset => 'إن لم تكن أنت من أعاد تعيينها، تواصل مع الدعم فورًا وراجع أمان حسابك.',
            self::SeasonStarted => 'الموسم متاح الآن ويمكنك الانضمام واللعب.',
            self::CampaignAvailable => 'الحملة متاحة الآن ويمكنك البدء بها.',
            self::StageUnlocked => 'مرحلة «'.($params['stage'] ?? '').'» من حملة «'.($params['campaign'] ?? '').'» أصبحت متاحة لك الآن.',
            self::CampaignCompleted => 'أنهيت جميع مراحل حملة «'.($params['campaign'] ?? '').'».',
            self::SeasonCompleted => 'أنهيت جميع مراحل موسم «'.($params['season'] ?? '').'».',
            self::SeasonEndingSoon => 'ينتهي موسم «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكماله.',
            self::CampaignEndingSoon => 'تنتهي حملة «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكمالها.',
            self::FriendRequestReceived => 'يمكنك قبول الطلب أو رفضه من صفحة الأصدقاء.',
            self::FriendRequestAccepted => 'أصبحتما صديقين.',
            self::FriendChallengeReceived => 'أحجية «'.($params['puzzle'] ?? '').'». يمكنك قبول التحدي أو رفضه من صفحة التحديات.',
            self::FriendChallengeAccepted => 'تحدّي أحجية «'.($params['puzzle'] ?? '').'» صار نشطًا، ويمكنك اللعب الآن.',
            self::FriendChallengeResultReady => 'تحدّي «'.($params['puzzle'] ?? '').'» ضد '.($params['name'] ?? '').': '.($params['outcome'] ?? '').'.',
            self::CompetitiveEventStarted => 'المنافسة التي سجّلت بها بدأت الآن، ويمكنك اللعب حتى انتهائها.',
            self::CompetitiveEventEndingSoon => 'لم تُرسل نتيجتك بعد. تنتهي المنافسة خلال '.($params['hours'] ?? '').' ساعة أو أقل.',
            self::CompetitiveEventResultReady => 'ترتيبك النهائي: '.($params['rank'] ?? '').'.',
            self::CompetitiveRewardGranted => 'مُنحت لك: '.($params['reward'] ?? '').'.',
            self::TeamInvitationReceived => 'يمكنك قبول الدعوة أو تجاهلها من صفحة دعواتك.',
            self::TeamInvitationAccepted => 'قَبِل دعوتك وأصبح عضوًا بالفريق.',
            self::TeamJoinRequestReceived => 'راجع الطلب من صفحة إدارة الفريق.',
            self::TeamJoinRequestAccepted => 'أهلًا بك في الفريق.',
            self::TeamMemberRemoved => 'يمكنك الانضمام إلى فريق آخر أو إنشاء فريقك من صفحة الفرق.',
            self::TeamChallengeReceived => 'اختر روستر فريقكم واقبل التحدّي قبل انتهاء المهلة.',
            self::TeamChallengeAccepted => 'قُفل الروستران، ويمكن للاعبي الروستر اللعب الآن.',
            self::TeamChallengeResultReady => ($params['outcome'] ?? 'اعتُمدت النتيجة').'.',
            self::TeamChampionshipStarted => 'تُحتسب نتائج أحداثها المعتمَدة بنقاط الترتيب.',
            self::TeamChampionshipResultReady => 'اطّلع على الترتيب النهائي وبطل البطولة.',
            self::CreatorApproved => 'يمكنك الآن إنشاء أحجيات المجتمع وإرسالها للمراجعة من استوديو المبدعين.',
            self::CreatorRejected => static::withNote('يمكنك مراجعة الملاحظة وإعادة التقديم لاحقًا.', $params),
            self::SubmissionApproved => 'أصبحت أحجيتك منشورة ضمن «من المجتمع» ويمكن للاعبين لعبها.',
            self::SubmissionChangesRequested => static::withNote('عدّل الأحجية وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SubmissionRejected => static::withNote('يمكنك إنشاء نسخة جديدة من استوديو المبدعين.', $params),
            self::PublicationHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من الأحجية ضمن «من المجتمع». أحجيات المجتمع بلا جوائز اقتصادية.'
                : 'أحجية جديدة ضمن «من المجتمع» من مبدع تتابعه. أحجيات المجتمع بلا جوائز اقتصادية.',
        };
    }

    /** ملاحظة المراجع (نص عادي يُهرَّب عند العرض) تُلحق بنص الإشعار إن وُجدت؛ الغياب لا يكسر النص. */
    protected static function withNote(string $base, array $params): string
    {
        $note = trim((string) ($params['note'] ?? ''));

        return $note === '' ? $base : 'ملاحظة المراجع: '.$note.' — '.$base;
    }

    /** @return list<string> */
    public static function reEngagementKeys(?self $atLeast = null): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isReEngagement() && ($atLeast === null || $t->priority() >= $atLeast->priority())),
        ));
    }
}
`````

### `app/Services/Notifications/NotificationCategory.php`

`sha256: 0a069bf5878a084048ab224ad825bba0b5733b1908e524dc84a2eb0524091fa6`

`````php
<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق للفئات المنفَّذة فعليًا فقط (لا فئة بلا حدث حقيقي يغذّيها). لا فئة تأتي من نص
 * إدارة أو من قاعدة البيانات. فئات مؤجَّلة (campaign/season/system/store/engagement): راجع docs/notifications.md.
 */
enum NotificationCategory: string
{
    case Achievement = 'achievement';
    case Streak = 'streak';
    case Quest = 'quest';
    case Security = 'security';
    case Season = 'season';
    case Campaign = 'campaign';
    case Social = 'social';
    case Competitive = 'competitive';

    public function label(): string
    {
        return match ($this) {
            self::Achievement => 'الإنجازات',
            self::Streak => 'السلسلة اليومية',
            self::Quest => 'المهام اليومية',
            self::Security => 'الأمان',
            self::Season => 'المواسم',
            self::Campaign => 'الحملات',
            self::Social => 'الاجتماعية',
            self::Competitive => 'المنافسات',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Achievement => 'إشعار عند فتح إنجاز جديد.',
            self::Streak => 'تذكير واحد في اليوم عند اقتراب انقطاع سلسلتك.',
            self::Quest => 'تذكير واحد في اليوم بجاهزية مهام اليوم.',
            self::Security => 'تنبيهات أمان حسابك - إلزامية ولا يمكن تعطيلها.',
            self::Season => 'إشعار واحد عند بدء موسم جديد.',
            self::Campaign => 'إشعار واحد عند إتاحة حملة جديدة.',
            self::Social => 'طلبات الصداقة الجديدة وقبول طلباتك، وتحديات الأصدقاء ونتائجها، وجديد المبدعين الذين تتابعهم.',
            self::Competitive => 'بدء المنافسات المسجَّل بها وقرب نهايتها وجاهزية نتائجها.',
        };
    }

    /** الأمان إلزامي: لا يخضع لتفضيل المستخدم ولا للمفتاح الشامل. */
    public function isMandatory(): bool
    {
        return $this === self::Security;
    }

    /** عمود التفضيل بجدول notification_preferences (null للإلزامي). */
    public function preferenceColumn(): ?string
    {
        return match ($this) {
            self::Achievement => 'achievement_enabled',
            self::Streak => 'streak_enabled',
            self::Quest => 'quest_enabled',
            self::Security => null,
            self::Season => 'season_enabled',
            self::Campaign => 'campaign_enabled',
            self::Social => 'social_enabled',
            self::Competitive => 'competitive_enabled',
        };
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => ! $c->isMandatory()));
    }
}
`````

### `app/Services/Creators/CreatorReviewService.php`

`sha256: 182320abe4d7176598644f3b87c559c35b26f965adc69cbb0d4bc23885b5d03a`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Engagement\CreatorPublicationAnnouncer;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E28 - مراجعة الأحجيات المرسلة. المراجع بصلاحية creators.review فقط (RBAC)، ولا يعدّل محتوى المبدع بصمت: الخيارات اعتماد/طلب تعديل/رفض.
 *
 * الاعتماد ذرّي: قفل النسخة والعمل ← إعادة تحقق كاملة من الحمولة ← مادّة الأحجية القانونية بالمحرك + فحص ذاتي بالمدقّق الرسمي ← ربط النسخة بالأحجية ← تحويل لقطة النشر ← تدقيق.
 * أي فشل بأي خطوة يُرجع كل شيء (لا نشر لما لا يُحلّ ولا نشر نصف مكتمل). الإشعار بعد الـcommit ولا يتراجع الاعتماد بفشله.
 * النسخة القديمة المنشورة لا تُحذف ولا تُعدَّل: تبقى أحجيتها القانونية القديمة وكل محاولاتها؛ لقطة العمل فقط تتحول للنسخة الجديدة.
 * ملاحظة: طلب التعديل والرفض يشترطان سببًا مكتوبًا (يظهر للمبدع بالاستوديو وبالإشعار).
 * E29: بعد الـcommit أيضًا يُعلَن النشر لمتابعي المبدع (CreatorPublicationAnnouncer ← دفعات بالطابور)، وفشل الإعلان لا يتراجع عنه الاعتماد.
 */
class CreatorReviewService
{
    public function __construct(
        protected CreatorContent $content,
        protected CreatorPuzzleMaterializer $materializer,
        protected OperationalAuditService $audit,
        protected CreatorNotifier $notifier,
        protected CreatorPublicationAnnouncer $announcer,
    ) {}

    public function approve(CreatorPuzzleRevision $revision, User $actor, ?string $note = null): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);

        $isUpdate = false;
        $creatorProfileId = 0;

        $approved = DB::transaction(function () use ($revision, $actor, $note, &$isUpdate, &$creatorProfileId) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($locked->creator_puzzle_id);
            $profile = CreatorProfile::query()->with('user')->findOrFail($work->creator_profile_id);

            if (! $profile->isApproved() || (bool) $profile->user?->is_frozen) {
                throw new CreatorException('لا يمكن اعتماد أحجية مبدع معلَّق أو حسابه مقيَّد.');
            }

            $this->content->assertReady($locked);
            $puzzle = $this->materializer->materialize($locked);
            $isUpdate = $work->first_published_at !== null;   // نسخة جديدة لعمل نُشر سابقًا (لا أول نشر)
            $creatorProfileId = (int) $work->creator_profile_id;

            $locked->forceFill([
                'status' => CreatorPuzzleRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'puzzle_id' => $puzzle->id,
            ])->save();

            $work->forceFill([
                'published_revision_id' => $locked->id,
                'published_puzzle_id' => $puzzle->id,
                'published_title' => $locked->title,
                'published_category_id' => $locked->puzzle_category_id,
                'published_difficulty' => $locked->difficulty,
                'published_at' => now(),
                'first_published_at' => $work->first_published_at ?? now(),
            ])->save();

            $this->audit->log('creator_submission_approved', $locked, [
                'work' => $work->public_id,
                'revision' => $locked->revision_number,
                'puzzle_id' => $puzzle->id,
                'title' => $locked->title,
            ], $actor);

            return $locked;
        });

        $this->notifier->submissionApproved($approved->load('work.creator.user'));
        $this->announcer->announce($approved, $creatorProfileId, $isUpdate);

        return $approved;
    }

    public function requestChanges(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED, 'creator_submission_changes_requested');
        $this->notifier->changesRequested($revision->load('work.creator.user'));

        return $revision;
    }

    public function reject(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_REJECTED, 'creator_submission_rejected');
        $this->notifier->submissionRejected($revision->load('work.creator.user'));

        return $revision;
    }

    protected function close(CreatorPuzzleRevision $revision, User $actor, string $note, string $status, string $auditAction): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        return DB::transaction(function () use ($revision, $actor, $note, $status, $auditAction) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, ['work' => $locked->work()->value('public_id'), 'revision' => $locked->revision_number, 'title' => $locked->title, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function assertPending(CreatorPuzzleRevision $revision): void
    {
        if (! $revision->isPendingReview()) {
            throw new CreatorException('هذه النسخة ليست قيد المراجعة (ربما عولجت للتو).');
        }
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
`````

### `app/Services/Creators/CommunityDiscovery.php`

`sha256: 182152b8075415ec0b312b81b159910edade386dd4a26688818cf60d3da0edfc`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Services\Engagement\CommunityQualityService;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E28 / E27 - أحجيات المجتمع بالاكتشاف: رائج + جديد + مقترحة للمسجَّل، **بسقف أحجيات لكل مبدع** (تنوّع المبدعين: مبدع غزير لا يحتكر القسم).
 *
 * قواعد ثابتة:
 *  - يقرأ فقط الأعمال الظاهرة (is_visible) في كل طلب؛ الكاش يحمل **معرّفات ودرجات فقط** (لا أسماء ولا بيانات لاعبين)، فإخفاء الإشراف أو تعليق المبدع يظهر فورًا ولو بقيت اللقطة بالكاش.
 *  - الرائج = لاعبون **مميَّزون** (لا محاولات) بحسابات موثَّقة غير مجمَّدة خلال نافذة E27 نفسها، بحدّ أدنى من اللاعبين؛ جدول community_puzzle_attempts وحده (لا puzzle_attempts).
 *    فلا تأثير لأي شيء هنا على الرائج الرسمي ولا على توصيات الأحجيات الرسمية، ولا على الاقتصاد/XP/المهام (عرض فقط).
 *  - عدد الاستعلامات ثابت لا يتبع حجم البيانات: جديد (1) + رائج (1 إن وُجدت لقطة) + للمسجَّل: سجلّه (1). الحساب الثقيل مخزَّن (TTL مثل رائج E27).
 *  - لا مكافآت ولا رعاية ولا ترتيب مدفوع. المبدع لا تُقترح عليه أعماله.
 *
 * E29 (إشارات التفاعل - ضعيفة ولا تحلّ محلّ الاستكشاف):
 *  - top_rated: ترتيب الدرجة المنكمشة (CommunityQualityService) من كاش معرّفات+درجات؛ الظهور يُفحص حيًّا هنا بكل طلب (المخفي لا يظهر ولو بقي بالكاش)، بسقف لكل مبدع.
 *  - following: أحدث ظاهر من مبدعين يتابعهم المشاهد (استعلام واحد، للمسجَّل فقط).
 *  - for_you: مفضلة المشاهد وتقييماته العالية (تصنيفاتها فقط، من بياناته هو حصرًا) تعيد **ترتيب** المرشَّحين أنفسهم؛ لا تضيف مرشَّحًا ولا تلغي سقف المبدع ولا تعرض شيئًا عن غيره.
 *  - لا أثر على الاكتشاف الرسمي ولا على أي اقتصاد/XP/مهام.
 */
class CommunityDiscovery
{
    public function __construct(protected CommunityQualityService $quality) {}

    /**
     * @return array{trending: list<DiscoverCard>, new: list<DiscoverCard>, for_you: list<DiscoverCard>, top_rated: list<DiscoverCard>, following: list<DiscoverCard>}
     */
    public function sections(?User $viewer): array
    {
        $limit = max(1, (int) config('creators.discovery.section_limit', 8));
        $cap = max(1, (int) config('creators.discovery.per_creator_cap', 2));
        $pool = $this->newestPool();

        $trending = $this->trendingWorks($limit, $cap);
        $trendingIds = $trending->pluck('id')->all();

        $new = $this->capPerCreator($pool->reject(fn ($w) => in_array($w->id, $trendingIds, true)), $cap, $limit);

        $forYou = collect();
        $following = collect();

        if ($viewer !== null) {
            $following = $this->followingWorks($viewer, $cap);

            $seen = CommunityPuzzleAttempt::query()->where('user_id', $viewer->id)->distinct()
                ->limit((int) config('search.recommendations.history_cap', 500))->pluck('creator_puzzle_id')->all();
            $shown = array_merge($trendingIds, $new->pluck('id')->all(), $seen);

            $liked = $this->likedCategories($viewer);

            $forYou = $this->capPerCreator(
                $pool->reject(fn ($w) => in_array($w->id, $shown, true) || (int) $w->creator_user_id === $viewer->id)
                    ->sort(fn ($a, $b) => [isset($liked[(int) $b->published_category_id]) ? 1 : 0, (int) $b->plays_count, (int) $b->id] <=> [isset($liked[(int) $a->published_category_id]) ? 1 : 0, (int) $a->plays_count, (int) $a->id])->values(),
                $cap,
                min($limit, (int) config('creators.discovery.reco_limit', 4)),
            );
        }

        $topRated = $this->topRatedWorks($cap);

        return [
            'trending' => $trending->map(fn ($w) => $this->card($w, $w->trending_players.' لاعب هذا الأسبوع'))->values()->all(),
            'new' => $new->map(fn ($w) => $this->card($w, null))->values()->all(),
            'for_you' => $forYou->map(fn ($w) => $this->card($w, 'لم تجرّبها بعد'))->values()->all(),
            'top_rated' => $topRated->map(fn ($w) => $this->card($w, number_format((float) $w->top_average, 1).' من 5 · '.number_format((int) $w->top_count).' تقييم'))->values()->all(),
            'following' => $following->map(fn ($w) => $this->card($w, 'من مبدع تتابعه'))->values()->all(),
        ];
    }

    /**
     * الأعلى تقييمًا: المعرّفات والدرجات من الكاش القصير، والظهور مُعاد فحصه حيًّا باستعلام واحد (whereIn على المعرّفات + is_visible + المبدع المعتمد).
     *
     * @return Collection<int, CreatorPuzzle>
     */
    protected function topRatedWorks(int $cap): Collection
    {
        $scores = $this->quality->topRated();

        if ($scores === []) {
            return collect();
        }

        $works = $this->visibleQuery()->whereIn('creator_puzzles.id', array_keys($scores))->get()->keyBy('id');

        $ordered = collect($scores)
            ->filter(fn (array $row, int $id) => $works->has($id))
            ->map(function (array $row, int $id) use ($works) {
                $work = $works->get($id);
                $work->setAttribute('top_average', $row['average']);
                $work->setAttribute('top_count', $row['count']);

                return $work;
            })
            ->values();

        return $this->capPerCreator($ordered, $cap, max(1, (int) config('engagement.discovery.top_rated_limit', 8)));
    }

    /** @return Collection<int, CreatorPuzzle> أحدث أعمال ظاهرة لمبدعين يتابعهم المشاهد (استعلام واحد، سقف لكل مبدع). */
    protected function followingWorks(User $viewer, int $cap): Collection
    {
        $limit = max(1, (int) config('engagement.discovery.following_limit', 4));

        return $this->capPerCreator(
            $this->visibleQuery()
                ->whereIn('creator_puzzles.creator_profile_id', fn ($q) => $q->select('creator_profile_id')->from('creator_follows')->where('user_id', $viewer->id))
                ->orderByDesc('creator_puzzles.published_at')->orderByDesc('creator_puzzles.id')
                ->limit($limit * $cap + $limit)->get(),
            $cap,
            $limit,
        );
    }

    /**
     * تصنيفات يفضّلها المشاهد (مفضلته + تقييماته ≥ الحدّ) كمجموعة مفاتيح. بيانات المشاهد وحده (WHERE user_id)، استعلام واحد بـUNION، ولا تُخزَّن بالكاش.
     *
     * @return array<int, true>
     */
    protected function likedCategories(User $viewer): array
    {
        $favorites = DB::table('community_puzzle_favorites as f')->join('creator_puzzles as p', 'p.id', '=', 'f.creator_puzzle_id')
            ->where('f.user_id', $viewer->id)->whereNotNull('p.published_category_id')->select('p.published_category_id as category_id')->limit(200);
        $rated = DB::table('community_puzzle_ratings as r')->join('creator_puzzles as p', 'p.id', '=', 'r.creator_puzzle_id')
            ->where('r.user_id', $viewer->id)->where('r.rating', '>=', (int) config('engagement.discovery.affinity_min_rating', 4))
            ->whereNotNull('p.published_category_id')->select('p.published_category_id as category_id')->limit(200);

        return array_fill_keys($favorites->union($rated)->pluck('category_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /** أحدث الأعمال الظاهرة (مجمَّع قراءة واحد بضمّ التصنيف واسم المبدع: لا N+1). */
    protected function newestPool(): Collection
    {
        return $this->visibleQuery()
            ->orderByDesc('creator_puzzles.published_at')->orderByDesc('creator_puzzles.id')
            ->limit(max(1, (int) config('creators.discovery.reco_pool', 40)))->get();
    }

    /** @return Collection<int, CreatorPuzzle> أعمال ظاهرة رائجة بترتيب الدرجة، بسقف لكل مبدع؛ كل عنصر يحمل trending_players. */
    protected function trendingWorks(int $limit, int $cap): Collection
    {
        $scores = $this->trendingScores();

        if ($scores === []) {
            return collect();
        }

        $works = $this->visibleQuery()->whereIn('creator_puzzles.id', array_keys($scores))->get()->keyBy('id');

        $ordered = collect($scores)
            ->filter(fn (array $row, int $id) => $works->has($id))
            ->map(function (array $row, int $id) use ($works) {
                $work = $works->get($id);
                $work->setAttribute('trending_players', $row['players']);

                return $work;
            })
            ->values();

        return $this->capPerCreator($ordered, $cap, $limit);
    }

    /**
     * معرّفات + درجات فقط (لا بيانات لاعبين). الدرجة = لاعبون مميَّزون + وزن إضافي لمن حلّوا.
     *
     * @return array<int, array{players: int, score: int}> مرتبة بالدرجة ثم الأحدث.
     */
    public function trendingScores(): array
    {
        return CachedSnapshot::remember('discover.community.trending', (int) config('search.trending.cache_ttl', 300), function () {
            $days = max(1, (int) config('search.trending.window_days', 7));
            $min = max(1, (int) config('creators.discovery.trending_min_players', 2));
            $solverWeight = (int) config('search.trending.solver_weight', 2);

            $rows = DB::table('community_puzzle_attempts as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.created_at', '>=', now()->subDays($days))
                ->whereNotNull('u.email_verified_at')->where('u.is_frozen', false)
                ->groupBy('a.creator_puzzle_id')
                ->selectRaw('a.creator_puzzle_id as id, COUNT(DISTINCT a.user_id) as players, COUNT(DISTINCT CASE WHEN a.is_correct THEN a.user_id END) as solvers')
                ->havingRaw('COUNT(DISTINCT a.user_id) >= ?', [$min])
                ->get();

            return $rows
                ->map(fn ($r) => ['id' => (int) $r->id, 'players' => (int) $r->players, 'score' => (int) $r->players + $solverWeight * (int) $r->solvers])
                ->sort(fn ($a, $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']])
                ->take(max(1, (int) config('creators.discovery.reco_pool', 40)))
                ->mapWithKeys(fn ($r) => [$r['id'] => ['players' => $r['players'], 'score' => $r['score']]])
                ->all();
        });
    }

    protected function visibleQuery(): Builder
    {
        return CreatorPuzzle::query()->visible()
            ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_puzzles.creator_profile_id')
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('puzzle_categories', 'puzzle_categories.id', '=', 'creator_puzzles.published_category_id')
            ->select(
                'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title',
                'creator_puzzles.published_category_id', 'creator_puzzles.published_difficulty', 'creator_puzzles.plays_count', 'creator_puzzles.published_at',
                'creator_profiles.user_id as creator_user_id', 'users.name as creator_name', 'puzzle_categories.name as category_name',
            );
    }

    /** يحافظ على الترتيب ويتجاوز ما فاق سقف المبدع الواحد حتى يبلغ $limit. */
    protected function capPerCreator(Collection $works, int $cap, int $limit): Collection
    {
        $perCreator = [];

        return $works->filter(function ($work) use (&$perCreator, $cap) {
            $perCreator[$work->creator_profile_id] = ($perCreator[$work->creator_profile_id] ?? 0) + 1;

            return $perCreator[$work->creator_profile_id] <= $cap;
        })->take($limit)->values();
    }

    protected function card($work, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$work->published_difficulty] ?? null;
        $by = 'بقلم '.$work->creator_name;

        return new DiscoverCard(
            (string) $work->published_title,
            route('community.puzzles.show', $work->public_id),
            'friends',
            $work->category_name ? $work->category_name.' · '.$by : $by,
            'من المجتمع'.($difficulty ? ' · '.$difficulty : ''),
            'primary',
            $reason,
        );
    }
}
`````

### `app/Services/Creators/CreatorAnalyticsService.php`

`sha256: 8aa38913a706e22882133a403914f983635705169c5f3406e59a62efa32fe005`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Services\Engagement\CommunityQualityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E28 - تحليلات المبدع (لوحته) + ملخص إداري متواضع. لا أرباح ولا محفظة ولا دفع ولا مرتبة.
 * بلا COUNT لكل بطاقة: المبدع محدود بسقف أعمال، فاستعلامان مجمَّعان فقط (إجماليات + نشاط حديث لكل عمل) على فهرس (creator_puzzle_id, user_id)، ومخزَّنان قصيرًا.
 * لا هوية لاعبين: أرقام مجمَّعة فقط (خصوصية).
 *
 * E29: المتابعون/المفضلة المستلمة/عدد التقييمات/المتوسط/أعلى عمل تقييمًا تُضاف **لنفس الحمولة المخزَّنة** (TTL نفسه، بلا كاش ثانٍ)، من عدّادات الأعمال الظاهرة
 * (استعلام واحد محدود بسقف أعمال المبدع). لا أسماء متابعين ولا مقيِّمين ولا محافظ؛ المتوسط يُخفى تحت الحدّ الأدنى للعيّنة.
 */
class CreatorAnalyticsService
{
    public function __construct(protected CommunityQualityService $quality) {}

    /** @return array{published:int, players:int, attempts:int, completion_rate:int|null, recent_attempts:int, recent_days:int, per_work: array<int, array{recent:int}>, followers:int, favorites_received:int, ratings_count:int, average_rating:float|null, top_rated_work: array{public_id:string, title:string, average:float, count:int}|null} */
    public function forCreator(CreatorProfile $profile): array
    {
        return Cache::remember('creators.analytics.'.$profile->id, now()->addSeconds((int) config('creators.analytics.cache_ttl')), function () use ($profile) {
            $days = (int) config('creators.analytics.recent_days');

            // استعلام واحد لأعمال المبدع (محدودة بسقف CREATOR_MAX_WORKS) يغذّي: المعرّفات، عدد المنشور، وعدّادات التفاعل للظاهر منها. (E28 كان استعلامين لأول اثنين.)
            $works = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)
                ->get(['id', 'public_id', 'published_title', 'published_puzzle_id', 'is_visible', 'ratings_count', 'ratings_sum', 'favorites_count']);
            $workIds = $works->pluck('id')->all();
            $published = $works->whereNotNull('published_puzzle_id')->count();

            $engagement = $this->engagementFor($profile, $works->where('is_visible', true));

            if ($workIds === []) {
                return ['published' => 0, 'players' => 0, 'attempts' => 0, 'completion_rate' => null, 'recent_attempts' => 0, 'recent_days' => $days, 'per_work' => []] + $engagement;
            }

            $totals = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)
                ->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT user_id) as players, COUNT(DISTINCT CASE WHEN is_correct THEN user_id END) as solvers')
                ->first();

            $recent = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('creator_puzzle_id, COUNT(*) as recent')->groupBy('creator_puzzle_id')->pluck('recent', 'creator_puzzle_id');

            $players = (int) ($totals->players ?? 0);

            return [
                'published' => $published,
                'players' => $players,
                'attempts' => (int) ($totals->attempts ?? 0),
                'completion_rate' => $players > 0 ? (int) round(((int) $totals->solvers / $players) * 100) : null,
                'recent_attempts' => (int) $recent->sum(),
                'recent_days' => $days,
                'per_work' => $recent->map(fn ($n) => ['recent' => (int) $n])->all(),
            ] + $engagement;
        });
    }

    /**
     * عدّادات التفاعل على أعمال المبدع **الظاهرة** (المخفي لا يدخل أرقامًا حتى يُستعاد)، من الأعمال المحمَّلة أصلًا (بلا استعلام إضافي).
     *
     * @return array{followers:int, favorites_received:int, ratings_count:int, average_rating:float|null, top_rated_work: array{public_id:string, title:string, average:float, count:int}|null}
     */
    protected function engagementFor(CreatorProfile $profile, \Illuminate\Support\Collection $works): array
    {
        $count = (int) $works->sum('ratings_count');
        $sum = (int) $works->sum('ratings_sum');

        $top = $works->filter(fn ($w) => $this->quality->eligible((int) $w->ratings_count))
            ->sortByDesc(fn ($w) => [$this->quality->score((int) $w->ratings_count, (int) $w->ratings_sum), (int) $w->ratings_count, (int) $w->id])
            ->first();

        return [
            'followers' => (int) $profile->followers_count,
            'favorites_received' => (int) $works->sum('favorites_count'),
            'ratings_count' => $count,
            'average_rating' => $this->quality->displayAverage($count, $sum),
            'top_rated_work' => $top === null ? null : [
                'public_id' => (string) $top->public_id,
                'title' => (string) $top->published_title,
                'average' => round(((int) $top->ratings_sum) / max(1, (int) $top->ratings_count), 1),
                'count' => (int) $top->ratings_count,
            ],
        ];
    }

    /** ملخص إداري صغير مخزَّن دقيقة (مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات). */
    public function adminSummary(): array
    {
        return Cache::remember('creators.admin.summary', now()->addSeconds(60), fn () => [
            'approved_creators' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->count(),
            'pending_applications' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_PENDING)->count(),
            'pending_submissions' => CreatorPuzzleRevision::query()->where('status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)->count(),
            'published_puzzles' => CreatorPuzzle::query()->where('is_visible', true)->count(),
            'community_plays' => CommunityPuzzleAttempt::query()->count(),
            'open_reports' => CommunityPuzzleReport::query()->where('status', CommunityPuzzleReport::STATUS_OPEN)->count(),
        ] + $this->engagementSummary());
    }

    /**
     * E29: ملخص التفاعل للإدارة (مخزَّن مع الملخص). المجاميع من العدّادات المشتقّة (تُجمع على آلاف الصفوف لا ملايينها)، والمجموعات COUNT واحد.
     * الأعلى تقييمًا من اللقطة القصيرة نفسها (معرّفات+درجات) مع إعادة فحص الظهور؛ الأكثر متابعة من المبدعين المعتمدين. لا هوية لاعبين.
     */
    protected function engagementSummary(): array
    {
        $topIds = array_slice(array_keys($this->quality->topRated()), 0, 5);
        $top = $topIds === [] ? collect() : CreatorPuzzle::query()->visible()->whereIn('id', $topIds)->get(['id', 'public_id', 'published_title', 'ratings_count', 'ratings_sum'])->keyBy('id');

        $mostFollowed = CreatorProfile::query()->where('creator_profiles.status', CreatorProfile::STATUS_APPROVED)->where('creator_profiles.followers_count', '>', 0)
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->orderByDesc('creator_profiles.followers_count')->orderBy('creator_profiles.id')->limit(5)
            ->get(['creator_profiles.id', 'creator_profiles.slug', 'creator_profiles.followers_count', 'users.name']);

        return [
            'total_follows' => (int) CreatorProfile::query()->sum('followers_count'),
            'total_ratings' => (int) CreatorPuzzle::query()->sum('ratings_count'),
            'total_favorites' => (int) CreatorPuzzle::query()->sum('favorites_count'),
            'total_collections' => (int) DB::table('user_collections')->count(),
            'top_rated' => collect($topIds)->filter(fn ($id) => $top->has($id))->map(fn ($id) => [
                'public_id' => (string) $top[$id]->public_id,
                'title' => (string) $top[$id]->published_title,
                'count' => (int) $top[$id]->ratings_count,
                'average' => round(((int) $top[$id]->ratings_sum) / max(1, (int) $top[$id]->ratings_count), 1),
            ])->values()->all(),
            'most_followed' => $mostFollowed->map(fn ($c) => ['slug' => (string) $c->slug, 'name' => (string) $c->name, 'followers' => (int) $c->followers_count])->all(),
        ];
    }
}
`````

### `app/Services/Discovery/DiscoveryService.php`

`sha256: db977e2516e10e7aec83b9b55770503a0695f6e7ae74682bd7ed678bcf3f1602`

`````php
<?php

namespace App\Services\Discovery;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\Creators\CommunityDiscovery;
use Illuminate\Support\Collection;

/**
 * E27-B: مركز الاكتشاف. يجمع أقسامًا **محدودة العدد** (لا ترقيم ولا قوائم مفتوحة) من الأنظمة القائمة بنفس شروط ظهورها العامة، ويضيف للمسجَّل «تابع» و«مقترح لك».
 * عدد الاستعلامات لا يتبع حجم البيانات (كل قسم استعلام محدود بـLIMIT). كل الأقسام عرض فقط: لا مكافآت ولا نقاط ولا كتابة.
 *
 * @phpstan-type Section array{key: string, title: string, icon: string, href: ?string, linkLabel: ?string, cards: list<DiscoverCard>}
 */
class DiscoveryService
{
    public function __construct(protected TrendingService $trending, protected RecommendationService $recommendations, protected CommunityDiscovery $community) {}

    /**
     * @return array{sections: list<array>, categories: Collection<int, PuzzleCategory>, personalized: bool}
     */
    public function hub(?User $viewer): array
    {
        $limit = max(1, (int) config('search.discover.section_limit', 8));
        $sections = [];
        $reco = $viewer !== null ? $this->recommendations->forUser($viewer) : null;

        if ($reco !== null) {
            $this->add($sections, 'continue', 'تابع من حيث توقفت', 'bolt', null, null, array_merge(
                $reco['continue_puzzles']->map(fn (Puzzle $p) => $this->puzzleCard($p, 'محاولاتك لم تنتهِ'))->all(),
                $reco['continue_campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, 'تقدّمك محفوظ'))->all(),
            ));
            $this->add($sections, 'for-you', 'مقترحة لك', 'sparkles', route('puzzles.index'), 'كل الأحجيات',
                array_map(fn (array $r) => $this->puzzleCard($r['puzzle'], $r['reason']), $reco['puzzles']));
        }

        $this->add($sections, 'trending', 'الرائج هذا الأسبوع', 'bolt', route('puzzles.index'), 'كل الأحجيات',
            $this->trending->puzzles($limit)->map(fn (Puzzle $p) => $this->puzzleCard($p, $p->trending_players.' لاعب هذا الأسبوع'))->all());

        $daily = Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
            ->where('is_daily_puzzle', true)->whereDate('daily_puzzle_date', today())->where('is_active', true)->limit(1)->get();
        $this->add($sections, 'daily', 'أحجية اليوم', 'calendar', null, null, $daily->map(fn (Puzzle $p) => $this->puzzleCard($p, 'تتجدّد يوميًا'))->all());

        $this->add($sections, 'new', 'جديد الأحجيات', 'puzzle', route('puzzles.index'), 'كل الأحجيات',
            Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
                ->where('puzzles.is_active', true)->orderByDesc('puzzles.id')->limit($limit)->get()
                ->map(fn (Puzzle $p) => $this->puzzleCard($p, null))->all());

        // E28: أحجيات المجتمع بأقسام مستقلة موسومة «من المجتمع» (لا تمتزج بالرسمي ولا تؤثر برائجه/توصياته). قسم فارغ لا يظهر.
        $community = $this->community->sections($viewer);
        $this->add($sections, 'community-trending', 'رائج من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['trending']);
        $this->add($sections, 'community-following', 'جديد من تتابعهم', 'friends', route('following.index'), 'كل ما ينشره من أتابعهم', $community['following']);
        $this->add($sections, 'community-top-rated', 'الأعلى تقييمًا من المجتمع', 'trophy', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['top_rated']);
        $this->add($sections, 'community-new', 'جديد من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['new']);
        $this->add($sections, 'community-for-you', 'مقترحة لك من المجتمع', 'sparkles', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['for_you']);

        $seasons = Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.id', 'seasons.slug', 'seasons.is_featured', 'campaigns.title as campaign_title')
            ->where('seasons.is_published', true)->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')
            ->limit((int) config('search.discover.season_limit', 4))->get();
        $this->add($sections, 'seasons', 'المواسم', 'calendar', route('seasons.index'), 'كل المواسم',
            $seasons->map(fn (Season $s) => new DiscoverCard((string) $s->getAttribute('campaign_title'), route('seasons.show', $s), 'calendar', $s->is_featured ? 'موسم مميّز' : null))->all());

        $this->add($sections, 'campaigns', 'حملات متاحة', 'flag', route('campaigns.index'), 'كل الحملات',
            $this->recommendations->listableCampaigns()->orderByDesc('campaigns.id')->limit((int) config('search.discover.campaign_limit', 6))->get()
                ->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());

        $events = $reco !== null && $reco['competitions']->isNotEmpty() ? $reco['competitions'] : $this->trending->competitions((int) config('search.discover.event_limit', 6));
        $this->add($sections, 'competitions', $reco !== null ? 'منافسات لم تسجّل بها' : 'منافسات رائجة', 'trophy', route('competitions.index'), 'كل المنافسات',
            $events->map(fn (CompetitiveEvent $e) => $this->eventCard($e))->all());

        $this->add($sections, 'championships', 'بطولات الفرق', 'flag', route('team-championships.index'), 'كل البطولات',
            TeamChampionship::query()->select('team_championships.id', 'team_championships.slug', 'team_championships.title', 'team_championships.status', 'team_championships.starts_at', 'team_championships.ends_at')
                ->public()->where('team_championships.ends_at', '>', now())->orderBy('team_championships.starts_at')->orderByDesc('team_championships.id')->limit(4)->get()
                ->map(fn (TeamChampionship $c) => new DiscoverCard($c->title, route('team-championships.show', $c), 'flag', $c->starts_at?->format('Y-m-d'), $c->phase() === TeamChampionship::PHASE_LIVE ? 'مباشرة' : 'قريبًا', $c->phase() === TeamChampionship::PHASE_LIVE ? 'success' : 'warning'))->all());

        $teams = $reco !== null && $reco['teams']->isNotEmpty() ? $reco['teams'] : $this->trending->teams((int) config('search.discover.team_limit', 6));
        $this->add($sections, 'teams', $reco !== null && $reco['teams']->isNotEmpty() ? 'فرق تنضم إليها' : 'فرق صاعدة', 'team', route('teams.index'), 'كل الفرق',
            $teams->map(fn (Team $t) => new DiscoverCard($t->name, route('teams.show', $t), 'team', number_format((int) $t->members_count).' عضو'))->all());

        if ($reco !== null && $reco['campaigns']->isNotEmpty()) {
            $this->add($sections, 'new-campaigns', 'حملات لم تبدأها', 'flag', route('campaigns.index'), 'كل الحملات',
                $reco['campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());
        }

        $categories = PuzzleCategory::query()->select('id', 'name', 'slug')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(12)->get();

        return ['sections' => $sections, 'categories' => $categories, 'personalized' => $reco !== null];
    }

    /** @param list<array> $sections @param list<DiscoverCard> $cards */
    protected function add(array &$sections, string $key, string $title, string $icon, ?string $href, ?string $linkLabel, array $cards): void
    {
        if ($cards === []) {
            return;
        }

        $sections[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'href' => $href, 'linkLabel' => $linkLabel, 'cards' => array_values($cards)];
    }

    protected function puzzleCard(Puzzle $puzzle, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? null;
        $tone = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$puzzle->difficulty] ?? 'neutral';

        return new DiscoverCard($puzzle->title, route('puzzles.show', $puzzle), 'puzzle', $puzzle->category?->name, $difficulty, $tone, $reason);
    }

    protected function campaignCard(Campaign $campaign, ?string $reason): DiscoverCard
    {
        return new DiscoverCard($campaign->title, route('campaigns.show', $campaign), 'flag', $campaign->ends_at ? 'تنتهي '.$campaign->ends_at->format('Y-m-d') : null, null, 'neutral', $reason);
    }

    protected function eventCard(CompetitiveEvent $event): DiscoverCard
    {
        $phase = $event->phase();

        return new DiscoverCard($event->title, route('competitions.show', $event), 'trophy', 'المشاركون: '.$event->participants_count,
            $phase === CompetitiveEvent::PHASE_LIVE ? 'مباشرة الآن' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'قادمة' : 'منتهية'),
            $phase === CompetitiveEvent::PHASE_LIVE ? 'success' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'warning' : 'neutral'));
    }
}
`````

### `app/Http/Controllers/Creators/CommunityPuzzleController.php`

`sha256: c8caf7adf445b310428f69337327d30b164d73f20a8599b7a951f1b91e1f8580`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creators\ReportCommunityPuzzleRequest;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\PuzzleCategory;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorException;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E28 - صفحات أحجيات المجتمع العامة واللعب والبلاغ. الظهور من is_visible وحدها (منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد وحسابه سليم):
 * أي عمل غير ظاهر = 404 للجميع (مسودة/مرفوض/مخفي/مؤرشف/معلَّق). المسار منفصل عن /puzzles الرسمي وكل اللعب عبر CommunityPlayService (بلا اقتصاد).
 */
class CommunityPuzzleController extends Controller
{
    public function __construct(
        protected CommunityPlayService $play,
        protected CommunityReportService $reports,
        protected GameTypeRegistry $games,
        protected EngagementViewerState $viewerState,
        protected CollectionService $collections,
    ) {}

    public function index(Request $request)
    {
        $categorySlug = $request->query('category');
        $category = is_string($categorySlug) ? PuzzleCategory::query()->where('slug', $categorySlug)->where('is_active', true)->first(['id', 'name', 'slug']) : null;

        $works = CreatorPuzzle::query()->visible()
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->when($category, fn ($q) => $q->where('published_category_id', $category->id))
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'))->withQueryString();

        return view('community.index', [
            'works' => $works,
            'category' => $category,
            // E29: مفضلة المشاهد لصفحة البطاقات بدفعة واحدة (استعلام واحد للمستخدم المسجَّل، صفر للضيف).
            'savedIds' => $this->viewerState->favoriteIds($request->user(), $works->getCollection()->pluck('id')->all()),
            'categories' => PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Request $request, string $work)
    {
        $work = $this->visibleWork($work);
        $puzzle = $work->publishedPuzzle;
        abort_unless($puzzle !== null && $puzzle->isCommunity(), 404);

        $user = $request->user();
        $state = $this->play->state($user, $puzzle);
        $isOwner = $user !== null && $work->creator->user_id === $user->id;
        $canInteract = $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen;

        // E29: حالة المشاهد (تقييمي/محفوظ/جرّب) باستعلام واحد + منتقي المجموعات باستعلام واحد، ولغير المالك الموثَّق فقط. الضيف والمالك: صفر استعلامات إضافية.
        $viewer = $canInteract && ! $isOwner ? $this->viewerState->forWork($user, $work->id) : null;
        $collections = $viewer !== null ? $this->collections->pickerFor($user, $work->id) : collect();

        return view('community.show', [
            'viewer' => $viewer,
            'collections' => $collections,
            'work' => $work,
            'puzzle' => $puzzle,
            'state' => $state,
            'renderer' => $this->games->rendererFor($puzzle),
            'isOwner' => $isOwner,
            'canInteract' => $canInteract,
            'reasons' => CommunityPuzzleReport::REASONS,
        ]);
    }

    public function attempt(SolvePuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);
        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->play->attempt($request->user(), $work, (string) $request->input('answer', ''), $submission);
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return back()->with('success', 'إجابة صحيحة! أحسنت. (أحجيات المجتمع بلا جوائز اقتصادية.)');
        }

        return back()->with('error', $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.');
    }

    public function hint(Request $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            return back()->with('hint', $this->play->hint($work));
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function report(ReportCommunityPuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $this->reports->report($request->user(), $work, (string) $request->validated('reason'), $request->validated('details'));
        } catch (CreatorException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'وصلنا بلاغك، شكرًا لمساعدتك في إبقاء المنصة آمنة.');
    }

    /** عمل ظاهر للعامة وإلا 404. */
    protected function visibleWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->visible()->where('public_id', $publicId)
            ->with(['creator.user:id,name', 'publishedPuzzle', 'publishedCategory:id,name'])
            ->firstOrFail();
    }
}
`````

### `app/Http/Controllers/Creators/PublicCreatorController.php`

`sha256: 8246e9c93728df971e9ffed9e255e733bb395448a3aca273c721070fd8149a7c`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Services\Engagement\CommunityQualityService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementViewerState;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use Illuminate\Http\Request;

/**
 * E28 - صفحة المبدع العامة /creators/{slug}. عامة للضيوف لكن لمبدع معتمَد وحسابه غير مجمَّد فقط (غيره 404).
 * تعرض: الاسم، الصورة الرمزية (هوية اللاعب القائمة)، النبذة، تاريخ الانضمام كمبدع، عدد المنشور، إجمالي اللاعبين، والأحجيات الظاهرة فقط (مرقَّمة).
 * لا بريد ولا هاتف ولا حالة إدارية ولا علامات احتيال ولا أي بيانات لاعب خاصة.
 */
class PublicCreatorController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected CreatorFollowService $follows,
        protected CommunityQualityService $quality,
        protected EngagementViewerState $viewerState,
    ) {}

    public function show(Request $request, CreatorProfile $creator)
    {
        $creator->loadMissing('user:id,name,is_frozen');

        abort_unless($creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen, 404);

        $totals = CreatorPuzzle::query()->visible()->where('creator_profile_id', $creator->id)
            ->selectRaw('COUNT(*) as published, COALESCE(SUM(plays_count), 0) as plays, COALESCE(SUM(ratings_count), 0) as rated, COALESCE(SUM(ratings_sum), 0) as rated_sum')->first();

        $works = CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $creator->id)
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'));

        $loadout = $this->loadouts->loadoutFor($creator->user);
        $user = $request->user();

        // E29: حالة زر المتابعة بقرار واحد (استعلام exists واحد للموثَّق فقط). لا هوية متابعين تُعرض؛ العدد المجمَّع (followers_count) علني.
        $followState = match (true) {
            $user === null => 'guest',
            $user->id === $creator->user_id => 'self',
            ! $user->hasVerifiedEmail() => 'verify',
            (bool) $user->is_frozen => 'frozen',
            $this->follows->isFollowing($user, $creator->id) => 'following',
            default => 'can_follow',
        };

        return view('creator.public', [
            'creator' => $creator,
            'avatar' => $loadout['avatar'] ?? null,
            'frame' => $loadout['profile_frame'] ?? null,
            'published' => (int) ($totals->published ?? 0),
            'plays' => (int) ($totals->plays ?? 0),
            'works' => $works,
            'followState' => $followState,
            'followers' => (int) $creator->followers_count,
            'quality' => $this->quality->creatorSummary((int) ($totals->rated ?? 0), (int) ($totals->rated_sum ?? 0)),
            'savedIds' => $this->viewerState->favoriteIds($user, $works->getCollection()->pluck('id')->all()),
        ]);
    }
}
`````

### `app/Filament/Widgets/CreatorEcosystemStatsWidget.php`

`sha256: ed562f8141ef17722eeb2c4bfb799b2767d157486bd2bf7482d597fb60087cd1`

`````php
<?php

namespace App\Filament\Widgets;

use App\Services\Creators\CreatorAnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E28 - ملخص منظومة المبدعين لرأس صفحات الإشراف (ليس بالصفحة الرئيسية: غير مكتشَف تلقائيًا). أرقام مجمَّعة مخزَّنة دقيقة؛ لا هوية لاعبين.
 */
class CreatorEcosystemStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('creators.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(CreatorAnalyticsService::class)->adminSummary();

        return [
            Stat::make('مبدعون معتمدون', number_format($s['approved_creators']))->icon('heroicon-o-paint-brush')->color('primary'),
            Stat::make('طلبات انضمام معلَّقة', number_format($s['pending_applications']))->icon('heroicon-o-user-plus')->color($s['pending_applications'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات بانتظار المراجعة', number_format($s['pending_submissions']))->icon('heroicon-o-clipboard-document-check')->color($s['pending_submissions'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات مجتمع منشورة', number_format($s['published_puzzles']))->icon('heroicon-o-puzzle-piece')->color('primary'),
            Stat::make('إجمالي محاولات المجتمع', number_format($s['community_plays']))->icon('heroicon-o-play')->color('primary'),
            Stat::make('بلاغات مفتوحة', number_format($s['open_reports']))->icon('heroicon-o-flag')->color($s['open_reports'] > 0 ? 'danger' : 'success'),

            // E29: تفاعل المجتمع (مجاميع من العدّادات المشتقّة، مخزَّنة مع الملخص؛ لا هويات).
            Stat::make('إجمالي المتابعات', number_format($s['total_follows']))->icon('heroicon-o-user-plus')->color('primary'),
            Stat::make('إجمالي التقييمات', number_format($s['total_ratings']))->icon('heroicon-o-star')->color('primary'),
            Stat::make('إجمالي المفضلة', number_format($s['total_favorites']))->icon('heroicon-o-bookmark')->color('primary'),
            Stat::make('المجموعات الشخصية', number_format($s['total_collections']))->icon('heroicon-o-folder')->color('primary'),
            Stat::make('الأعلى تقييمًا', $s['top_rated'][0]['title'] ?? '—')->description(isset($s['top_rated'][0]) ? $s['top_rated'][0]['average'].' من 5 · '.$s['top_rated'][0]['count'].' تقييم' : 'لا عمل بلغ الحدّ الأدنى بعد')->icon('heroicon-o-trophy')->color('success'),
            Stat::make('الأكثر متابعة', $s['most_followed'][0]['name'] ?? '—')->description(isset($s['most_followed'][0]) ? number_format($s['most_followed'][0]['followers']).' متابع' : 'لا متابعات بعد')->icon('heroicon-o-heart')->color('success'),
        ];
    }
}
`````

### `resources/views/community/_card.blade.php`

`sha256: 26ad9324935315e98103c1ebbf12abc2742ba37ba7defcb240479295360fc75d`

`````blade
@php
    /** @var \App\Models\CreatorPuzzle $work */
    $showCreator = $showCreator ?? false;
    $creatorName = $showCreator && $work->relationLoaded('creator') && $work->creator?->relationLoaded('user') ? $work->creator->user?->name : null;
    // E29: التقييم يُعرض فقط حين تُحمَّل عدّادات العمل بالاستعلام (لا استعلام إضافي ولا «لا تقييمات» كاذبة في أسطح لم تحمّلها). $savedIds: مفضلة المشاهد (دفعة واحدة، اختياري).
    $hasRatings = array_key_exists('ratings_count', $work->getAttributes());
    $isSaved = (bool) (($savedIds ?? [])[$work->id] ?? false);
@endphp
{{-- E28: بطاقة أحجية مجتمع. وسم «من المجتمع» ثابت دائمًا (يميّزها عن الرسمي)، وتصريح أنها بلا جوائز. البطاقة كلها رابط واحد. نصوص مهرَّبة ولا قراءة علاقات غير محمَّلة (لا N+1). --}}
<a href="{{ route('community.puzzles.show', $work->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="puzzle" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true" icon="friends">من المجتمع</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $work->published_title }}</h3>
    <p class="text-xs text-slate-400 mt-1">
        @if ($work->publishedCategory) {{ $work->publishedCategory->name }} @endif
        @if ($creatorName) · {{ $creatorName }} @endif
    </p>
    <div class="mt-auto pt-3 flex flex-wrap items-center gap-2">
        @if ($work->published_difficulty) <x-difficulty-badge :difficulty="$work->published_difficulty" :small="true" /> @endif
        <span class="text-[11px] font-bold text-slate-400">{{ number_format($work->plays_count) }} لاعب</span>
        @if ($hasRatings) @include('engagement._rating_summary', ['count' => (int) $work->ratings_count, 'sum' => (int) $work->ratings_sum]) @endif
        @if ($isSaved)
            <span class="inline-flex items-center gap-1 text-[11px] font-black text-gold"><x-engagement-icon name="bookmark" :filled="true" class="w-3.5 h-3.5" />محفوظة</span>
        @endif
    </div>
</a>
`````

### `resources/views/community/index.blade.php`

`sha256: 9daa127e6a740243a9e23cf9de739f31febf21eacd8eeedb5c165348a0f40662`

`````blade
@extends('layouts.app')

@section('title', 'أحجيات المجتمع')
@section('content_width', 'max-w-6xl')

{{-- E28: قائمة أحجيات المجتمع المنشورة (الظاهرة فقط). مفصولة عن الرسمي بمسار ووسم؛ بلا جوائز اقتصادية. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="أحجيات المجتمع" icon="friends" subtitle="أحجيات صنعها لاعبون مبدعون وراجعها فريق الإشراف. بلا جواهر أو نقاط خبرة؛ للمتعة والتحدي فقط." class="anim-fade-up !mb-0">
            <x-slot:actions>
                @if (auth()->user()?->email_verified_at !== null)
                    <a href="{{ route('creator.apply') }}" class="btn-secondary !py-2 !px-4 text-sm">كن مبدعًا</a>
                @endif
                @if (auth()->user()?->email_verified_at !== null)
                    <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                    <a href="{{ route('following.index') }}" class="chip !py-2 text-sm">من أتابعهم</a>
                    <a href="{{ route('collections.index') }}" class="chip !py-2 text-sm">مجموعاتي</a>
                @endif
                <a href="{{ route('puzzles.index') }}" class="chip !py-2 text-sm">الأحجيات الرسمية</a>
            </x-slot:actions>
        </x-page-header>

        <nav aria-label="تصفية حسب التصنيف" class="flex flex-wrap gap-2 anim-fade-up">
            <a href="{{ route('community.puzzles.index') }}" @if (! $category) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $category ? '' : 'chip-active' }}">الكل</a>
            @foreach ($categories as $cat)
                <a href="{{ route('community.puzzles.index', ['category' => $cat->slug]) }}" @if ($category && $category->id === $cat->id) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $category && $category->id === $cat->id ? 'chip-active' : '' }}">{{ $cat->name }}</a>
            @endforeach
        </nav>

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لا أحجيات مجتمع هنا بعد" message="كن أول من ينشر أحجية، أو جرّب تصنيفًا آخر." :action="route('puzzles.index')" actionLabel="الأحجيات الرسمية" />
        @else
            <section aria-label="أحجيات المجتمع" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true, 'savedIds' => $savedIds ?? []])
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
`````

### `resources/views/community/show.blade.php`

`sha256: fb240cfcf0faa938d115e4b168b989e176a49067179f95a14c242eaf7fd14be2`

`````blade
@extends('layouts.app')

@section('title', $puzzle->title.' - من المجتمع')
@section('content_width', 'max-w-3xl')

{{--
    E28: صفحة لعب أحجية مجتمع. الوسم «من المجتمع» وتصريح «بلا جوائز» ظاهران دائمًا. اللعب عبر مسار المجتمع المنفصل (لا /puzzles الرسمي) والمدقّق الرسمي نفسه.
    لا جواهر ولا XP ولا مكافآت ولا مهام ولا سلسلة. المبدع لا يلعب أحجيته (يعاينها). نصوص المبدع مهرَّبة.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('community.puzzles.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />أحجيات المجتمع
        </a>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($work->publishedCategory) <span class="chip !py-1 !px-3">{{ $work->publishedCategory->name }}</span> @endif
                <x-difficulty-badge :difficulty="$puzzle->difficulty" />
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $puzzle->title }}</h1>
            <p class="text-xs text-slate-400 mb-4">
                بقلم <a href="{{ route('creators.show', $work->creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $work->creator->user->name }}</a>
                · {{ number_format($work->plays_count) }} لاعب
            </p>

            <p class="font-display font-black text-xl md:text-2xl text-white mb-4 leading-snug break-words whitespace-pre-line">{{ $puzzle->prompt }}</p>

            @if ($puzzle->image_path)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif

            @guest
                <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <span>سجّل الدخول لتلعب هذه الأحجية وتحفظ حالة حلّك.</span>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                </div>
            @else
                @if ($isOwner)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200" role="status">هذه أحجيتك. لا تُحتسب لك محاولات عليها؛ جرّبها من المعاينة في الاستوديو.
                        <a href="{{ route('creator.puzzles.preview', $work->public_id) }}" class="text-amethyst font-bold underline">افتح المعاينة</a></div>
                @elseif (! $canInteract)
                    <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-gold font-bold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتلعب أحجيات المجتمع.</div>
                @elseif ($state['solved'])
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="check-circle" class="w-5 h-5" />أحسنت! سبق أن حللت هذه الأحجية.</div>
                @elseif ($state['left'] <= 0)
                    <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="alert" class="w-5 h-5" />استنفدت جميع محاولاتك المسموحة لهذه الأحجية.</div>
                @else
                    <form method="POST" action="{{ route('community.puzzles.attempt', $work->public_id) }}" class="mt-2">
                        @csrf
                        @include($renderer)

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-bold text-slate-400">محاولة {{ $state['used'] + 1 }} من {{ $state['max'] }}</span>
                            <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
                        </div>
                    </form>

                    @if ($puzzle->hint)
                        <form method="POST" action="{{ route('community.puzzles.hint', $work->public_id) }}" class="mt-6 pt-6 border-t border-white/5">
                            @csrf
                            <button type="submit" class="text-sm font-bold text-slate-300 hover:text-gold transition motion-reduce:transition-none underline decoration-gold decoration-2 underline-offset-4 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إظهار التلميح (مجاني)</button>
                        </form>
                    @endif
                @endif
            @endguest
        </div>

        {{-- E29: التقييم والمفضلة والمجموعات (المتوسط والعدّادات علنية للجميع؛ التفاعل للموثَّق غير المجمَّد وغير المالك) --}}
        @include('engagement._work_panel', ['work' => $work, 'isOwner' => $isOwner, 'canInteract' => $canInteract, 'viewer' => $viewer, 'collections' => $collections])

        @auth
            @if (! $isOwner && $canInteract)
                <details class="glass rounded-2xl p-5 anim-fade-up">
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="flag" class="w-4 h-4" />الإبلاغ عن هذه الأحجية</summary>
                    <form method="POST" action="{{ route('community.puzzles.report', $work->public_id) }}" class="mt-4 space-y-3">
                        @csrf
                        <div>
                            <label for="report-reason" class="block text-xs font-bold text-slate-300 mb-1">السبب</label>
                            <select id="report-reason" name="reason" required class="input-gem">
                                @foreach ($reasons as $key => $label)
                                    <option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="report-details" class="block text-xs font-bold text-slate-300 mb-1">تفاصيل (اختياري)</label>
                            <textarea id="report-details" name="details" rows="3" maxlength="1000" class="input-gem">{{ old('details') }}</textarea>
                        </div>
                        <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إرسال البلاغ</button>
                    </form>
                </details>
            @endif
        @endauth
    </div>
@endsection
`````

### `resources/views/creator/dashboard.blade.php`

`sha256: aec1b785505cfc29e86f7cc04866fd6a6a77cacf8d270d86a9131882bfd3c5e5`

`````blade
@extends('layouts.app')

@section('title', 'استوديو المبدع')

@section('content')
    <div class="space-y-8">
        <x-page-header title="استوديو المبدع" icon="palette" subtitle="أنشئ أحجيات المجتمع وتابع حالتها وأرقام لعبها." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creators.show', $profile->slug) }}" class="btn-secondary !py-2 !px-4 text-sm">صفحتي العامة</a>
                <a href="{{ route('creator.puzzles.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />أحجية جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400 anim-fade-up">
            أحجيات المجتمع بلا جوائز اقتصادية: لا جواهر ولا نقاط خبرة لك أو للاعبين، وتظهر دائمًا بوسم «من المجتمع».
        </div>

        <section aria-label="أرقام أحجياتك" class="grid grid-cols-2 lg:grid-cols-5 gap-3 anim-fade-up">
            <x-stat-card label="أحجيات منشورة" :value="number_format($stats['published'])" icon="puzzle" />
            <x-stat-card label="لاعبون" :value="number_format($stats['players'])" icon="friends" />
            <x-stat-card label="محاولات" :value="number_format($stats['attempts'])" icon="chart" />
            <x-stat-card label="نسبة الإكمال" :value="$stats['completion_rate'] === null ? '—' : $stats['completion_rate'].'%'" icon="check-circle" />
            <x-stat-card :label="'محاولات آخر '.$stats['recent_days'].' أيام'" :value="number_format($stats['recent_attempts'])" icon="bolt" />
        </section>

        {{-- E29: تفاعل المجتمع مع أعمالك (أرقام مجمَّعة فقط: لا أسماء متابعين ولا مقيِّمين). المتوسط يُخفى تحت الحدّ الأدنى للعيّنة. --}}
        <section aria-label="تفاعل المجتمع" class="grid grid-cols-2 lg:grid-cols-4 gap-3 anim-fade-up">
            <x-stat-card label="متابعون" :value="number_format($stats['followers'])" icon="friends" />
            <x-stat-card label="حفظوا أعمالك" :value="number_format($stats['favorites_received'])" icon="sparkles" />
            <x-stat-card label="تقييمات" :value="number_format($stats['ratings_count'])" icon="chart" />
            <x-stat-card label="متوسط التقييم" :value="$stats['average_rating'] === null ? '—' : number_format($stats['average_rating'], 1).' من 5'" icon="trophy" tone="gold" />
        </section>
        @if ($stats['top_rated_work'])
            <p class="text-sm text-slate-300 anim-fade-up">
                الأعلى تقييمًا: <a href="{{ route('community.puzzles.show', $stats['top_rated_work']['public_id']) }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $stats['top_rated_work']['title'] }}</a>
                <span class="text-slate-400">({{ number_format($stats['top_rated_work']['average'], 1) }} من 5 · {{ number_format($stats['top_rated_work']['count']) }} تقييم)</span>
            </p>
        @endif

        <section aria-label="أعمالي" class="anim-fade-up">
            <x-section-header title="أحجياتي" :href="route('creator.puzzles.index')" linkLabel="عرض الكل" />
            <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 mb-4">
                @foreach (['draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                    <a href="{{ route('creator.puzzles.index', ['status' => $key]) }}" class="chip !py-1.5 !px-3 text-xs">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
                @endforeach
            </nav>

            @forelse ($recent as $work)
                @include('creator.puzzles._row', ['work' => $work])
            @empty
                <x-empty-state icon="palette" title="لم تنشئ أحجية بعد" message="ابدأ بمسودة جديدة، ثم أرسلها للمراجعة لتُنشر للمجتمع." :action="route('creator.puzzles.create')" actionLabel="أحجية جديدة" />
            @endforelse
        </section>
    </div>
@endsection
`````

### `resources/views/creator/public.blade.php`

`sha256: 43de3431dd21a54f2d44661a8917da01bdf2a72f137b20a4fd9c6576cbb769f8`

`````blade
@extends('layouts.app')

@section('title', $creator->user->name.' - مبدع')
@section('content_width', 'max-w-5xl')

{{-- E28: صفحة المبدع العامة. اسم + صورة رمزية + نبذة + تاريخ الانضمام كمبدع + أرقام مجمَّعة + أحجياته الظاهرة فقط. بلا بريد/هاتف/حالة إدارية/بيانات خاصة. --}}
@section('content')
    <div class="space-y-6">
        <section class="glass rounded-3xl p-6 md:p-8 flex flex-col sm:flex-row items-center sm:items-start gap-5 anim-fade-up" aria-labelledby="creator-name">
            <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$creator->user->name" size="xl" />
            <div class="min-w-0 text-center sm:text-start">
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                    <h1 id="creator-name" class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $creator->user->name }}</h1>
                    <x-status-badge tone="primary" icon="palette">مبدع</x-status-badge>
                </div>
                @if ($creator->bio)
                    <p class="mt-2 text-sm text-slate-300 break-words whitespace-pre-line max-w-2xl">{{ $creator->bio }}</p>
                @endif
                @if ($creator->approved_at)
                    <p class="mt-2 text-xs text-slate-400">مبدع منذ {{ $creator->approved_at->translatedFormat('F Y') }}</p>
                @endif

                {{-- E29: المتابعة. العدد المجمَّع علني؛ هوية المتابعين لا تُعرض. الزر يتبع حالة المشاهد (ضيف/مالك/غير موثَّق/مجمَّد/يتابع/يمكنه المتابعة). --}}
                <div class="mt-3 flex flex-wrap items-center justify-center sm:justify-start gap-3">
                    <span class="text-sm font-bold text-slate-300">{{ number_format($followers) }} {{ $followers === 1 ? 'متابع' : 'متابعين' }}</span>
                    @switch($followState)
                        @case('can_follow')
                            <form method="POST" action="{{ route('creators.follow', $creator->slug) }}">
                                @csrf
                                <button type="submit" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-2"><x-engagement-icon name="user-plus" class="w-4 h-4" />متابعة</button>
                            </form>
                            @break
                        @case('following')
                            <form method="POST" action="{{ route('creators.unfollow', $creator->slug) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm inline-flex items-center gap-2" aria-label="إلغاء متابعة هذا المبدع"><x-ui-icon name="check" class="w-4 h-4" />تتابعه</button>
                            </form>
                            @break
                        @case('guest')
                            <a href="{{ route('login') }}" class="btn-secondary !py-2 !px-5 text-sm">سجّل الدخول للمتابعة</a>
                            @break
                        @case('verify')
                            <span class="text-xs font-bold text-gold">وثّق بريدك الإلكتروني لتتمكن من المتابعة.</span>
                            @break
                        @case('frozen')
                            <span class="text-xs font-bold text-gold">حسابك مجمَّد؛ لا يمكن المتابعة حاليًا.</span>
                            @break
                    @endswitch
                </div>
                @if (session('engagement_status'))
                    <p role="status" class="mt-2 text-xs font-bold text-emerald">{{ session('engagement_status') }}</p>
                @endif
            </div>
        </section>

        <section aria-label="أرقام المبدع" class="grid grid-cols-2 {{ $quality ? 'md:grid-cols-3' : '' }} gap-3 anim-fade-up d-1">
            <x-stat-card label="أحجيات منشورة" :value="number_format($published)" icon="puzzle" />
            <x-stat-card label="إجمالي اللاعبين" :value="number_format($plays)" icon="friends" />
            @if ($quality)
                {{-- E29: ملخّص الجودة يظهر فقط ببلوغ حدّ أدنى من التقييمات على أعماله الظاهرة (لا «5.0» من عيّنة صغيرة). --}}
                <x-stat-card label="متوسط تقييم أعماله" :value="number_format($quality['average'], 1).' من 5'" icon="sparkles" tone="gold" :hint="number_format($quality['count']).' تقييم'" />
            @endif
        </section>

        <section aria-label="أحجيات المبدع" class="anim-fade-up d-2">
            <x-section-header title="أحجياته" />
            @if ($works->isEmpty())
                <x-empty-state icon="puzzle" title="لا أحجيات منشورة حاليًا" message="سيظهر هنا ما ينشره هذا المبدع بعد اعتماده من الإشراف." />
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($works as $work)
                        @include('community._card', ['work' => $work, 'savedIds' => $savedIds ?? []])
                    @endforeach
                </div>
                <div class="flex justify-center mt-6">{{ $works->links() }}</div>
            @endif
        </section>
    </div>
@endsection
`````

### `resources/views/discover/index.blade.php`

`sha256: b16f0d6b380cd7d7214103869ded264c2aa6772577e674412ece5413670028c8`

`````blade
@extends('layouts.app')

@section('title', 'اكتشف')
@section('meta_description', 'الرائج والجديد من الأحجيات والحملات والمنافسات والفرق، ومقترحات تناسبك.')

@section('content')
    <div class="space-y-10">

        <x-page-header title="اكتشف" :subtitle="$personalized ? 'ما يناسبك الآن، والرائج والجديد في المنصة.' : 'الرائج والجديد في المنصة. سجّل دخولك لتحصل على مقترحات تناسبك.'" icon="sparkles" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('search.index') }}" role="search" class="glass rounded-3xl p-4 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="discover-q" class="sr-only">ابحث في المنصة</label>
            <input id="discover-q" type="search" name="q" maxlength="64" autocomplete="off" enterkeyhint="search" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        @if ($categories->isNotEmpty())
            <nav aria-label="تصنيفات الأحجيات" class="flex flex-wrap gap-2 anim-fade-up">
                @foreach ($categories as $category)
                    <a href="{{ route('puzzles.category', $category) }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        {{-- E29: مدخل صغير لقوائم المستخدم الخاصة (مفضلة/متابَعون/مجموعات) للموثَّق فقط؛ بلا تغيير تصميم الصفحة. --}}
        @if (auth()->user()?->email_verified_at !== null)
            <nav aria-label="قوائمي" class="flex flex-wrap gap-2 anim-fade-up">
                <a href="{{ route('following.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">من أتابعهم</a>
                <a href="{{ route('favorites.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مفضلتي</a>
                <a href="{{ route('collections.index') }}" class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">مجموعاتي</a>
            </nav>
        @endif

        @forelse ($sections as $section)
            <section aria-label="{{ $section['title'] }}" data-discover-section="{{ $section['key'] }}" class="anim-fade-up">
                <x-section-header :title="$section['title']" :href="$section['href']" :linkLabel="$section['linkLabel'] ?? 'عرض الكل'" />
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ($section['cards'] as $card)
                        @include('discover._card', ['card' => $card])
                    @endforeach
                </div>
            </section>
        @empty
            <x-empty-state icon="sparkles" title="لا محتوى للاكتشاف بعد" message="عند نشر أحجيات ومنافسات جديدة ستظهر هنا." :action="route('puzzles.index')" actionLabel="تصفّح الأحجيات" />
        @endforelse
    </div>
@endsection
`````


---

## C) MIGRATIONS (جديد فقط — لا تعديل على أي ترحيل قائم)


### `database/migrations/2026_10_21_000001_create_community_engagement_tables.php`

المحتوى الكامل بالقسم A أعلاه (نفس الملف)، `sha256: 2f0f23fc11687c779fc5f882f8290af4de851a3d8b03b075f29ae1dd97c6af50`.

الأعمدة المضافة: `creator_puzzles.{ratings_count,ratings_sum,favorites_count}`، `creator_profiles.followers_count`؛ جداول: `community_puzzle_ratings`، `community_puzzle_favorites`، `creator_follows`، `user_collections`، `user_collection_items`. `down()` يعكس كل شيء. لا بيانات تُحذف.


---

## D) ENV / CONFIG — الأسطر المضافة (كلها اختيارية بافتراضيات آمنة، معلَّق عليها بـ`#`)

```dotenv
# E29 - التفاعل المجتمعي: التقييمات والمفضلة ومتابعة المبدعين والمجموعات الشخصية والأعلى تقييمًا (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/community-engagement-e29.md.
# التفاعل بلا أي سلطة اقتصادية بأي إعداد (لا جواهر/XP/مهام/سلسلة/إنجازات/صدارة): لا مفتاح بيئة لذلك.
# ENGAGEMENT_RATING_DISPLAY_MIN=3            # أقل عدد تقييمات لإظهار «المتوسط» علنًا (تحته يُعرض العدد فقط)
# ENGAGEMENT_TOP_MIN_RATINGS=5               # أقل عدد تقييمات كي يدخل العمل ترتيب «الأعلى تقييمًا»
# ENGAGEMENT_TOP_PRIOR_MEAN=3.5              # المتوسط الافتراضي للانكماش البايزي: score = (sum + mean*weight) / (count + weight)
# ENGAGEMENT_TOP_PRIOR_WEIGHT=5              # وزن المتوسط الافتراضي (كم «تقييمًا وهميًا»)؛ كلما كبر احتاج الوصول للقمة تقييمات أكثر
# ENGAGEMENT_TOP_CACHE_TTL=300               # عمر لقطة الأعلى تقييمًا بالثواني (معرّفات ودرجات فقط؛ الظهور يُفحص حيًّا)
# ENGAGEMENT_CREATOR_MIN_RATINGS=5           # أقل مجموع تقييمات لإظهار ملخّص جودة المبدع بصفحته العامة
# ENGAGEMENT_MAX_FOLLOWS=500                 # سقف من يتابعهم المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTIONS=20              # سقف مجموعات المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTION_ITEMS=100        # سقف عناصر المجموعة الواحدة
# ENGAGEMENT_NOTIFY_ON_REVISION=true         # إشعار المتابعين أيضًا عند اعتماد نسخة جديدة لعمل منشور سابقًا
```

ملف الإعداد الجديد: `config/engagement.php` (قسم A).


---

## E) LOCAL COMMANDS (بلا أي أمر git)

```powershell
# من جذر المشروع بعد نسخ الملفات:
php artisan migrate
php artisan optimize:clear
php artisan test tests/Feature/EngagementE29
php artisan test tests/Feature/CreatorE28
php artisan test tests/Feature/SecurityE26/E26StaticAuditTest.php
php artisan community:reconcile-engagement --dry-run
# اختياري (بيئة محلية فقط، يرفض الإنتاج):
php artisan db:seed --class=DemoEngagementSeeder
```

لا `composer install` ولا `composer update` ولا `npm run build` (لم تتغير أصول الواجهة). لا `php artisan test` بلا مسار.
