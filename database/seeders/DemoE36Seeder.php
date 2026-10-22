<?php

namespace Database\Seeders;

use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Discussions\DiscussionSubject;
use App\Services\Discussions\DiscussionSubjectRegistry;
use App\Services\Discussions\DiscussionThreadService;
use App\Services\Engagement\CollectionService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E36 - بيانات Demo للنقاشات.   php artisan db:seed --class=DemoE36Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق discussions.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من DemoQaSeeder.
 * - تعتمد على عمل مجتمع منشور وسلسلة منشورة (تستدعي DemoCreatorSeeder / DemoCreatorSeriesSeeder إن لم يوجدا) وتنشئ مجموعة عامة تجريبية.
 * - **بالخدمات الرسمية فقط** (تعليق/رد/إعجاب/حذف/بلاغ/إخفاء/قفل): تحقق + عدّادات صحيحة + تدقيق. لا جواهر ولا XP ولا مهام ولا أي أثر اقتصادي.
 * - تولّد: نقاش على كل نوع موضوع (أحجية، سلسلة، مجموعة عامة)، تعليقات جذرية وردود وإعجابات، تعليقًا جذريًا محذوفًا له ردّ (لحد)، تعليقًا مخفيًا إشرافيًا،
 *   بلاغًا مفتوحًا، ونقاشًا مقفلًا.
 * - Idempotent: كل تعليق يُحدَّد بكاتبه ونصه ونقاشه، والخدمات تتجاهل المكرَّر ⇒ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE36Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-discussions-admin@discussions.test';

    public const COLLECTION_TITLE = 'نقاش: ألغاز بعد العشاء';

    /** @var array<string, User> */
    protected array $people = [];

    public function run(): void
    {
        $this->refuseInProduction('DemoE36Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        if (! CreatorPuzzle::query()->visible()->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        if (! app(CreatorSeriesVisibilityService::class)->visibleQuery()->exists()) {
            $this->call(DemoCreatorSeriesSeeder::class);
        }

        $work = CreatorPuzzle::query()->visible()->orderBy('id')->first();
        $series = app(CreatorSeriesVisibilityService::class)->visibleQuery()->orderBy('creator_series.id')->first();

        if ($work === null || $series === null) {
            $this->command?->warn('DemoE36Seeder: لا عمل مجتمع أو سلسلة منشورة.');

            return;
        }

        $admin = $this->user(self::ADMIN_EMAIL, 'مشرف النقاشات التجريبي');
        $admin->syncRoles(['administrator']);

        foreach (['a' => 'هدى', 'b' => 'سامر', 'c' => 'ليلى', 'd' => 'كريم', 'e' => 'نور'] as $key => $name) {
            $this->people[$key] = $this->user("demo-talker-{$key}@discussions.test", $name.' (تجريبي)');
        }

        $registry = app(DiscussionSubjectRegistry::class);
        $puzzle = $registry->resolve(DiscussionThread::TYPE_COMMUNITY_PUZZLE, (string) $work->public_id, null);
        $seriesSubject = $registry->resolve(DiscussionThread::TYPE_CREATOR_SERIES, (string) $series->public_id, null);
        $collectionSubject = $this->publicCollection($work);

        if ($puzzle === null || $seriesSubject === null || $collectionSubject === null) {
            $this->command?->warn('DemoE36Seeder: تعذّر حلّ أحد المواضيع الثلاثة.');

            return;
        }

        $this->puzzleThread($puzzle, $admin);
        $this->seriesThread($seriesSubject);
        $this->collectionThread($collectionSubject, $admin);

        $this->command?->info('DemoE36Seeder: نقاش على أحجية وسلسلة ومجموعة عامة؛ تعليقات وردود وإعجابات، تعليق محذوف بردّ، تعليق مخفي، بلاغ مفتوح، ونقاش مقفل. كلمة المرور: '.self::PASSWORD);
    }

    protected function puzzleThread(DiscussionSubject $s, User $admin): void
    {
        [$a, $b, $c, $d, $e] = [$this->people['a'], $this->people['b'], $this->people['c'], $this->people['d'], $this->people['e']];

        $root1 = $this->say($a, $s, 'أحجية جميلة، استغرقت مني عشر دقائق حتى وصلت للحل!');
        $this->say($b, $s, 'وأنا أيضًا، التلميح ساعدني كثيرًا.', $root1);
        $this->say($c, $s, 'هل أحد وجد حلًّا ثانيًا؟ أظن أن هناك طريقة أقصر.', $root1);
        $root2 = $this->say($a, $s, 'أنصح بتجربتها قبل النوم، فهي هادئة.');

        // لحد: جذر يحذفه صاحبه وله ردّ ⇒ يبقى «تم حذف هذا التعليق» والرد ظاهرًا.
        $gone = $this->say($e, $s, 'تعليق سيحذفه صاحبه لاحقًا.');
        $this->say($b, $s, 'ردّ يبقى ظاهرًا تحت تعليق محذوف.', $gone);

        // تعليق مخالف يخفيه المشرف (بسبب منظَّم).
        $bad = $this->say($d, $s, 'تعليق مخالف (مثال تجريبي للإخفاء الإشرافي).');

        foreach ([$b, $c, $d] as $liker) {
            $this->tryDo(fn () => app(DiscussionLikeService::class)->like($liker, (string) $root1->public_id));
        }

        $this->tryDo(fn () => app(DiscussionLikeService::class)->like($e, (string) $root2->public_id));

        if ($gone !== null && $gone->isLive()) {
            $this->tryDo(fn () => app(DiscussionCommentService::class)->delete($e, (string) $gone->public_id));
        }

        // بلاغ مفتوح واحد على تعليق ظاهر (سبب من القائمة المغلقة).
        $this->tryDo(fn () => app(DiscussionReportService::class)->report($e, (string) $root2->public_id, 'misleading'));

        $badFresh = $bad === null ? null : DiscussionComment::query()->whereKey($bad->id)->first();

        if ($badFresh !== null && $badFresh->isLive()) {
            $this->tryDo(fn () => app(DiscussionModerationService::class)->hide($badFresh, $admin, 'spam', 'مثال تجريبي.'));
        }
    }

    protected function seriesThread(DiscussionSubject $s): void
    {
        $root = $this->say($this->people['b'], $s, 'سلسلة منظمة ومتدرجة الصعوبة، شكرًا للمبدعة.');
        $this->say($this->people['c'], $s, 'أكملت أول ثلاث أحجيات وأنتظر البقية.', $root);
        $this->tryDo(fn () => app(DiscussionLikeService::class)->like($this->people['a'], (string) $root->public_id));
    }

    protected function collectionThread(DiscussionSubject $s, User $admin): void
    {
        $root = $this->say($this->people['a'], $s, 'مجموعة مرتبة بعناية، أضفتها إلى محفوظاتي.');
        $this->say($this->people['d'], $s, 'اختيارات موفقة للمساء.', $root);

        // نقاش مقفل: القراءة والإبلاغ يبقيان، والكتابة الجديدة ممنوعة.
        $thread = app(DiscussionThreadService::class)->findFor($s);

        if ($thread !== null && ! $thread->is_locked) {
            $this->tryDo(fn () => app(DiscussionModerationService::class)->lockThread($thread, $admin, 'مثال تجريبي لنقاش مقفل.'));
        }
    }

    /** مجموعة عامة تجريبية لمالك واحد (idempotent بالعنوان لكل مالك). */
    protected function publicCollection(CreatorPuzzle $work): ?DiscussionSubject
    {
        $owner = $this->user('demo-discussions-curator@discussions.test', 'أمينة النقاش (تجريبية)', true);
        $svc = app(CollectionService::class);
        $collection = UserCollection::query()->where('user_id', $owner->id)->where('title', self::COLLECTION_TITLE)->first()
            ?? $svc->create($owner, self::COLLECTION_TITLE, 'مجموعة تجريبية لعرض نقاش مجموعة عامة.');

        $this->tryDo(fn () => $svc->add($owner, (string) $collection->public_id, $work->fresh()));
        $this->tryDo(fn () => app(CollectionVisibilityService::class)->set($owner, (string) $collection->public_id, UserCollection::VISIBILITY_PUBLIC));

        return app(DiscussionSubjectRegistry::class)->resolve(DiscussionThread::TYPE_PUBLIC_COLLECTION, (string) $collection->public_id, null);
    }

    /** ينشئ التعليق عبر الخدمة، أو يعيد الموجود (نفس الكاتب والنص والنقاش). null إن تعذّر (نقاش مقفل مثلًا). */
    protected function say(User $user, DiscussionSubject $subject, string $body, ?DiscussionComment $parent = null): ?DiscussionComment
    {
        $existing = DiscussionComment::query()->where('user_id', $user->id)->where('body', $body)
            ->whereHas('thread', fn ($q) => $q->where('subject_type', $subject->type)->where('subject_id', $subject->id))->first();

        if ($existing !== null) {
            return $existing;
        }

        // نص التعليق المحذوف يُفرَّغ فلا يُطابق عند إعادة التشغيل: نبحث عن جذر محذوف لنفس الكاتب في النقاش قبل إعادة إنشائه.
        if ($body === 'تعليق سيحذفه صاحبه لاحقًا.') {
            $tomb = DiscussionComment::query()->where('user_id', $user->id)->whereNotNull('deleted_at')->whereNull('parent_id')
                ->whereHas('thread', fn ($q) => $q->where('subject_type', $subject->type)->where('subject_id', $subject->id))->first();

            if ($tomb !== null) {
                return $tomb;
            }
        }

        try {
            return app(DiscussionCommentService::class)->create($user, $subject, $body, $parent?->public_id);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
            // مكرَّر أو غير مؤهَّل أو نقاش مقفل: يُتجاهل (Demo فقط).
        }
    }

    protected function user(string $email, string $name, bool $publicProfile = false): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()] + ($publicProfile ? ['profile_visibility' => User::VISIBILITY_PUBLIC] : []))->save();

        return $user->fresh();
    }
}
