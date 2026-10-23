<?php

namespace Database\Seeders;

use App\Models\CreatorPuzzle;
use App\Models\FraudFlag;
use App\Models\ModerationAction;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\Creators\CommunityReportService;
use App\Services\TrustSafety\AppealService;
use App\Services\TrustSafety\CaseService;
use App\Services\TrustSafety\EnforcementService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E38 - بيانات Demo لمركز الثقة والسلامة.   php artisan db:seed --class=DemoE38Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق safety.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من DemoQaSeeder.
 * - كل شيء يُبنى **بالخدمات الرسمية فقط** (بلاغات E28، فتح الحالات، التنفيذ عبر EnforcementService، الاعتراض عبر AppealService) فلا إدخال مباشر في جداول الحالات.
 * - مشهد العرض: حالة بإجراء إخفاء وعليه اعتراض معلَّق؛ حالة مفتوحة غير معيَّنة؛ حالة إشارة نظام (احتيال) محسومة بلا مخالفة؛ وبلاغ مفتوح بلا حالة.
 * - لا جواهر ولا XP ولا مهام ولا أي أثر اقتصادي، ولا تجميد حسابات (التجميد إجراء بشري يُنفَّذ يدويًا).
 * - Idempotent: علامة العرض في ملخص الحالة؛ إعادة التشغيل لا تضاعف حالة ولا بلاغًا ولا إجراءً.
 */
class DemoE38Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-safety-admin@safety.test';

    public const MODERATOR_EMAIL = 'demo-moderator@safety.test';

    public const MARK = 'عرض تجريبي E38';

    public function run(): void
    {
        $this->refuseInProduction('DemoE38Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الثقة والسلامة التجريبي', 'administrator');
        $moderator = $this->user(self::MODERATOR_EMAIL, 'مشرف تجريبي', 'moderator');

        if (ModerationCase::query()->where('summary', 'like', self::MARK.'%')->exists()) {
            $this->command?->info('DemoE38Seeder: بيانات العرض موجودة بالفعل (لا تكرار).');

            return;
        }

        if (! CreatorPuzzle::query()->visible()->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        $works = CreatorPuzzle::query()->visible()->with('creator.user')->orderBy('id')->limit(3)->get();

        if ($works->count() < 2) {
            $this->command?->warn('DemoE38Seeder: لا أعمال مجتمع منشورة كافية (2 على الأقل).');

            return;
        }

        $reporters = [];

        for ($i = 1; $i <= 3; $i++) {
            $reporters[] = $this->user("demo-reporter-{$i}@safety.test", "مبلِّغ تجريبي {$i}");
        }

        $cases = app(CaseService::class);
        $reports = app(CommunityReportService::class);

        // 1) حالة بإجراء إخفاء وعليه اعتراض معلَّق (بلاغان مستقلان على العمل نفسه).
        $this->tryDo(function () use ($works, $reporters, $reports, $cases, $admin) {
            $first = $reports->report($reporters[0], $works[0], 'inappropriate', 'بلاغ تجريبي: محتوى يبدو غير لائق.');
            $second = $reports->report($reporters[1], $works[0], 'misleading', 'بلاغ تجريبي: العنوان مضلِّل.');
            $case = $cases->open($admin, ['community_puzzle_report:'.$first->id, 'community_puzzle_report:'.$second->id], self::MARK.': عمل مبلَّغ عنه من أكثر من مبلِّغ.');
            $cases->claim($case, $admin);
            $cases->addNote($case->fresh(), $admin, 'ملاحظة داخلية تجريبية: المراجعة اليدوية تؤكد المخالفة الظاهرة.');
            $action = app(EnforcementService::class)->apply($case->fresh(), $admin, 'hide_content', [
                'reason' => 'إخفاء تجريبي بعد مراجعة المحتوى المبلَّغ عنه.', 'public_note' => 'أُخفي العمل لمخالفته إرشادات المحتوى.', 'confirm' => true,
            ]);
            $owner = $works[0]->creator?->user;

            if ($owner instanceof User && $action instanceof ModerationAction) {
                app(AppealService::class)->file($owner, $action, 'أعتقد أن إخفاء العمل كان خطأً وأرجو إعادة النظر فيه؛ العمل يلتزم بالإرشادات.');
            }
        });

        // 2) حالة مفتوحة غير معيَّنة (تظهر في طابور «غير معيَّنة»).
        $this->tryDo(function () use ($works, $reporters, $reports, $cases, $moderator) {
            $report = $reports->report($reporters[2], $works[1], 'spam', 'بلاغ تجريبي: تكرار ترويجي.');
            $cases->open($moderator, ['community_puzzle_report:'.$report->id], self::MARK.': حالة مفتوحة غير معيَّنة.');
        });

        // 3) إشارة نظام (احتيال) محسومة بلا مخالفة - الإشارة ليست عقوبة ولا يُجمَّد بها أحد آليًا.
        $this->tryDo(function () use ($reporters, $cases, $admin) {
            $flag = FraudFlag::query()->firstOrCreate(
                ['user_id' => $reporters[0]->id, 'reason' => 'multiple_accounts'],
                ['severity' => 'medium', 'details' => 'إشارة نظام تجريبية: اشتراك عنوان IP.']
            );
            $case = $cases->open($admin, ['fraud_flag:'.$flag->id], self::MARK.': إشارة نظام للمراجعة.');
            $cases->claim($case, $admin);
            $cases->resolve($case->fresh(), $admin, 'no_violation', 'تبيّن أن الاشتراك عائلي مشروع.');
        });

        // 4) بلاغ مفتوح بلا حالة (يظهر في «بلا حالة إشراف»).
        if ($works->count() >= 3) {
            $this->tryDo(fn () => $reports->report($reporters[1], $works[2], 'other', 'بلاغ تجريبي بلا حالة.'));
        }

        $this->command?->info(sprintf('DemoE38Seeder: حالات=%d، إجراءات=%d. دخول: %s / %s (كلمة المرور: %s)', ModerationCase::query()->count(), ModerationAction::query()->count(), self::ADMIN_EMAIL, self::MODERATOR_EMAIL, self::PASSWORD));
    }

    protected function user(string $email, string $name, ?string $role = null): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    protected function tryDo(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE38Seeder: تخطّي خطوة - '.$e->getMessage());
        }
    }
}
