<?php

namespace Database\Seeders;

use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Creators\CreatorVisibilityService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E28 - بيانات Demo لمنظومة المبدعين وأحجيات المجتمع.   php artisan db:seed --class=DemoCreatorSeeder
 *
 * - **مستقلة عن DemoQaSeeder** (لا تُستدعى منه): أرقام DemoQa المثبّتة بالاختبارات لا تتغير. اختيارية، وترفض الإنتاج.
 * - **بالخدمات الرسمية فقط** (طلب انضمام، مسودات، إرسال، اعتماد، إخفاء، بلاغ، لعب): لا كتابة مباشرة بحالة أو بأحجية قانونية ولا بأي رصيد/XP.
 *   أحجيات المجتمع بلا مكافآت بالبناء، فلا يصل شيء من هنا للمحافظ ولا المهام ولا الصدارة.
 * - **Idempotent**: تُعاد دون تضاعف (مستخدمون بالبريد، أعمال بالعنوان لكل مبدع).
 * - حسابات تجريبية بكلمة المرور المعروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoCreatorSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->refuseInProduction('DemoCreatorSeeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $category = PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? PuzzleCategory::query()->create(['name' => 'متنوعة', 'slug' => 'misc', 'sort_order' => 99, 'is_active' => true]);

        $reviewer = $this->user('demo-reviewer@creators.test', 'مراجع المجتمع');
        $reviewer->syncRoles(['moderator']);

        $applications = app(CreatorApplicationService::class);
        $puzzles = app(CreatorPuzzleService::class);
        $review = app(CreatorReviewService::class);

        // مبدع معتمَد بثلاث أحجيات منشورة (نص، اختيار، ترتيب) + نسخة حيّة مخفية إشرافيًا.
        $nour = $this->user('demo-creator-nour@creators.test', 'نور المبدعة');
        $this->ensureApproved($nour, 'أحب صناعة ألغاز قصيرة تحفّز التفكير وتناسب الجميع.', $reviewer, $applications);

        $published = [];
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'ما الذي يكتب ولا يقرأ؟', ['kind' => 'text', 'difficulty' => 'easy', 'prompt' => 'لغز قصير: ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده في المكتب', 'answer' => 'القلم'], $category);
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'أي هذه الكواكب أكبر؟', ['kind' => 'multiple_choice', 'difficulty' => 'medium', 'prompt' => 'اختر الكوكب الأكبر في المجموعة الشمسية.', 'choices' => ['المشتري', 'زحل', 'الأرض', 'المريخ'], 'answer' => 'المشتري'], $category);
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'رتّب فصول السنة', ['kind' => 'sequence', 'difficulty' => 'easy', 'prompt' => 'رتّب فصول السنة بدءًا من الربيع.', 'items' => ['الربيع', 'الصيف', 'الخريف', 'الشتاء']], $category);
        $hidden = $this->publish($nour, $reviewer, $puzzles, $review, 'أحجية أخفاها الإشراف', ['kind' => 'text', 'difficulty' => 'hard', 'prompt' => 'لغز تجريبي أُخفي لأغراض العرض.', 'answer' => 'سر'], $category);

        if ($hidden && ! $hidden->isHidden()) {
            app(CreatorVisibilityService::class)->hide($hidden, $reviewer, 'مخفية لعرض حالة الإخفاء بالواجهة التجريبية.');
        }

        // مبدع ثانٍ: معتمَد بأعمال بحالات مختلفة (قيد المراجعة، تحتاج تعديلات، مرفوضة، مسودة) + منشورة واحدة.
        $sami = $this->user('demo-creator-sami@creators.test', 'سامي المبدع');
        $this->ensureApproved($sami, 'مهتم بألغاز الذاكرة والمنطق، وأشارك ما أجمعه من ألعاب العائلة.', $reviewer, $applications);
        $published[] = $this->publish($sami, $reviewer, $puzzles, $review, 'بطاقات الفواكه', ['kind' => 'memory', 'difficulty' => 'easy', 'prompt' => 'اقلب البطاقات وطابق الأزواج المتشابهة.', 'faces' => ['تفاح', 'موز', 'عنب', 'خوخ']], $category);

        $this->draft($sami, $puzzles, 'مسودة قيد الكتابة', ['kind' => 'text', 'difficulty' => 'easy', 'prompt' => 'لغز لم يكتمل بعد، ما هو؟', 'answer' => 'سؤال'], $category);
        $this->submitted($sami, $puzzles, 'بانتظار المراجعة', ['kind' => 'text', 'difficulty' => 'medium', 'prompt' => 'ما الذي له أسنان ولا يعض؟', 'answer' => 'المشط'], $category);
        $changes = $this->submitted($sami, $puzzles, 'تحتاج تعديلات', ['kind' => 'text', 'difficulty' => 'medium', 'prompt' => 'لغز يحتاج توضيح السؤال أكثر.', 'answer' => 'غموض'], $category);
        $rejected = $this->submitted($sami, $puzzles, 'أحجية مرفوضة', ['kind' => 'text', 'difficulty' => 'hard', 'prompt' => 'لغز لا يناسب المنصة ويتجاوز الإرشادات.', 'answer' => 'ممنوع'], $category);

        if ($changes && $changes->status === CreatorPuzzleRevision::STATUS_PENDING_REVIEW) {
            $review->requestChanges($changes, $reviewer, 'وضّح صياغة السؤال وحدّد المقصود بالإجابة.');
        }

        if ($rejected && $rejected->status === CreatorPuzzleRevision::STATUS_PENDING_REVIEW) {
            $review->reject($rejected, $reviewer, 'لا يتوافق مع إرشادات النشر.');
        }

        // مبدع بطلب انضمام معلَّق.
        $layla = $this->user('demo-applicant-layla@creators.test', 'ليلى المتقدّمة');

        if (! CreatorProfile::query()->where('user_id', $layla->id)->exists()) {
            $applications->apply($layla, 'أرغب في الانضمام لنشر ألغاز لغوية وكلمات متقاطعة مبسّطة للمجتمع.');
        }

        // لاعبون ولعب حقيقي (بالخدمة، في جدول المجتمع المنفصل) + بلاغ مفتوح، فتظهر الأرقام والرائج.
        $players = [$this->user('demo-player-a@creators.test', 'لاعب أ'), $this->user('demo-player-b@creators.test', 'لاعب ب'), $this->user('demo-player-c@creators.test', 'لاعب ج')];
        $this->play($players, array_filter($published));

        $reportable = collect($published)->filter()->first(fn (CreatorPuzzle $w) => $w->fresh()->is_visible);

        if ($reportable !== null && ! CommunityPuzzleReport::query()->where('creator_puzzle_id', $reportable->id)->where('reporter_id', $players[2]->id)->exists()) {
            app(CommunityReportService::class)->report($players[2], $reportable->fresh(), 'broken', 'تجربة بلاغ تجريبي للعرض فقط.');
        }

        $this->command?->info('DemoCreatorSeeder: مبدعون ('.CreatorProfile::query()->count().')، أعمال ('.CreatorPuzzle::query()->count().'). كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
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

    protected function ensureApproved(User $user, string $bio, User $reviewer, CreatorApplicationService $applications): void
    {
        $profile = CreatorProfile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            $profile = $applications->apply($user, $bio);
        }

        if ($profile->status === CreatorProfile::STATUS_PENDING) {
            $applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
        }
    }

    protected function existingWork(User $user, string $title): ?CreatorPuzzle
    {
        return CreatorPuzzle::query()
            ->whereHas('creator', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('revisions', fn ($q) => $q->where('title', $title))
            ->first();
    }

    protected function draft(User $user, CreatorPuzzleService $puzzles, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzleRevision
    {
        if ($this->existingWork($user, $title) !== null) {
            return null;
        }

        return $puzzles->createDraft($user, $data + ['title' => $title, 'puzzle_category_id' => $category->id]);
    }

    protected function submitted(User $user, CreatorPuzzleService $puzzles, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzleRevision
    {
        $revision = $this->draft($user, $puzzles, $title, $data, $category);

        return $revision === null ? null : $puzzles->submit($user, $revision->work->public_id);
    }

    /** ينشر العمل (مسودة ← إرسال ← اعتماد) أو يعيد الموجود. */
    protected function publish(User $user, User $reviewer, CreatorPuzzleService $puzzles, CreatorReviewService $review, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzle
    {
        if (($existing = $this->existingWork($user, $title)) !== null) {
            return $existing;
        }

        $submitted = $this->submitted($user, $puzzles, $title, $data, $category);
        $review->approve($submitted, $reviewer, 'معتمدة للعرض التجريبي.');

        return $submitted->work()->first();
    }

    /** @param list<User> $players @param iterable<CreatorPuzzle> $works */
    protected function play(array $players, iterable $works): void
    {
        $play = app(CommunityPlayService::class);

        foreach ($works as $work) {
            $work = $work->fresh();

            if (! $work->is_visible || $work->plays_count > 0) {
                continue;
            }

            foreach ($players as $i => $player) {
                try {
                    $play->attempt($player, $work, $i === 0 ? '__غير صحيحة__' : '', []);
                } catch (\Throwable) {
                    // عمل نوعه لا يقبل إجابة نصية خام (ترتيب/ذاكرة) أو انتهت محاولاته: لا يهم للعرض.
                }
            }
        }
    }
}
