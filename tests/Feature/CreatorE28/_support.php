<?php

use App\Models\CampaignGateQualification;
use App\Models\CompetitiveEventParticipant;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\CurrencyTransaction;
use App\Models\FraudFlag;
use App\Models\GameSession;
use App\Models\GemTransaction;
use App\Models\PlayerProgression;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;

/*
| E28 - مساعدات مشتركة لاختبارات منظومة المبدعين. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| كل شيء محلي على SQLite وكاش array؛ لا شبكة. كل شيء يمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات) كي تختبر الاختبارات المسار الحقيقي.
*/

require_once __DIR__.'/../DiscoveryE27/_support.php';

if (! function_exists('e28_category')) {
    function e28_category(array $attrs = []): PuzzleCategory
    {
        return PuzzleCategory::factory()->create($attrs + ['is_active' => true]);
    }

    /** مستخدم بصلاحية المراجعة (مشرف) أو دور آخر. يتطلب بذر RolesAndPermissionsSeeder. */
    function e28_reviewer(string $role = 'moderator'): User
    {
        $user = e16User();
        $user->assignRole($role);

        return $user;
    }

    /** مستخدم موثَّق بملف مبدع معتمَد (طلب ← اعتماد بالخدمة الرسمية). */
    function e28_creator(?User $user = null, ?User $reviewer = null): User
    {
        $user ??= e16User();
        $applications = app(CreatorApplicationService::class);
        $profile = $applications->apply($user, 'مبدع ألغاز يحب صناعة التحديات الذهنية للمجتمع.');
        $applications->approve($profile, $reviewer ?? e28_reviewer());

        return $user->refresh()->unsetRelation('creatorProfile');
    }

    /** حمولة مدخلات صالحة لنوع معيّن (يجوز تجاوز أي حقل). */
    function e28_data(string $kind = 'text', array $over = []): array
    {
        $category = PuzzleCategory::query()->where('is_active', true)->first() ?? e28_category();
        $base = ['kind' => $kind, 'title' => 'أحجية '.fake()->unique()->numerify('####'), 'puzzle_category_id' => $category->id, 'difficulty' => 'easy', 'prompt' => 'ما الشيء الذي يكتب ولا يقرأ أبدًا؟', 'hint' => 'تجده على المكتب'];

        $byKind = match ($kind) {
            'multiple_choice' => ['choices' => ['القلم', 'الكتاب', 'المسطرة'], 'answer' => 'القلم'],
            'sequence' => ['items' => ['الأول', 'الثاني', 'الثالث', 'الرابع']],
            'memory' => ['faces' => ['تفاح', 'موز', 'عنب', 'خوخ']],
            default => ['answer' => 'القلم'],
        };

        return $over + $byKind + $base;
    }

    function e28_draft(User $creator, string $kind = 'text', array $over = []): CreatorPuzzleRevision
    {
        return app(CreatorPuzzleService::class)->createDraft($creator, e28_data($kind, $over));
    }

    function e28_pending(User $creator, string $kind = 'text', array $over = []): CreatorPuzzleRevision
    {
        $revision = e28_draft($creator, $kind, $over);

        return app(CreatorPuzzleService::class)->submit($creator, $revision->work()->value('public_id'));
    }

    /** عمل منشور فعلًا (مسودة ← إرسال ← اعتماد). */
    function e28_published(User $creator, string $kind = 'text', array $over = [], ?User $reviewer = null): CreatorPuzzle
    {
        $revision = e28_pending($creator, $kind, $over);
        app(CreatorReviewService::class)->approve($revision, $reviewer ?? e28_reviewer());

        return $revision->work()->first();
    }

    /** لقطة حالة الاقتصاد والتقدّم كلها لمستخدم: أي تغيّر بعد لعب مجتمع = اختراق للعزل. */
    function e28_world(User $user): array
    {
        $models = [XpTransaction::class, GemTransaction::class, CurrencyTransaction::class, PuzzleAttempt::class, PlayerProgression::class, PlayerStreak::class,
            UserQuestProgress::class, UserAchievementProgress::class, CampaignGateQualification::class, UserCampaignProgress::class, CompetitiveEventParticipant::class, GameSession::class, FraudFlag::class];

        $counts = [];

        foreach ($models as $model) {
            $counts[class_basename($model)] = $model::query()->count();
        }

        return [
            'wallet' => e26_wallet_state($user),
            'counts' => $counts,
            'xp' => (int) PlayerProgression::query()->where('user_id', $user->id)->sum('total_xp'),
            'streak' => (int) PlayerStreak::query()->where('user_id', $user->id)->sum('current_streak'),
            'quest' => (int) UserQuestProgress::query()->where('user_id', $user->id)->sum('current_value'),
        ];
    }

    /** صفحات المبدع/المجتمع/المراجعة كلها: لا تسرّب جوابًا ولا حقول سلطة. */
    function e28_assert_no_secrets(string $html, array $secrets = []): void
    {
        foreach (array_merge($secrets, ['answer_hash', 'answer_raw', 'solution_data', 'game_config']) as $secret) {
            expect(str_contains($html, $secret))->toBeFalse('leaked: '.$secret);
        }
    }

    /** اسم مستخدم/بريد غير موجودين بصفحات عامة. */
    function e28_assert_private_absent(string $html, User $creator): void
    {
        expect(str_contains($html, $creator->email))->toBeFalse('email leaked')
            ->and(str_contains($html, 'password'))->toBeFalse();
    }
}
