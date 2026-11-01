<?php

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\CuratedKnowledge;
use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use App\Services\AiAssistant\Knowledge\KnowledgeSafetyException;
use App\Services\AiAssistant\Local\AnswerComposer;
use App\Services\AiAssistant\Local\Cards\CardLibrary;
use App\Services\AiAssistant\Local\Cards\FeatureRegistry;
use App\Services\AiAssistant\Local\IntentCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../TatawwurAiE45/_support.php';

/*
| TI-3 / TI-6 / TI-7 - سجل ميزات المستخدم، بطاقات المعرفة، وبوابة التغطية.
| الميزة الجديدة بلا بطاقة معرفة ⇒ فشل؛ والمسارات الإدارية/الداخلية مستثناة صراحةً؛ ولا نشر تلقائيًا من الكود.
*/

/** @return array<string, list<string>> slug ⇒ عناوين أقسامه كما في النص المصدري */
function ti3_headings(): array
{
    $out = [];

    foreach (array_merge(CuratedKnowledge::articles(), CardLibrary::articles()) as $a) {
        preg_match_all('/^#{1,3}\s+(.+)$/mu', (string) $a['body'], $m);
        $out[$a['slug']] = array_map(fn (string $h) => AnswerComposer::foldTitle($h), $m[1]);
    }

    return $out;
}

// ───────────────────────────── TI-3: السجل والتغطية ─────────────────────────────

test('TI3-01: every user-facing GET route is classified - zero unclassified, and admin/internal routes are explicitly excluded', function () {
    $unclassified = [];
    $features = [];
    $excluded = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $c = FeatureRegistry::classify($route);
        $excluded += $c['kind'] === 'excluded' ? 1 : 0;

        if ($c['kind'] === 'unclassified') {
            $unclassified[] = $route->getName().' '.$route->uri();
        }

        if ($c['kind'] === 'feature') {
            $features[$c['key']] = true;
        }
    }

    expect($unclassified)->toBe([])->and($excluded)->toBeGreaterThan(100)->and(count($features))->toBeGreaterThanOrEqual(30);
});

test('TI3-02: admin panel, staff-payment, webhook-like and service routes never map to a public knowledge feature', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        $c = FeatureRegistry::classify($route);

        if (str_starts_with($name, 'filament.') || str_starts_with($name, 'payments.staff.') || str_starts_with($route->uri(), 'admin') || str_starts_with($route->uri(), 'api/')) {
            expect($c['kind'])->toBe('excluded', "admin/internal route leaked into features: $name ".$route->uri());
        }
    }
});

test('TI3-03: every registered feature points to an article defined in code and to at least one knowledge intent', function () {
    $known = FeatureRegistry::knownArticleSlugs();
    $withIntent = FeatureRegistry::slugsWithIntent();

    foreach (FeatureRegistry::FEATURES as $key => $feature) {
        expect($known)->toContain($feature['article'])->and($withIntent)->toContain($feature['article']);
    }
});

test('TI3-04: every knowledge intent targets an existing article and only section headings that really exist in it', function () {
    $headings = ti3_headings();

    foreach (IntentCatalog::knowledge() as $intent => $def) {
        expect($headings)->toHaveKey($def['a']);

        foreach ($def['s'] as $section) {
            expect(in_array(AnswerComposer::foldTitle($section), $headings[$def['a']], true))->toBeTrue("$intent: section '$section' missing in {$def['a']}");
        }
    }
});

test('TI3-05: the coverage command passes on the shipped code and fails when a new unclassified user route appears', function () {
    expect(Artisan::call('ai:local:coverage', ['--strict' => true]))->toBe(0);

    Route::get('/zz-new-feature', fn () => 'x')->name('zznew.index');
    app('router')->getRoutes()->refreshNameLookups();

    $code = Artisan::call('ai:local:coverage', ['--strict' => true]);
    $out = Artisan::output();

    expect($code)->toBe(1)->and($out)->toContain('zznew.index');
});

test('TI3-06: a new admin-only route does not require a knowledge card (explicit exclusion)', function () {
    Route::get('/admin/zz-tool', fn () => 'x')->name('filament.admin.pages.zz-tool');
    app('router')->getRoutes()->refreshNameLookups();

    expect(Artisan::call('ai:local:coverage', ['--strict' => true]))->toBe(0);
});

test('TI3-07: coverage is read-only - it writes nothing to the database', function () {
    $before = AiKnowledgeArticle::query()->count();
    Artisan::call('ai:local:coverage', ['--strict' => true, '--db' => true]);

    expect(AiKnowledgeArticle::query()->count())->toBe($before);
});

// ───────────────────────────── TI-6: البطاقات ─────────────────────────────

