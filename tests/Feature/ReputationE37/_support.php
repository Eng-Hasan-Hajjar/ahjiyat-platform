<?php

use App\Models\CommunityReputationSnapshot;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\ReputationRankings;
use App\Services\Reputation\ReputationRecognitionContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| E37 - مساعدات مشتركة لاختبارات السمعة المجتمعية. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. الأعمال والمبدعون والمجموعات والتعليقات والإعجابات تمرّ بالخدمات الرسمية حين يهم المسار،
| أما **أحجام التقييم/المفضلة/اللعب/المتابعة الكبيرة** فتُدرَج صفوف سلطة مباشرة (المقاييس تُقرأ من هذه الصفوف نفسها لا من عدّادات مشتقّة).
| تعتمد على مساعدات E36 (وبالتسلسل E35/E30/E29/E28/E27/E16).
*/

require_once __DIR__.'/../DiscussionsE36/_support.php';

if (! function_exists('e37_seed')) {
    function e37_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        ReputationRecognitionContext::flush();
        config(['reputation.rankings.cache_seconds' => 0]); // اختبارات حتمية: لا كاش ما لم يطلبه اختبار بعينه
    }

    /** مبدع معتمَد بأعمال منشورة. @return array{0: User, 1: list<CreatorPuzzle>} */
    function e37_creator(int $works = 2, ?User $creator = null): array
    {
        $creator ??= e28_creator();
        $list = [];

        for ($i = 0; $i < $works; $i++) {
            $list[] = e29_work($creator)->fresh();
        }

        return [$creator, $list];
    }

    /** @return list<User> */
    function e37_users(int $n): array
    {
        return array_map(fn () => e16User(), range(1, $n));
    }

    function e37_rate(CreatorPuzzle $work, User $user, int $rating, ?string $at = null): void
    {
        DB::table('community_puzzle_ratings')->updateOrInsert(
            ['user_id' => $user->id, 'creator_puzzle_id' => $work->id],
            ['rating' => $rating, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]
        );
    }

    function e37_fav(CreatorPuzzle $work, User $user, ?string $at = null): void
    {
        DB::table('community_puzzle_favorites')->updateOrInsert(['user_id' => $user->id, 'creator_puzzle_id' => $work->id], ['created_at' => $at ?? now()]);
    }

    function e37_play(CreatorPuzzle $work, User $user, ?string $at = null): void
    {
        DB::table('community_puzzle_attempts')->insert([
            'user_id' => $user->id, 'creator_puzzle_id' => $work->id, 'puzzle_id' => $work->published_puzzle_id, 'attempt_number' => 1, 'is_correct' => false, 'created_at' => $at ?? now(),
        ]);
    }

    function e37_follow(User $creator, User $follower, ?string $at = null): void
    {
        DB::table('creator_follows')->updateOrInsert(
            ['user_id' => $follower->id, 'creator_profile_id' => $creator->creatorProfile()->first()->id], ['created_at' => $at ?? now()]
        );
    }

    /**
     * مبدع **مؤهَّل للترتيب** افتراضيًا: عملان ظاهران وكل مقيِّم يقيّم العملين (6 مقيِّمين ⇒ 12 تقييمًا من 6 مقيِّمين مميَّزين).
     *
     * @param  list<int>  $ratings  تقييم كل مقيِّم (يُعاد على كل عمل)
     * @return array{0: User, 1: list<CreatorPuzzle>, 2: list<User>}
     */
    function e37_eligible_creator(array $ratings = [5, 5, 4, 5, 4, 5], int $works = 2, ?array $raters = null, ?string $at = null): array
    {
        [$creator, $list] = e37_creator($works);
        $raters ??= e37_users(count($ratings));

        foreach ($list as $work) {
            foreach ($raters as $i => $rater) {
                e37_rate($work, $rater, $ratings[$i % count($ratings)], $at);
            }
        }

        return [$creator, $list, $raters];
    }

    /** يعيد الحساب والكتابة لمستخدمين. @return array{processed:int, created:int, changed:int, unchanged:int, rows: array<int, array<string, mixed>>} */
    function e37_refresh(User ...$users): array
    {
        return app(CommunityReputationService::class)->refreshUsers(array_map(fn (User $u) => (int) $u->id, $users));
    }

    function e37_snap(User $user): ?CommunityReputationSnapshot
    {
        return CommunityReputationSnapshot::query()->where('user_id', $user->id)->first();
    }

    function e37_rankings(): ReputationRankings
    {
        return app(ReputationRankings::class);
    }

    /** أسماء قائمة ترتيب. @return list<string> */
    function e37_names(array $rows): array
    {
        return array_map(fn ($r) => $r['name'], $rows);
    }

    function e37_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    function e37_manager(): User
    {
        $user = e16User();
        $user->assignRole('content-manager');

        return $user;
    }

    /**
     * لقطة اقتصاد/تقدّم/إحالات/منافسة/دعم **بلا تقدّم الإنجازات** (الاعتراف يكتب تقدّم إنجازات بتصميمه وبلا مكافآت: يُقاس على حدة):
     * أي تغيّر بعد حساب سمعة أو تغيّر ترتيب = اختراق لعزل الاقتصاد.
     */
    function e37_world(User ...$users): array
    {
        $tables = ['xp_transactions', 'currency_transactions', 'gem_transactions', 'user_quest_progress', 'player_streaks', 'user_referrals', 'referral_codes',
            'competitive_event_participants', 'competitive_event_results', 'competitive_reward_grants', 'user_mission_assignments', 'redemption_requests', 'game_sessions',
            'puzzle_attempts', 'fraud_flags', 'payment_requests', 'sponsor_contributions'];

        $counts = [];

        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        $per = [];

        foreach ($users as $user) {
            $per[$user->id] = ['wallet' => e26_wallet_state($user), 'xp' => (int) DB::table('player_progressions')->where('user_id', $user->id)->sum('total_xp')];
        }

        return ['counts' => $counts, 'per_user' => $per, 'wallets' => (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'), 'xp' => (int) DB::table('player_progressions')->sum('total_xp')];
    }

    /**
     * مبدع وهمي **رخيص** للقياس/الحجم (بلا خدمات النشر): مستخدم موثَّق + ملف معتمَد + $works عمل ظاهر + لقطة جاهزة مؤهَّلة بدرجة معطاة.
     * مناسب لاختبارات القراءة والأداء (الفحص الحيّ يقرأ creator_profiles/users/creator_puzzles الفعلية).
     *
     * @param  array<string, mixed>  $snap  تجاوزات أعمدة اللقطة
     */
    function e37_bulk_creator(int $score = 5000, int $works = 2, array $snap = [], array $userAttrs = []): User
    {
        $user = e16User($userAttrs);
        $profileId = DB::table('creator_profiles')->insertGetId([
            'user_id' => $user->id, 'slug' => 'bulk-'.Str::lower(Str::random(10)), 'bio' => 'نبذة', 'status' => CreatorProfile::STATUS_APPROVED,
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $works; $i++) {
            DB::table('creator_puzzles')->insert([
                'public_id' => (string) Str::ulid(), 'creator_profile_id' => $profileId, 'published_title' => 'عمل '.$i, 'published_difficulty' => 'easy',
                'published_at' => now(), 'first_published_at' => now(), 'is_visible' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        e37_snapshot_row($user, $snap + [
            'creator_score' => $score, 'creator_eligible' => true, 'works_count' => $works, 'ratings_count' => 10, 'ratings_sum' => 45, 'raters_count' => 6,
        ]);

        return $user;
    }

    /** صف لقطة مباشر (للقراءة/الأداء فقط). */
    function e37_snapshot_row(User $user, array $over = []): void
    {
        DB::table('community_reputation_snapshots')->updateOrInsert(['user_id' => $user->id], $over + [
            'formula_version' => 1, 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    function e37_profile(User $creator): CreatorProfile
    {
        return CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail();
    }

    /** مجموعة عامة مُدرَجة بمالك معطى + $savers حافظين مميَّزين (حفظ مباشر بصفوف سلطة، المالك لا يحفظ). @return array{0: \App\Models\UserCollection, 1: list<User>} */
    function e37_public_collection(User $owner, int $savers, int $items = 3, string $title = 'مجموعة'): array
    {
        $collection = e35_collection($owner, e35_works($items), 'public', $title);
        $users = e37_users($savers);

        foreach ($users as $user) {
            DB::table('user_collection_saves')->insert(['user_id' => $user->id, 'user_collection_id' => $collection->id, 'created_at' => now()]);
        }

        return [$collection, $users];
    }

    /** يعيد استبدال سجل الاستعلامات ويحسب استعلامات قراءة لقطات السمعة والاعتراف أثناء $run. */
    function e37_queries(callable $run): int
    {
        return e27_queries($run);
    }
}

if (! function_exists('e37_metrics')) {
    /** مقاييس مصدر لمبدع نشط افتراضيًا (تجاوز أي مقياس). */
    function e37_metrics(array $over = []): array
    {
        return $over + array_replace(app(\App\Services\Reputation\ReputationMetricsCollector::class)->blank(1, false, true, true), ['creator_active' => true, 'creator_status' => 'approved', 'works' => 3]);
    }
}

if (! function_exists('e37_scores')) {
    function e37_scores(array $over = []): array
    {
        return app(CommunityReputationService::class)->scores(e37_metrics($over), now());
    }
}

if (! function_exists('e37_curator')) {
    /** منسّق بمجموعتين عامتين (3 أعمال لكل منهما) وحافظين فريدين على كل منهما. @return array{0: User, 1: list<\App\Models\UserCollection>, 2: list<User>} */
    function e37_curator(int $savers = 6, int $collections = 2): array
    {
        $owner = e16User();
        $savedBy = e37_users($savers);
        $list = [];

        for ($i = 0; $i < $collections; $i++) {
            $collection = e35_collection($owner, e35_works(3), 'public', 'مجموعة '.($i + 1));

            foreach ($savedBy as $user) {
                DB::table('user_collection_saves')->insert(['user_id' => $user->id, 'user_collection_id' => $collection->id, 'created_at' => now()]);
            }

            $list[] = $collection;
        }

        return [$owner, $list, $savedBy];
    }

    /** كاتب بـ$comments تعليقًا حيًّا (أعمال ظاهرة مختلفة) + $likers معجبين فريدين على أول تعليق. @return array{0: User, 1: list<\App\Models\DiscussionComment>, 2: list<User>} */
    function e37_helper(int $comments = 5, int $likers = 10): array
    {
        $author = e16User();
        $made = [];

        for ($i = 0; $i < $comments; $i++) {
            $made[] = e36_say($author, e36_subject_or_fail(e36_work()));
        }

        $fans = e37_users($likers);

        foreach ($fans as $fan) {
            DB::table('discussion_comment_likes')->insert(['user_id' => $fan->id, 'discussion_comment_id' => $made[0]->id, 'created_at' => now()]);
        }

        return [$author, $made, $fans];
    }

    /** يزرع تعريفات الاعتراف (إنجازات بلا مكافآت). */
    function e37_recognition_seed(): void
    {
        test()->seed(\Database\Seeders\CommunityRecognitionSeeder::class);
    }

    /** أسماء الاعتراف المفتوح (الإنجازات المجتمعية) لمستخدم. @return list<string> */
    function e37_unlocked(User $user): array
    {
        $keys = array_keys((array) config('reputation.recognition', []));

        return DB::table('user_achievement_progress as p')->join('achievements as a', 'a.id', '=', 'p.achievement_id')
            ->where('p.user_id', $user->id)->whereNotNull('p.unlocked_at')->whereIn('a.internal_key', $keys)->orderBy('a.internal_key')->pluck('a.internal_key')->all();
    }

    function e37_notes(User $user, ?string $typeKey = null): int
    {
        return (int) DB::table('notifications')->where('notifiable_id', $user->id)->when($typeKey, fn ($q) => $q->where('type_key', $typeKey))->count();
    }
}

if (! function_exists('e37_sql')) {
    /** يجمع كل استعلامات SQL أثناء $run (بأحرف صغيرة). @return list<string> */
    function e37_sql(callable $run): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = strtolower($query->sql);
        });
        $run();

        return $sql;
    }
}
