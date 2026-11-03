<?php

namespace App\Services\AiAssistant\Local\Cards;

use App\Services\AiAssistant\Knowledge\CuratedKnowledge;
use App\Services\AiAssistant\Local\IntentCatalog;
use Illuminate\Routing\Route;

/**
 * TI-3 - سجل ميزات المستخدم: يربط كل **مسار GET موجّه للمستخدم** بميزة، وكل ميزة بمقال معرفة (curated أو بطاقة) يشرحها.
 *
 * - هذا السجل هو مصدر «الميزة الجديدة بلا بطاقة = فشل» (ai:local:coverage --strict ثم خطوة CI): أي مسار مستخدم جديد لا يطابقه نمط هنا
 *   يُبلَّغ كـ«غير مصنَّف»، وأي ميزة بلا مقال موجود أو بلا نية في كتالوج المحرك تُبلَّغ كفجوة.
 * - مسارات الإدارة (admin/filament) والويبهوك والواجهات الداخلية والخدمية (livewire/sanctum/storage/health/api/…) **مستثناة صراحةً** بأنماط
 *   معلنة أدناه؛ لا يدخل منها شيء إلى المعرفة العامة، ولا يُنشَر شيء تلقائيًا من الكود.
 * - مطابقة بأنماط على اسم المسار (`*` = أي لاحقة)، وللمسارات غير المسمّاة بمقطع URI الأول.
 */
final class FeatureRegistry
{
    /** أنماط مستثناة (أسماء مسارات) ⇒ سبب الاستثناء. */
    public const EXCLUDED_NAMES = [
        'filament.*' => 'لوحة الإدارة',
        'payments.staff.*' => 'أدوات طاقم الدفع (حساسة)',
        'payments.webhook*' => 'ويبهوك مزوّد الدفع (آلي)',
        'ads.click' => 'تتبع نقرات الإعلانات (تحويل داخلي)',
        'livewire.*' => 'بنية Livewire الداخلية',
        'sanctum.*' => 'بنية المصادقة للواجهات',
        'storage.*' => 'تقديم الملفات',
        'health.*' => 'فحوص الصحة التشغيلية',
        'commerce.sandbox.*' => 'بوابة دفع تجريبية (بيئة اختبار)',
        'community.review.preview' => 'معاينة المراجع (طاقم)',
        'tatawwur.*' => 'نقاط نهاية المساعد نفسه',
        'verification.verify' => 'رابط توثيق مُوقَّع',
        'referrals.landing' => 'تحويل رابط الدعوة (يُشرح ضمن ميزة الدعوات)',
    ];

    /** مقاطع URI الأولى لمسارات غير مسمّاة مستثناة. */
    public const EXCLUDED_URI_PREFIXES = ['api', 'livewire', 'broadcasting', 'sanctum', 'up', 'admin', 'storage'];

