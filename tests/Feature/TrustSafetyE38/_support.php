<?php

use App\Models\ChatMessage;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\FraudFlag;
use App\Models\ModerationAction;
use App\Models\ModerationCase;
use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Services\Chat\ChatReportService;
use App\Services\Collections\CollectionReportService;
use App\Services\Creators\CommunityReportService;
use App\Services\Discussions\DiscussionReportService;
use App\Services\TrustSafety\AppealService;
use App\Services\TrustSafety\CaseService;
use App\Services\TrustSafety\EnforcementService;
use App\Services\TrustSafety\InboxService;
use App\Services\TrustSafety\ModerationException;
use Illuminate\Support\Facades\Cache;

/*
| E38 - مساعدات مشتركة لاختبارات مركز الثقة والسلامة. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. كل التهيئة تمرّ بالخدمات الرسمية (بلاغات E21/E28/E35/E36، حالات/إجراءات/اعتراضات E38) كي تختبر الاختبارات المسار الحقيقي.
| تعتمد على مساعدات E28/E29/E35/E36 (مبدعون/أعمال/مجموعات/تعليقات) وE21 (دردشة) وE16 (مستخدمون).
*/

require_once __DIR__.'/../DiscussionsE36/_support.php';
require_once __DIR__.'/../Chat/ChatTestHelpers.php';

