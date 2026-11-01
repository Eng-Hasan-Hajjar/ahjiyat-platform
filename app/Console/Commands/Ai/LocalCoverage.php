<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Local\Cards\FeatureRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;

/**
 * TI-7 - بوابة تغطية المعرفة: كل ميزة موجّهة للمستخدم يجب أن يكون لها (1) تصنيف في FeatureRegistry، (2) مقال معرفة معرَّف في الكود، (3) نية معرفية في كتالوج المحرك.
 * - يفرّق بين مسارات المستخدم ومسارات الإدارة/الويبهوك/الداخلية (مستثناة صراحةً بأنماط معلنة).
 * - للقراءة فقط: لا ينشر ولا يكتب في قاعدة البيانات ولا يقرأ ملفات ولا أسرارًا.
 * - --strict: رمز خروج 1 عند أي فجوة (للـCI). --db: يفحص أيضًا أن المقال منشور في القاعدة الحالية (للتشغيل الفعلي بعد sync-cards --publish).
 */
class LocalCoverage extends Command
{
    protected $signature = 'ai:local:coverage {--strict : رمز خروج 1 عند أي فجوة} {--db : افحص نشر المقالات في قاعدة البيانات الحالية (قراءة فقط)} {--format=text : text|md}';

    protected $description = 'TI: يتحقق أن كل ميزة مستخدم جديدة لها بطاقة معرفة ونية في المحرك الداخلي (قراءة فقط).';

    public function handle(): int
    {
        $features = [];
        $unclassified = [];
        $excluded = 0;
        $userRoutes = 0;

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $c = FeatureRegistry::classify($route);

            if ($c['kind'] === 'excluded') {
                $excluded++;
            } elseif ($c['kind'] === 'feature') {
                $userRoutes++;
                $features[$c['key']] = ($features[$c['key']] ?? 0) + 1;
            } else {
                $unclassified[] = ($route->getName() ?: '(غير مسمّى)').' ['.$route->uri().']';
            }
        }

        $known = FeatureRegistry::knownArticleSlugs();
        $withIntent = FeatureRegistry::slugsWithIntent();
        $published = $this->option('db') && Schema::hasTable('ai_knowledge_articles')
            ? AiKnowledgeArticle::query()->where('locale', 'ar')->where('status', AiKnowledgeArticle::STATUS_PUBLISHED)->pluck('slug')->all()
            : null;

        $gaps = [];
        $rows = [];

        foreach (FeatureRegistry::FEATURES as $key => $feature) {
            $slug = $feature['article'];
            $routes = $features[$key] ?? 0;
            $status = [];

            if (! in_array($slug, $known, true)) {
                $status[] = 'لا مقال معرَّف';
            }

            if (! in_array($slug, $withIntent, true)) {
                $status[] = 'لا نية معرفية';
            }

            if ($published !== null && ! in_array($slug, $published, true)) {
                $status[] = 'غير منشور في القاعدة';
            }

            if ($routes === 0) {
                $status[] = 'لا مسار مطابق حاليًا (نمط قديم؟)';
            }

            $rows[] = [$key, $feature['label'], $slug, (string) $routes, $status === [] ? 'مغطّاة' : implode('، ', $status)];

            if ($status !== [] && $routes > 0) {
                $gaps[] = $key.': '.implode('، ', $status);
            }
        }

        foreach ($unclassified as $u) {
            $gaps[] = 'مسار مستخدم غير مصنَّف: '.$u;
        }

        if ($this->option('format') === 'md') {
            $this->line('| الميزة | الوصف | المقال | مسارات GET | الحالة |');
            $this->line('|---|---|---|---|---|');

            foreach ($rows as $r) {
                $this->line('| '.implode(' | ', $r).' |');
            }
        } else {
            $this->table(['الميزة', 'الوصف', 'المقال', 'مسارات GET', 'الحالة'], $rows);
        }

        $this->line(sprintf('مسارات GET: مستخدم=%d، مستثناة (إدارة/داخلي)=%d، غير مصنَّفة=%d، ميزات=%d، فجوات=%d', $userRoutes, $excluded, count($unclassified), count(FeatureRegistry::FEATURES), count($gaps)));

        foreach ($gaps as $gap) {
            $this->error($gap);
        }

        return $gaps !== [] && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
