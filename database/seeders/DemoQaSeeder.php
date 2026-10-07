<?php

namespace Database\Seeders;

use App\Models\CompetitiveEvent;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Demo\DemoCampaignSeeder;
use Database\Seeders\Demo\DemoChatSeeder;
use Database\Seeders\Demo\DemoCompetitionSeeder;
use Database\Seeders\Demo\DemoNotificationSeeder;
use Database\Seeders\Demo\DemoProgressionSeeder;
use Database\Seeders\Demo\DemoPuzzleCatalog;
use Database\Seeders\Demo\DemoSocialSeeder;
use Database\Seeders\Demo\DemoTeamCompetitionSeeder;
use Database\Seeders\Demo\DemoTeamSeeder;
use Illuminate\Database\Seeder;

/**
 * بيانات Demo / QA مترابطة لاختبار كل الأنظمة حتى E20 يدويًا من الواجهة.   php artisan db:seed --class=DemoQaSeeder
 *
 * - **اختيارية (Opt-in)**: غير مضافة إلى DatabaseSeeder ولا تعمل تلقائيًا بالنشر.
 * - **ترفض العمل خارج local/testing/staging** (production وأي بيئة غير معروفة) بلا تجاوز.
 * - **لا مسح**: لا migrate:fresh ولا truncate ولا حذف. تضيف فقط، وتُعيد استعمال مستخدمي UserSeeder (كلمة المرور: password).
 * - **Idempotent**: تشغيلها مرات لا يضاعف الصداقات ولا الفرق ولا الأحداث ولا النتائج ولا الجوائز ولا الإشعارات.
 * - **حتمية**: لا عشوائية بالسيناريوهات (نفس المستخدمين والفرق والنتائج والمراكز كل مرة)، وتواريخ نسبية للآن.
 * - **بالخدمات الرسمية**: صداقات، فرق، أحداث، جوائز، بطولات، متجر، تقدّم: بزمن محاكى (يُعاد دائمًا). لا تعديل مباشر لرصيد/XP/تقدّم مشتق.
 */
class DemoQaSeeder extends Seeder
{
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    /** كلمة مرور حسابات UserSeeder التجريبية (.test). */
    public const PASSWORD = UserSeeder::TEST_PASSWORD;

    public function run(): void
    {
        self::assertSafeEnvironment();

        $previousNow = Carbon::getTestNow();

        try {
            DemoPuzzleCatalog::reset();

            // متطلبات موجودة أصلًا (لا نعيد إنشاء ما تزرعه) ثم التعريفات التي لا يستدعيها DatabaseSeeder. بذرة الأدوار **بعد** المستخدمين عمدًا:
            // هي تمنح دور "مستخدم" لمن وُجد وقت تشغيلها، فتأخيرها يكمّل أدوار الحسابات التجريبية من التشغيل الأول (لا حاجة لتشغيل ثانٍ).
            $this->call([
                AdminUserSeeder::class, PuzzleCategorySeeder::class, PuzzleSeeder::class, UserSeeder::class, RolesAndPermissionsSeeder::class,
                ProgressionSeeder::class, EngagementSeeder::class, CompetitiveAchievementsSeeder::class, EconomySeeder::class,
                Seasons\AseelSeasonSeeder::class, FraudFlagSeeder::class,
            ]);

            // سيناريوهات الديمو (الترتيب مهم: الفرق قبل المنافسات لتُؤخذ لقطات الفريق وقت التسجيل صحيحة).
            $this->call([
                DemoSocialSeeder::class, DemoTeamSeeder::class, DemoProgressionSeeder::class, DemoCampaignSeeder::class,
                DemoCompetitionSeeder::class, DemoTeamCompetitionSeeder::class, DemoNotificationSeeder::class, DemoChatSeeder::class,
            ]);
        } finally {
            Carbon::setTestNow($previousNow);
            DemoPuzzleCatalog::reset();
        }

        $this->summary();
    }