if (! function_exists('e38_seed')) {
    function e38_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    /** مشرف بصلاحيات الثقة والسلامة الافتراضية للدور (view/manage-cases/moderate-content/review-appeals) + صلاحيات مجالاته. */
    function e38_mod(): User
    {
        return e28_reviewer('moderator');
    }

    /** مدير عام (administrator): كل الصلاحيات بما فيها التجميد والتعليق والإشراف الأعلى. */
    function e38_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    function e38_super(): User
    {
        $user = e16User();
        $user->assignRole('super-admin');

        return $user;
    }

    /** مشرف بصلاحيات إضافية صريحة (للاختبارات الدقيقة). */
    function e38_mod_with(array $permissions): User
    {
        return e38_mod()->givePermissionTo($permissions);
    }

    function e38_cases(): CaseService
    {
        return app(CaseService::class);
    }

    function e38_enforce(): EnforcementService
    {
        return app(EnforcementService::class);
    }

    function e38_appeals(): AppealService
    {
        return app(AppealService::class);
    }

    function e38_inbox(): InboxService
    {
        return app(InboxService::class);
    }

    function e38_puzzle_report(?User $reporter = null, ?CreatorPuzzle $work = null, string $reason = 'spam', ?string $details = null): CommunityPuzzleReport
    {
        $work ??= e36_work();

        return app(CommunityReportService::class)->report($reporter ?? e16User(), $work, $reason, $details);
    }

    function e38_public_collection(?User $owner = null): UserCollection
    {
        return e35_public($owner ?? e16User());
    }

    function e38_collection_report(?User $reporter = null, ?UserCollection $collection = null, string $reason = 'spam'): UserCollectionReport
    {
        $collection ??= e38_public_collection();

        return app(CollectionReportService::class)->report($reporter ?? e16User(), (string) $collection->public_id, $reason);
    }

    /** تعليق على أحجية مجتمع + بلاغ عليه. @return array{0: DiscussionComment, 1: DiscussionCommentReport} */
    function e38_comment_with_report(?User $reporter = null, ?User $author = null, string $reason = 'harassment', ?CreatorPuzzle $work = null): array
    {
        $work ??= e36_work();
        $subject = e36_subject_or_fail($work);
        $comment = e36_say($author ?? e16User(), $subject);
        $report = app(DiscussionReportService::class)->report($reporter ?? e16User(), (string) $comment->public_id, $reason);

        return [$comment->fresh(), $report];
    }

    /** رسالة دردشة عامة + بلاغ عليها. @return array{0: ChatMessage, 1: \App\Models\ChatMessageReport} */
    function e38_chat_with_report(?User $reporter = null, ?User $author = null, string $category = 'spam'): array
    {
        \Carbon\Carbon::setTestNow(now()->addSeconds(11));
        $message = chatMessages()->send($author ?? e16User(), chatGlobal(), 'رسالة اختبار '.fake()->unique()->numerify('####'));
        $report = app(ChatReportService::class)->report($reporter ?? e16User(), $message, $category, 'تفاصيل بلاغ الدردشة');

        return [$message->fresh(), $report];
    }

    function e38_fraud_flag(?User $user = null, string $reason = 'multiple_accounts', string $severity = 'medium'): FraudFlag
    {
        return FraudFlag::create(['user_id' => ($user ?? e16User())->id, 'reason' => $reason, 'severity' => $severity, 'details' => 'تفاصيل داخلية للإشارة']);
    }

    /** يفتح حالة من مراجع بلاغات (كائنات تقارير أو نصوص type:id). @param list<object|string> $refs */
    function e38_open(array $refs, ?User $actor = null, ?string $summary = null, ?string $priority = null): ModerationCase
    {
        $actor ??= e38_mod();
        $refs = array_map(fn ($r) => is_string($r) ? $r : e38_ref($r), $refs);

        return e38_cases()->open($actor, $refs, $summary, $priority);
    }

    function e38_ref(object $report): string
    {
        return match (true) {
            $report instanceof CommunityPuzzleReport => 'community_puzzle_report:'.$report->id,
            $report instanceof UserCollectionReport => 'user_collection_report:'.$report->id,
            $report instanceof DiscussionCommentReport => 'discussion_comment_report:'.$report->id,
            $report instanceof \App\Models\ChatMessageReport => 'chat_message_report:'.$report->id,
            $report instanceof FraudFlag => 'fraud_flag:'.$report->id,
            default => throw new InvalidArgumentException('نوع بلاغ غير معروف'),
        };
    }

    /** حالة جاهزة للتنفيذ: بلاغ أحجية مجتمع، مفتوحة ومعيّنة للمشرف. @return array{0: ModerationCase, 1: User, 2: CreatorPuzzle, 3: User} case, mod, work, creator */
    function e38_puzzle_case(?User $mod = null): array
    {
        $creator = e28_creator();
        $work = e36_work($creator);
        $report = e38_puzzle_report(null, $work, 'inappropriate');
        $mod ??= e38_admin();
        $case = e38_open([$report], $mod);
        e38_cases()->claim($case, $mod);

        return [$case->fresh(), $mod, $work->fresh(), $creator];
    }

    /** حالة جاهزة على مجموعة عامة. @return array{0: ModerationCase, 1: User, 2: UserCollection, 3: User} */
    function e38_collection_case(?User $mod = null): array
    {
        $owner = e16User();
        $collection = e38_public_collection($owner);
        $report = e38_collection_report(null, $collection, 'harassment');
        $mod ??= e38_admin();
        $case = e38_open([$report], $mod);
        e38_cases()->claim($case, $mod);

        return [$case->fresh(), $mod, $collection->fresh(), $owner];
    }

    /** حالة تعليق نقاش جاهزة. @return array{0: ModerationCase, 1: User, 2: DiscussionComment, 3: User} */
    function e38_comment_case(?User $mod = null): array
    {
        $author = e16User();
        [$comment, $report] = e38_comment_with_report(null, $author, 'harassment');
        $mod ??= e38_admin();
        $case = e38_open([$report], $mod);
        e38_cases()->claim($case, $mod);

        return [$case->fresh(), $mod, $comment->fresh(), $author];
    }

    /** حالة إشارة احتيال جاهزة (موضوعها المستخدم). @return array{0: ModerationCase, 1: User, 2: User} */
    function e38_user_case(?User $mod = null, ?User $subject = null): array
    {
        $subject ??= e16User();
        $flag = e38_fraud_flag($subject, 'multiple_accounts', 'high');
        $mod ??= e38_admin();
        $case = e38_open([$flag], $mod);
        e38_cases()->claim($case, $mod);

        return [$case->fresh(), $mod, $subject->fresh()];
    }

    /** ينفّذ إجراءً بمدخلات صالحة افتراضيًا. */
    function e38_apply(ModerationCase $case, User $actor, string $action, array $input = []): ModerationAction
    {
        $defaults = ['reason' => 'سبب داخلي واضح لاتخاذ الإجراء', 'public_note' => 'مخالفة لإرشادات المجتمع.', 'confirm' => true];

        return e38_enforce()->apply($case->fresh(), $actor, $action, $input + $defaults);
    }

    /** رسالة ModerationException (أو null إن لم تُرمَ). */
    function e38_error(callable $call): ?string
    {
        try {
            $call();
        } catch (ModerationException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** أنواع أحداث التسلسل الزمني للحالة بالترتيب. @return list<string> */
    function e38_events(ModerationCase $case): array
    {
        return $case->events()->orderBy('id')->pluck('type')->all();
    }
}