    /**
     * ميزات المستخدم: مفتاح ⇒ [أنماط أسماء المسارات، slug المقال الذي يشرحها، وصف قصير].
     *
     * @var array<string, array{routes: list<string>, article: string, label: string}>
     */
    public const FEATURES = [
        'home' => ['routes' => ['home'], 'article' => 'ma-hiya-ahjiyat', 'label' => 'الصفحة الرئيسية'],
        'auth' => ['routes' => ['login', 'register', 'password.*', 'verification.*'], 'article' => 'kayfa-abda', 'label' => 'التسجيل والدخول والتوثيق'],
        'puzzles' => ['routes' => ['puzzles.*'], 'article' => 'al-ahjiyat-wa-anwaauha', 'label' => 'الأحجيات'],
        'wallet' => ['routes' => ['wallet.*'], 'article' => 'al-jawahir', 'label' => 'المحفظة والجواهر'],
        'redemption' => ['routes' => ['redemption.*'], 'article' => 'al-istibdal', 'label' => 'استبدال الجواهر'],
        'progress' => ['routes' => ['progress.*'], 'article' => 'al-taqaddum-wal-mustawayat', 'label' => 'التقدم والمستويات'],
        'quests' => ['routes' => ['quests.*', 'missions.*', 'events.*'], 'article' => 'al-mahamm-wal-fa3aliyat', 'label' => 'المهام والفعاليات'],
        'friends' => ['routes' => ['friends.*'], 'article' => 'al-asdiqa-wal-hazr', 'label' => 'الأصدقاء'],
        'teams' => ['routes' => ['teams.index', 'teams.show', 'teams.create', 'teams.invitations', 'teams.mine', 'teams.chat', 'teams.manage', 'teams.leaderboard'], 'article' => 'al-furuq', 'label' => 'الفرق'],
        'team_competitions' => ['routes' => ['teams.challenges.*', 'team-championships.*'], 'article' => 'tahaddiyat-wa-butulat-al-furuq', 'label' => 'تحديات الفرق وبطولاتها'],
        'competitions' => ['routes' => ['competitions.*'], 'article' => 'al-munafasat', 'label' => 'المنافسات'],
        'pass' => ['routes' => ['pass.*'], 'article' => 'masar-al-ahjiyat', 'label' => 'مسار الأحجيات'],
        'seasons' => ['routes' => ['seasons.*'], 'article' => 'al-mawasim', 'label' => 'المواسم'],
        'writers' => ['routes' => ['seasonal.writers.*'], 'article' => 'dawri-al-kuttab', 'label' => 'دوري كتّاب الموسم'],
        'supporters' => ['routes' => ['seasonal.supporters.*', 'supporters.*'], 'article' => 'taqdir-al-daaimin', 'label' => 'تقدير الداعمين'],
        'support' => ['routes' => ['support.*'], 'article' => 'daam-al-minassa', 'label' => 'دعم المنصة'],
        'creators' => ['routes' => ['community.puzzles.*', 'creators.*', 'creator.dashboard', 'creator.apply', 'creator.puzzles.*', 'creator.premium.*'], 'article' => 'al-mubdiun-wa-ahjiyat-al-mujtamaa', 'label' => 'المبدعون وأحجيات المجتمع'],
        'creator_series' => ['routes' => ['series.*', 'creator.series.*'], 'article' => 'salasil-al-mubdiin', 'label' => 'سلاسل المبدعين'],
        'creator_earnings' => ['routes' => ['creator.earnings*'], 'article' => 'arbah-al-mubdiin', 'label' => 'أرباح المبدعين'],
        'reputation' => ['routes' => ['community.rankings'], 'article' => 'al-sumaa', 'label' => 'ترتيب السمعة'],
        'store' => ['routes' => ['store.*', 'inventory.*'], 'article' => 'al-matjar-wal-maqtaniyat', 'label' => 'المتجر والمقتنيات'],
        'purchases' => ['routes' => ['commerce.purchases.*', 'commerce.receipts.*', 'commerce.orders.*', 'commerce.checkout.*'], 'article' => 'mushtarayati', 'label' => 'المشتريات والإيصالات'],
        'search' => ['routes' => ['search.*', 'discover.*'], 'article' => 'al-bahth-wal-iktishaf', 'label' => 'البحث والاكتشاف'],
        'notifications' => ['routes' => ['notifications.*'], 'article' => 'al-ishaaraat', 'label' => 'الإشعارات'],
        'chat' => ['routes' => ['messages.*', 'chat.*', 'community.chat'], 'article' => 'al-dardasha', 'label' => 'الدردشة والرسائل'],
        'comments' => ['routes' => ['discussions.*'], 'article' => 'al-taaliqat', 'label' => 'التعليقات والنقاش'],
        'collections' => ['routes' => ['collections.*'], 'article' => 'al-majmuaat', 'label' => 'المجموعات'],
        'favorites' => ['routes' => ['favorites.*', 'following.*', 'players.*'], 'article' => 'al-mufaddala-wal-mutabaa', 'label' => 'المفضلة والمتابعة وملفات اللاعبين'],
        'referrals' => ['routes' => ['referrals.index'], 'article' => 'barnamaj-al-daawa', 'label' => 'برنامج الدعوة'],
        'safety' => ['routes' => ['account.safety.*'], 'article' => 'aman-al-hisab', 'label' => 'أمان الحساب والاعتراض'],
        'campaigns' => ['routes' => ['campaigns.*', 'challenges.*'], 'article' => 'al-tahaddiyat-wal-hamalat', 'label' => 'التحديات والحملات'],
        'leaderboard' => ['routes' => ['leaderboard.*'], 'article' => 'lawhat-al-sadara', 'label' => 'لوحة الصدارة'],
        'profile' => ['routes' => ['profile.edit'], 'article' => 'al-malaf-al-shakhsi', 'label' => 'الملف الشخصي'],
        'customize' => ['routes' => ['profile.customize'], 'article' => 'khanat-altakhsees', 'label' => 'خانات تخصيص الملف'],
        'help' => ['routes' => ['help.*', 'pages.*'], 'article' => 'al-shurut-wal-musaada', 'label' => 'الشروط والخصوصية والمساعدة'],
    ];

