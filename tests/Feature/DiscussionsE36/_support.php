<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Discussions\DiscussionSubject;
use App\Services\Discussions\DiscussionSubjectRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E36 - مساعدات مشتركة لاختبارات النقاشات. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. كل التهيئة تمرّ بالخدمات الرسمية (تعليق/رد/إعجاب/بلاغ/إخفاء/قفل) كي تختبر الاختبارات المسار الحقيقي.
| تعتمد على مساعدات E35 (مجموعات) وE30 (سلاسل) وE29/E28 (أعمال مجتمع) وE16 (مستخدمون/حظر) وE27 (عدّ الاستعلامات).
*/

require_once __DIR__.'/../PublicCollectionsE35/_support.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';
require_once __DIR__.'/../DiscoveryE27/_support.php';

if (! function_exists('e36_seed')) {
    function e36_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    function e36_work(?User $creator = null): CreatorPuzzle
    {
        return e29_work($creator)->fresh();
    }

    function e36_series(?User $creator = null): CreatorSeries
    {
        return e30s_published($creator ?? e28_creator())->fresh();
    }

    function e36_registry(): DiscussionSubjectRegistry
    {
        return app(DiscussionSubjectRegistry::class);
    }

    /** يحلّ موضوع النقاش لكيان (أحجية/سلسلة/مجموعة) كما يراه $viewer. null = غير متاح للنقاش. */
    function e36_subject(CreatorPuzzle|CreatorSeries|UserCollection $entity, ?User $viewer = null): ?DiscussionSubject
    {
        $type = match (true) {
            $entity instanceof CreatorPuzzle => DiscussionThread::TYPE_COMMUNITY_PUZZLE,
            $entity instanceof CreatorSeries => DiscussionThread::TYPE_CREATOR_SERIES,
            default => DiscussionThread::TYPE_PUBLIC_COLLECTION,
        };

        return e36_registry()->resolve($type, (string) $entity->public_id, $viewer);
    }

    function e36_subject_or_fail(CreatorPuzzle|CreatorSeries|UserCollection $entity, ?User $viewer = null): DiscussionSubject
    {
        $subject = e36_subject($entity, $viewer);
        expect($subject)->not->toBeNull('الموضوع يجب أن يكون متاحًا للنقاش');

        return $subject;
    }

    function e36_url(CreatorPuzzle|CreatorSeries|UserCollection $entity): string
    {
        return match (true) {
            $entity instanceof CreatorPuzzle => route('community.puzzles.show', $entity->public_id),
            $entity instanceof CreatorSeries => route('series.show', $entity->public_id),
            default => route('collections.public.show', $entity->public_id),
        };
    }

    /** نص فريد (تفادي رفض التكرار خلال النافذة). */
    function e36_text(string $prefix = 'تعليق'): string
    {
        return $prefix.' '.fake()->unique()->numerify('######');
    }

    /** تعليق (أو رد إن مُرّر الأب) بالخدمة الرسمية. */
    function e36_say(User $user, DiscussionSubject $subject, ?string $body = null, ?DiscussionComment $parent = null): DiscussionComment
    {
        return app(DiscussionCommentService::class)->create($user, $subject, $body ?? e36_text(), $parent?->public_id)->fresh();
    }

    function e36_mod(): User
    {
        return e28_reviewer('moderator');
    }

    function e36_hide(DiscussionComment $comment, ?User $mod = null, string $reason = 'spam', ?string $note = null): DiscussionComment
    {
        return app(DiscussionModerationService::class)->hide($comment->fresh(), $mod ?? e36_mod(), $reason, $note)->fresh();
    }

    function e36_lock(DiscussionSubject $subject, ?User $mod = null, ?string $note = null): DiscussionThread
    {
        $thread = DiscussionThread::query()->where('subject_type', $subject->type)->where('subject_id', $subject->id)->firstOrFail();

        return app(DiscussionModerationService::class)->lockThread($thread, $mod ?? e36_mod(), $note)->fresh();
    }

    function e36_thread(DiscussionSubject $subject): ?DiscussionThread
    {
        return DiscussionThread::query()->where('subject_type', $subject->type)->where('subject_id', $subject->id)->first();
    }

    /** @return list<User> $n لاعبين موثَّقين. */
    function e36_users(int $n): array
    {
        return array_map(fn () => e16User(), range(1, $n));
    }

    /** يعيد رسالة DiscussionException (أو null إن لم يُرمَ استثناء). */
    function e36_error(callable $call): ?string
    {
        try {
            $call();
        } catch (\App\Services\Engagement\EngagementException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** لقطة اقتصاد/تقدّم/إحالات/منافسة/LiveOps كاملة: أي تغيّر بعد نشاط نقاش = اختراق للعزل. */
    function e36_world(User ...$users): array
    {
        $tables = ['xp_transactions', 'currency_transactions', 'user_quest_progress', 'player_streaks', 'user_achievement_progress', 'user_referrals', 'referral_codes',
            'competitive_event_participants', 'competitive_event_results', 'competitive_reward_grants', 'user_mission_assignments', 'redemption_requests', 'game_sessions', 'puzzle_attempts', 'fraud_flags'];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        $per = [];

        foreach ($users as $user) {
            $per[$user->id] = e28_world($user);
        }

        return ['counts' => $counts, 'per_user' => $per, 'wallets' => (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'), 'xp' => (int) DB::table('player_progressions')->sum('total_xp')];
    }

    function e36_audit(string $action): ?\App\Models\OperationalAuditLog
    {
        return \App\Models\OperationalAuditLog::query()->where('action', $action)->orderByDesc('id')->first();
    }

    /** يقتطع الأسطر التي تحوي data-comment لتعليق معيّن من HTML. */
    function e36_comment_html(string $html, DiscussionComment $comment): string
    {
        $start = strpos($html, 'id="c-'.$comment->public_id.'"');

        return $start === false ? '' : substr($html, $start, 4000);
    }
}