test('TI6-01: cards are drafts by default - sync creates them unpublished and the index serves none of them', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false]);

    expect(Artisan::call('ai:knowledge:sync-cards'))->toBe(0);

    $cards = AiKnowledgeArticle::query()->whereIn('slug', array_column(CardLibrary::articles(), 'slug'))->get();

    expect($cards)->toHaveCount(count(CardLibrary::articles()))
        ->and($cards->pluck('status')->unique()->all())->toBe([AiKnowledgeArticle::STATUS_DRAFT])
        ->and(array_intersect(array_column(app(App\Services\AiAssistant\Knowledge\KnowledgeIndex::class)->entries(true), 'slug'), array_column(CardLibrary::articles(), 'slug')))->toBe([]);
});

test('TI6-02: explicit --publish publishes every card after the safety scan, and re-running changes nothing (idempotent)', function () {
    Artisan::call('ai:knowledge:sync-cards', ['--publish' => true]);
    $versions = AiKnowledgeArticle::query()->whereIn('slug', array_column(CardLibrary::articles(), 'slug'))->pluck('version', 'slug')->all();

    expect(AiKnowledgeArticle::query()->whereIn('slug', array_keys($versions))->where('status', AiKnowledgeArticle::STATUS_PUBLISHED)->count())->toBe(count(CardLibrary::articles()));

    Artisan::call('ai:knowledge:sync-cards', ['--publish' => true]);
    $again = AiKnowledgeArticle::query()->whereIn('slug', array_keys($versions))->pluck('version', 'slug')->all();

    expect($again)->toBe($versions);
});

test('TI6-03: every card is scan-clean (no emails, URLs, admin paths, env vars, SQL or artisan) and states no variable value, price or date', function () {
    foreach (CardLibrary::articles() as $card) {
        $text = $card['summary']."\n".$card['body'];

        expect(KnowledgeNormalizer::scan($text))->toBe([], 'unsafe content in card '.$card['slug']);
        expect(preg_match('/\d/u', preg_replace('/^#{1,3}\s.*$/mu', '', $text) ?? $text))->toBe(0, 'digits (a variable value?) in card '.$card['slug'].': '.$text);
        expect($card['audience'])->toBeIn(['public', 'member'])
            ->and(str_starts_with($card['canonical_path'], '/'))->toBeTrue()
            ->and(str_contains($card['canonical_path'], 'admin'))->toBeFalse();
    }
});

test('TI6-04: unsafe card content is refused by the publisher and never reaches the index', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false]);
    $article = new AiKnowledgeArticle;
    $article->forceFill([
        'slug' => 'zz-unsafe', 'locale' => 'ar', 'title' => 'بطاقة غير آمنة', 'summary' => 'ملخص تجريبي لبطاقة غير آمنة يجب أن تُرفض.', 'body' => "## قسم\nراجع لوحة الإدارة على /admin/users وراسل admin@example.com لمعرفة كلمة المرور.",
        'audience' => 'public', 'area' => 'general', 'tags' => null, 'source_label' => 'دليل المنصة', 'canonical_path' => '/puzzles', 'source_type' => 'distilled',
        'status' => AiKnowledgeArticle::STATUS_DRAFT, 'version' => 1,
    ])->save();

    expect(fn () => app(KnowledgePublisher::class)->publish($article))->toThrow(KnowledgeSafetyException::class)
        ->and($article->refresh()->status)->toBe(AiKnowledgeArticle::STATUS_DRAFT);
});

test('TI6-05: the card library covers the listed user-facing domains beyond the 21 original articles', function () {
    $slugs = array_column(CardLibrary::articles(), 'slug');
    $original = array_column(CuratedKnowledge::articles(), 'slug');

    expect(count($original))->toBe(21)->and(array_intersect($slugs, $original))->toBe([])->and(count($slugs))->toBeGreaterThanOrEqual(16);

    foreach (['barnamaj-al-daawa', 'al-majmuaat', 'al-taaliqat', 'al-sumaa', 'aman-al-hisab', 'daam-al-minassa', 'mushtarayati', 'al-tahaddiyat-wal-hamalat', 'lawhat-al-sadara', 'al-malaf-al-shakhsi', 'arbah-al-mubdiin'] as $slug) {
        expect($slugs)->toContain($slug);
    }
});

test('TI6-06: card sections the engine extracts really exist after publishing (extraction works end to end)', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false]);
    e45_kb();
    Artisan::call('ai:knowledge:sync-cards', ['--publish' => true]);
    $index = app(App\Services\AiAssistant\Knowledge\KnowledgeIndex::class);
    $composer = new AnswerComposer;
    $analysis = (new App\Services\AiAssistant\Local\Language\Analyzer)->analyze('سؤال');

    foreach (IntentCatalog::knowledge() as $intent => $def) {
        $entries = array_values(array_filter($index->entries(true), fn (array $e) => $e['slug'] === $def['a']));
        $block = $composer->compose($entries, $def['s'], $analysis);

        expect($block)->not->toBeNull("no extractable block for $intent");
        expect(mb_strlen($block['text']))->toBeGreaterThan(15)->and(mb_strlen($block['text']))->toBeLessThanOrEqual(AnswerComposer::MAX_CHARS + 60);
    }
});