    /**
     * أدوات القراءة الحية/الشخصية (E45) المرتبطة بكل ميزة. كل أداة من الأدوات الـ23 يجب أن تظهر هنا مرة على الأقل (يفرض ذلك TIFinalCoverageTest)،
     * وكلها موصولة بالمحرك: السبع عشرة في LiveResolver، والست في EntityTools (لا تقاطع بينهما؛ المجموع 23).
     *
     * @var array<string, list<string>>
     */
    public const LIVE_TOOLS = [
        'progress' => ['get_my_progress_summary'],
        'wallet' => ['get_my_progress_summary'],
        'campaigns' => ['get_campaign_information', 'get_my_current_campaign_progress'],
        'competitions' => ['get_competition_information', 'get_my_competition_status'],
        'teams' => ['get_team_public_information'],
        'pass' => ['get_ahjiyat_pass_information', 'get_my_pass_status'],
        'seasons' => ['get_current_season', 'get_season_information', 'get_my_current_season_status'],
        'writers' => ['get_writer_league_information', 'get_my_writer_star_budget'],
        'supporters' => ['get_supporter_leaderboard_information', 'get_my_supporter_recognition'],
        'creators' => ['get_creator_public_information', 'get_my_creator_status'],
        'creator_series' => ['get_creator_series_public_information', 'get_my_premium_series_access'],
        'store' => ['get_store_item_information'],
        'search' => ['search_platform'],
        'profile' => ['get_my_profile_summary'],
        'help' => ['get_platform_guide_article'],
    ];

    /**
     * حالات الرفض/الامتناع الموثّقة لكل ميزة (ما لا يفعله المساعد حولها). نصوص الرد نفسها ثابتة في CannedReplies/EnhancedReplies.
     *
     * @var array<string, list<string>>
     */
    public const REFUSALS = [
        'puzzles' => ['حلول الأحجيات وإجاباتها وما يقود إليها', 'تلميحات بديلة عن ميزة التلميح الرسمية'],
        'wallet' => ['أرصدة أو حركات مستخدم آخر', 'تعديل الرصيد أو منح الجواهر'],
        'redemption' => ['تنفيذ طلب استبدال أو قبوله نيابة عن المستخدم', 'مبالغ أو سياسات غير منشورة'],
        'progress' => ['شراء الخبرة أو المستوى أو تحويلها لجواهر'],
        'friends' => ['بيانات أصدقاء غير ظاهرة للعموم'],
        'chat' => ['محادثات خاصة لمستخدمين آخرين'],
        'safety' => ['ملفات الإشراف أو تفاصيل الاحتيال الداخلية'],
        'purchases' => ['إثباتات الدفع أو بيانات مالية'],
        'creator_earnings' => ['أرباح أو كشوف مبدع آخر'],
        'creator_series' => ['محتوى سلسلة مميزة غير مملوكة أو حلولها'],
        'store' => ['الشراء أو التجهيز نيابة عن المستخدم'],
        'help' => ['إعدادات الخوادم والأسرار والمسارات الإدارية'],
    ];

    /** تعريف ميزات المنصة بمعايير بطاقة الميزة الكاملة (للتقرير والبوابة): ينتج صفًا لكل ميزة. @return list<array<string, mixed>> */
    public static function matrix(): array
    {
        $articles = [];

        foreach (array_merge(CuratedKnowledge::articles(), CardLibrary::articles()) as $article) {
            $articles[$article['slug']] = $article;
        }

        $intents = [];

        foreach (IntentCatalog::knowledge() as $name => $def) {
            $intents[$def['a'] ?? ''][] = $name;
        }

        $rows = [];

        foreach (self::FEATURES as $key => $feature) {
            $article = $articles[$feature['article']] ?? null;
            $body = (string) ($article['body'] ?? '');
            $sections = preg_match_all('/^##\s+/mu', $body);
            $listItems = preg_match_all('/^\s*(?:-|\d+\.)\s+/mu', $body);

            $rows[] = [
                'feature' => $key,
                'name' => $feature['label'],
                'description' => (string) ($article['summary'] ?? ''),
                'article' => $feature['article'],
                'audience' => (string) ($article['audience'] ?? ''),
                'sections' => (int) $sections,
                'steps_or_conditions' => (int) $listItems,
                'intents' => $intents[$feature['article']] ?? [],
                'synonyms' => array_values(array_filter(array_map('trim', explode(',', (string) ($article['tags'] ?? ''))))),
                'related_page' => (string) ($article['canonical_path'] ?? ''),
                'live_tools' => self::LIVE_TOOLS[$key] ?? [],
                'source_of_truth' => (string) ($article['source_reference'] ?? ''),
                'review' => isset($article) && in_array($feature['article'], array_column(CardLibrary::articles(), 'slug'), true) ? 'مسودة ← مراجعة ← نشر (ai:knowledge:sync-cards؛ لا نشر آلي)' : 'مقال E45 منشور بعد المراجعة',
                'refusals' => self::REFUSALS[$key] ?? [],
            ];
        }

        return $rows;
    }