    /** يرفض أي بيئة غير مسموحة (production وغير المعروفة) بلا مفتاح تجاوز. */
    public static function assertSafeEnvironment(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new \RuntimeException('DemoQaSeeder مخصّصة لبيئات local/testing/staging فقط. البيئة الحالية: "'.app()->environment().'" - رُفض التشغيل ولم يُنفَّذ أي شيء.');
        }
    }

    protected function summary(): void
    {
        if ($this->command === null) {
            return;
        }

        $p = self::PASSWORD;
        $teams = Team::query()->orderBy('id')->pluck('name', 'id');
        $this->command->newLine();
        $this->command->line(str_repeat('=', 66));
        $this->command->info('QA DEMO ACCOUNTS  (كلمة المرور للجميع: '.$p.')');
        $this->command->line(str_repeat('=', 66));

        foreach ([
            ['MAIN PLAYER', 'yousef', 'الحساب الرئيسي: مالك «فرسان الشام»، 5 أصدقاء، طلب وارد (ريم) وصادر (خالد)، حظر لمى، سلسلة 7 أيام، مهام اليوم، حملة جارية، منافسات (5/7/2/1)، 4 تحدّيات أصدقاء، تحدّي فريق جارٍ وآخر وارد، متجر (إطار مجهَّز)، إشعارات، **دردشة: مباشرتان (سارة وليان) + فريقه + العامة**'],
            ['SOCIAL / RIVAL OWNER', 'sara', 'صديقة يوسف مقبولة، مالكة «صقور المعرفة» (مفتوح 5/6)، أكملت الحملة، بطلة «كأس الربيع»، تحدّتا يوسف'],
            ['HIGH LEVEL', 'reem', 'مستوى 12، مالكة «عباقرة الشرق» (بدعوة فقط)، طلب صداقة صادر إلى يوسف، فازت بجولتين'],
            ['TEAM ADMIN', 'omar', 'مشرف «فرسان الشام» (بلا علاقة صداقة بيوسف)، ينفّذ إدارة الأعضاء والطلبات'],
            ['TEAM MEMBER', 'layan', 'عضو «فرسان الشام» (صديقة ليوسف)، انضمت بطلب مقبول'],
            ['CHAT MUTED', 'wisam', 'مكتوم 7 أيام بالدردشة العامة (يقرأ ولا يرسل)؛ على رسالة خالد الإعلانية بلاغ معلّق بمركز البلاغات'],
            ['PENDING JOIN REQUEST', 'khaled', 'لديه طلب انضمام معلّق لفريق يوسف، ووارد إليه طلب صداقة من يوسف'],
            ['NEW PLAYER', 'kenan', 'لاعب جديد بلا تقدّم، لديه دعوة معلّقة من «فرسان الشام»'],
            ['PRIVATE TEAM OWNER', 'noureddine', 'مالك «نجوم الأحجيات» (فريق خاص)، صديق ليوسف'],
            ['BLOCKED BY MAIN', 'lama', 'محظورة من يوسف (لا تظهر له ولا تتفاعل معه)'],
            ['HAS BLOCKED MAIN', 'firas', 'حظر يوسف (يختبر عدم التفاعل من جهته)'],
            ['FRIEND REQUESTS OFF', 'dana', 'لا تستقبل طلبات صداقة (friend_requests_enabled=false)، عضو «عباقرة الشرق»'],
            ['FROZEN', 'tarek', 'حساب مجمَّد (رسالة التجميد)'],
            ['FROZEN', 'huda', 'حساب مجمَّد (بطلب المستخدم)'],
            ['UNVERIFIED', 'muath', 'بريد غير موثَّق (قيود الميزات)'],
        ] as [$role, $key, $scenario]) {
            $this->command->line(sprintf('%-22s %s@ahjiyat.test', $role, $key));
            $this->command->line('   '.$scenario);
        }

        $this->command->line(sprintf('%-22s %s', 'ADMIN PANEL', 'admin@ahjiyat.app  (كلمة المرور من AdminUserSeeder: change-me-now ما لم تغيّرها)  → /admin'));
        $this->command->line(str_repeat('-', 66));
        $this->command->info(sprintf(
            'فرق: %d | أحداث: %d | تحدّيات فرق: %d | بطولات: %d | مستخدمون: %d',
            $teams->count(), CompetitiveEvent::query()->count(), TeamChallenge::query()->count(), TeamChampionship::query()->count(), User::query()->count(),
        ));
        $this->command->line('الفرق: '.$teams->implode('، '));
        $this->command->line('إعادة التشغيل آمنة (Idempotent). التوثيق: docs/demo-qa-data.md');
        $this->command->line(str_repeat('=', 66));
    }
}