    /** فجوات بطاقة الميزة (حقول إلزامية ناقصة). @return list<string> */
    public static function matrixGaps(): array
    {
        $gaps = [];

        foreach (self::matrix() as $r) {
            foreach (['name' => $r['name'] === '', 'description' => $r['description'] === '', 'audience' => $r['audience'] === '', 'related_page' => $r['related_page'] === '', 'source_of_truth' => $r['source_of_truth'] === '', 'sections' => $r['sections'] < 1, 'intents' => $r['intents'] === [], 'synonyms' => $r['synonyms'] === []] as $field => $missing) {
                if ($missing) {
                    $gaps[] = $r['feature'].': حقل ناقص ('.$field.')';
                }
            }
        }

        return $gaps;
    }

    /** ميزات المنصة التي تُشرح بمقال لا يرتبط بمسار GET واحد (لا تدخل في فحص المسارات). */
    public const NON_ROUTE_ARTICLES = ['tatawwur-ai'];

    /**
     * يصنّف مسارًا: ['kind' => 'excluded'|'feature'|'unclassified', 'key' => ...].
     *
     * @return array{kind: string, key: ?string, reason: ?string}
     */
    public static function classify(Route $route): array
    {
        $name = (string) $route->getName();
        $uri = trim($route->uri(), '/');
        $first = explode('/', $uri)[0];

        if ($name === '') {
            if (in_array($first, self::EXCLUDED_URI_PREFIXES, true)) {
                return ['kind' => 'excluded', 'key' => null, 'reason' => 'مسار خدمي/داخلي غير مسمّى ('.$first.')'];
            }

            return ['kind' => 'unclassified', 'key' => null, 'reason' => null];
        }

        foreach (self::EXCLUDED_NAMES as $pattern => $reason) {
            if (self::matches($pattern, $name)) {
                return ['kind' => 'excluded', 'key' => null, 'reason' => $reason];
            }
        }

        if (in_array($first, self::EXCLUDED_URI_PREFIXES, true)) {
            return ['kind' => 'excluded', 'key' => null, 'reason' => 'مسار خدمي/داخلي ('.$first.')'];
        }

        foreach (self::FEATURES as $key => $feature) {
            foreach ($feature['routes'] as $pattern) {
                if (self::matches($pattern, $name)) {
                    return ['kind' => 'feature', 'key' => $key, 'reason' => null];
                }
            }
        }

        return ['kind' => 'unclassified', 'key' => null, 'reason' => null];
    }

    public static function matches(string $pattern, string $name): bool
    {
        if (! str_contains($pattern, '*')) {
            return $pattern === $name;
        }

        return str_starts_with($name, rtrim($pattern, '*'));
    }

    /** slugs كل مقالات المعرفة المعرّفة في الكود (curated + بطاقات). @return list<string> */
    public static function knownArticleSlugs(): array
    {
        return array_values(array_unique(array_merge(
            array_column(CuratedKnowledge::articles(), 'slug'),
            array_column(CardLibrary::articles(), 'slug'),
        )));
    }

    /** slugs المقالات التي لها نية معرفية في كتالوج المحرك. @return list<string> */
    public static function slugsWithIntent(): array
    {
        return array_values(array_unique(array_filter(array_map(fn (array $d) => ($d['k'] ?? '') === 'know' ? ($d['a'] ?? null) : null, IntentCatalog::knowledge()))));
    }
}
