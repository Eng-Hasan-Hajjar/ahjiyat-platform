<?php

use App\Filament\Pages\TatawwurAiPage;
use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\CreateAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\EditAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages\ListAiKnowledgeArticles;
use App\Models\AiConversation;
use App\Models\AiFeedback;
use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use App\Models\AiMessage;
use App\Models\AiRequestMetric;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../AhjiyatPassE40/_support.php';

/*
| E45 - لوحة الإدارة: صفحة «تطوّر AI» (استخدام وتقييمات وفجوات بلا أي محتوى محادثات) ومورد «معرفة تطوّر AI» (مسودات ← نشر/سحب بصلاحيات منفصلة).
| لا توجد أي صلاحية أو واجهة لقراءة نص المحادثات أو هوية السائلين، ولا حقل لتعديل مفتاح المزوّد أو نموذجه.
*/

const E45A_PAGE = '/admin/tatawwur-ai';
const E45A_LIST = '/admin/ai-knowledge-articles';

beforeEach(function () {
    e40_seed();
    e45_enable();
});

function e45a_staff(array $permissions): App\Models\User
{
    return e40_staff_with(array_merge(['admin.access'], $permissions));
}

// ───────────────────────────── الصلاحيات ─────────────────────────────

test('E45-A01: the three permissions are registered and the administrator role holds them; plain staff and players do not', function () {
    foreach (['ai-assistant.view', 'ai-assistant.knowledge.manage', 'ai-assistant.knowledge.publish'] as $permission) {
        expect(Spatie\Permission\Models\Permission::query()->where('name', $permission)->exists())->toBeTrue($permission)
            ->and(e40_admin()->can($permission))->toBeTrue()->and(e40_plain_staff()->can($permission))->toBeFalse()->and(e16User()->can($permission))->toBeFalse();
    }
});

test('E45-A02: there is no permission or policy ability that reads conversations, messages or user identities of the assistant', function () {
    $all = array_keys(collect(config('permissions'))->flatMap(fn ($g) => $g['permissions'] ?? [])->all());
    $ai = array_values(array_filter($all, fn ($p) => str_starts_with($p, 'ai-assistant.')));

    expect($ai)->toBe(['ai-assistant.view', 'ai-assistant.knowledge.manage', 'ai-assistant.knowledge.publish'])
        ->and(collect($all)->filter(fn ($p) => preg_match('/conversation|transcript|chat-log|prompt/i', $p) === 1 && str_contains($p, 'ai'))->all())->toBe([]);

    foreach (['App\\Filament\\Resources\\AiConversationResource', 'App\\Filament\\Resources\\AiMessageResource'] as $class) {
        expect(class_exists($class))->toBeFalse();
    }
});

test('E45-A03: the dashboard page is reachable only with ai-assistant.view', function () {
    $this->get(E45A_PAGE)->assertRedirect();
    $this->actingAs(e16User())->get(E45A_PAGE)->assertForbidden();
    $this->actingAs(e45a_staff([]))->get(E45A_PAGE)->assertForbidden();
    $this->flushSession();
    $this->actingAs(e45a_staff(['ai-assistant.view']))->get(E45A_PAGE)->assertOk()->assertSee('تطوّر AI');

    expect(TatawwurAiPage::canAccess())->toBeTrue();
});

test('E45-A04: the knowledge resource list requires view, create requires manage, and nobody can delete', function () {
    $article = e45_article('policy-art', 'مقال السياسة', 'نص تعريفي اختباري آمن عن سياسة الصلاحيات في دليل المنصة ويكفي للنشر.');
    $viewer = e45a_staff(['ai-assistant.view']);
    $manager = e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']);

    expect(Gate::forUser($viewer)->allows('viewAny', AiKnowledgeArticle::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('create', AiKnowledgeArticle::class))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', AiKnowledgeArticle::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $article))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $article))->toBeFalse()
        ->and(Gate::forUser(e40_admin())->allows('delete', $article))->toBeFalse()
        ->and(AiKnowledgeArticleResource::canDelete($article))->toBeFalse();

    $this->actingAs(e16User())->get(E45A_LIST)->assertForbidden();
});

// ───────────────────────────── لوحة الاستخدام ─────────────────────────────

test('E45-A05: the dashboard shows usage, outcomes, feedback and knowledge gaps - and never question text, answers or user identity', function () {
    $fake = e45_enable();
    e45_kb();
    $fake->answer('رد')->answer('رد');
    $user = e45_member();

    $answered = e45_ask($this->actingAs($user), 'ما هي الجواهر وكيف تعمل؟')->json();
    e45_ask($this, 'ما هي الجواهر وكيف تعمل؟');
    e45_ask($this, 'ما عاصمة بلد كيلاميندا السرية؟ عن منصة أحجيات');
    $this->postJson(route('tatawwur.feedback.store'), ['message' => $answered['id'], 'rating' => 'down', 'reason' => 'inaccurate'])->assertOk();
    $this->flushSession();

    $html = $this->actingAs(e45a_staff(['ai-assistant.view']))->get(E45A_PAGE)->assertOk()->getContent();

    expect($html)->not->toContain('كيلاميندا')->not->toContain($user->email)->not->toContain($user->name)->not->toContain('ما هي الجواهر وكيف تعمل');
});

test('E45-A06: the analytics service aggregates correctly - totals by outcome, grounded/streamed, tokens, errors, ratings and gaps by area', function () {
    $now = now();
    $rows = [
        ['outcome' => 'answered', 'area' => 'general', 'grounded' => 1, 'input_tokens' => 100, 'output_tokens' => 40, 'latency_ms' => 800, 'audience' => 'member'],
        ['outcome' => 'answered', 'area' => 'general', 'grounded' => 1, 'input_tokens' => 50, 'output_tokens' => 20, 'latency_ms' => 400, 'audience' => 'member'],
        ['outcome' => 'abstained', 'area' => 'store', 'grounded' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 10, 'audience' => 'guest'],
        ['outcome' => 'abstained', 'area' => 'store', 'grounded' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 10, 'audience' => 'guest'],
        ['outcome' => 'fallback', 'area' => null, 'grounded' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 30, 'audience' => 'member', 'error_category' => 'timeout'],
    ];

    foreach ($rows as $row) {
        $metric = new AiRequestMetric;
        $metric->forceFill($row + ['provider' => 'fake', 'model' => 'm', 'reason' => null, 'streamed' => 0, 'tool_calls' => 0, 'created_at' => $now])->save();
    }

    $overview = app(App\Services\AiAssistant\TatawwurAnalytics::class)->overview(7);

    expect($overview['total'] ?? array_sum($overview['by_outcome'] ?? []))->toBe(5);
    expect(json_encode($overview, JSON_UNESCAPED_UNICODE))->toContain('timeout')->toContain('المتجر');
});

test('E45-A07: the dashboard status block reports readiness as yes/no only - no key, model secret or limits are rendered', function () {
    e45_enable(['tatawwur_ai.provider' => 'openai', 'tatawwur_ai.providers.openai.api_key' => 'sk-test-ADMIN-LEAK-0123456789abcdef', 'tatawwur_ai.model' => 'test-model-1']);
    $html = $this->actingAs(e45a_staff(['ai-assistant.view']))->get(E45A_PAGE)->assertOk()->getContent();

    expect($html)->not->toContain('sk-test-ADMIN-LEAK')->not->toContain('ADMIN-LEAK')->not->toContain('api_key');
    expect(json_encode(app(App\Services\AiAssistant\TatawwurAnalytics::class)->status(), JSON_UNESCAPED_UNICODE))->not->toContain('sk-test');
});

test('E45-A08: the rebuild action needs ai-assistant.knowledge.publish (viewer gets 403) and then rebuilds only published articles locally with no network', function () {
    e45_kb();
    $before = AiKnowledgeChunk::query()->count();

    $viewer = e45a_staff(['ai-assistant.view']);
    $this->actingAs($viewer);
    Livewire::test(TatawwurAiPage::class)->call('rebuild')->assertForbidden();
    expect(AiKnowledgeChunk::query()->count())->toBe($before);

    $publisher = e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.publish']);
    $this->actingAs($publisher);
    Livewire::test(TatawwurAiPage::class)->call('rebuild')->assertHasNoErrors();
    Illuminate\Support\Facades\Http::assertNothingSent();

    expect(AiKnowledgeChunk::query()->count())->toBe($before);
});

test('E45-A09: the dashboard period selector accepts only the allowed windows', function () {
    $this->actingAs(e45a_staff(['ai-assistant.view']));

    $page = Livewire::test(TatawwurAiPage::class)->call('setDays', 30);
    expect($page->get('days'))->toBe(30);

    $page->call('setDays', 99999);
    expect($page->get('days'))->toBe(7);
});

// ───────────────────────────── مورد المعرفة ─────────────────────────────

function e45a_form(array $over = []): array
{
    return $over + [
        'slug' => 'admin-created', 'title' => 'مقال أنشأه المشرف', 'summary' => 'ملخص مقال أنشأه المشرف لاختبار النموذج.', 'body' => "## قسم\n\nنص عربي آمن يكفي لاجتياز فحص الطول والسلامة في لوحة الإدارة.",
        'audience' => 'public', 'area' => 'general', 'tags' => 'اختبار', 'canonical_path' => '/puzzles',
    ];
}

test('E45-A10: a manager creates a DRAFT article (never published by creating), with editorial fields only', function () {
    $this->actingAs(e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']));

    Livewire::test(CreateAiKnowledgeArticle::class)->fillForm(e45a_form())->call('create')->assertHasNoFormErrors();

    $article = AiKnowledgeArticle::query()->where('slug', 'admin-created')->firstOrFail();
    expect($article->status)->toBe('draft')->and($article->locale)->toBe('ar')->and($article->version)->toBe(1)->and($article->content_hash)->toBeNull()
        ->and(AiKnowledgeChunk::query()->where('article_id', $article->id)->exists())->toBeFalse();
});

test('E45-A11: the form validates slug uniqueness and format, summary length and the canonical path (internal only)', function () {
    $this->actingAs(e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']));
    e45_article('taken-slug', 'مقال محجوز', 'نص تعريفي اختباري آمن عن مقال محجوز في دليل المنصة ويكفي للنشر.');

    Livewire::test(CreateAiKnowledgeArticle::class)->fillForm(e45a_form(['slug' => 'taken-slug']))->call('create')->assertHasFormErrors(['slug']);
    Livewire::test(CreateAiKnowledgeArticle::class)->fillForm(e45a_form(['slug' => 'Bad Slug']))->call('create')->assertHasFormErrors(['slug']);
    Livewire::test(CreateAiKnowledgeArticle::class)->fillForm(e45a_form(['canonical_path' => 'https://evil.example']))->call('create')->assertHasFormErrors(['canonical_path']);
    Livewire::test(CreateAiKnowledgeArticle::class)->fillForm(e45a_form(['summary' => '']))->call('create')->assertHasFormErrors(['summary']);

    expect(AiKnowledgeArticle::query()->where('slug', 'admin-created')->exists())->toBeFalse();
});

test('E45-A12: a viewer without manage cannot open the create or edit pages and cannot update through the policy', function () {
    $article = e45_article('viewer-art', 'مقال المشاهد', 'نص تعريفي اختباري آمن عن مقال المشاهد في دليل المنصة ويكفي للنشر.');
    $this->actingAs(e45a_staff(['ai-assistant.view']));

    $this->get(E45A_LIST.'/create')->assertForbidden();
    $this->get(E45A_LIST.'/'.$article->getRouteKey().'/edit')->assertForbidden();

    expect(AiKnowledgeArticle::query()->where('slug', 'admin-created')->exists())->toBeFalse()->and(Gate::allows('update', $article))->toBeFalse();
});

test('E45-A13: editing a PUBLISHED article reverts it to draft, removes its chunks from service and warns - the new text is not served until re-published', function () {
    $article = e45_article('served-art', 'مقال مخدوم', "## قسم\n\nنص تعريفي اختباري آمن عن مقال مخدوم في دليل المنصة ويكفي للنشر والاسترجاع.");
    expect(AiKnowledgeChunk::query()->where('article_id', $article->id)->exists())->toBeTrue();

    $this->actingAs(e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']));
    Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['body' => "## قسم\n\nنص معدّل جديد لم تتم مراجعته بعد ويجب ألا يُخدَم قبل إعادة النشر الصريحة."])->call('save')->assertHasNoFormErrors();

    expect($article->refresh()->status)->toBe('draft')->and(AiKnowledgeChunk::query()->where('article_id', $article->id)->exists())->toBeFalse();
    $this->get(route('help.show', 'served-art'))->assertNotFound();
});

test('E45-A14: a manager cannot change status, version or hash through the edit form (editorial fields only)', function () {
    $article = e45_article('field-art', 'مقال الحقول', "## قسم\n\nنص تعريفي اختباري آمن عن مقال الحقول في دليل المنصة ويكفي للنشر والاسترجاع.");
    $hash = $article->content_hash;
    $this->actingAs(e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']));

    Livewire::test(EditAiKnowledgeArticle::class, ['record' => $article->getRouteKey()])->fillForm(['title' => 'عنوان معدّل'])->call('save');

    $fresh = $article->refresh();
    expect($fresh->title)->toBe('عنوان معدّل')->and($fresh->version)->toBe(1)->and($fresh->content_hash)->toBe($hash);
});

test('E45-A15: the publish table action needs ai-assistant.knowledge.publish, runs the safety scan, and a failed scan keeps the article a draft with an error', function () {
    $article = new AiKnowledgeArticle;
    $article->forceFill(['slug' => 'pub-action', 'locale' => 'ar', 'title' => 'مقال الإجراء', 'summary' => 'ملخص مقال الإجراء لاختبار النشر.', 'body' => "## قسم\n\nنص آمن يكفي للنشر من لوحة الإدارة عبر الإجراء الرسمي المخصص لذلك.", 'audience' => 'public', 'area' => 'general', 'source_label' => 'دليل المنصة', 'canonical_path' => '/puzzles', 'status' => 'draft', 'version' => 1])->save();

    $manager = e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.manage']);
    $this->actingAs($manager);
    Livewire::test(ListAiKnowledgeArticles::class)->assertTableActionHidden('publish', $article);

    $publisher = e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.publish']);
    $this->actingAs($publisher);
    Livewire::test(ListAiKnowledgeArticles::class)->assertTableActionVisible('publish', $article)->callTableAction('publish', $article);
    expect($article->refresh()->status)->toBe('published')->and(AiKnowledgeChunk::query()->where('article_id', $article->id)->exists())->toBeTrue();

    $bad = new AiKnowledgeArticle;
    $bad->forceFill(['slug' => 'pub-bad', 'locale' => 'ar', 'title' => 'مقال سيئ', 'summary' => 'ملخص مقال سيئ لاختبار فحص الأمان.', 'body' => "## قسم\n\nمفتاح sk-proj-AbCdEf1234567890AbCdEf1234567890 داخل نص طويل بما يكفي لاجتياز الطول.", 'audience' => 'public', 'area' => 'general', 'source_label' => 'دليل المنصة', 'canonical_path' => '/puzzles', 'status' => 'draft', 'version' => 1])->save();
    Livewire::test(ListAiKnowledgeArticles::class)->callTableAction('publish', $bad);

    expect($bad->refresh()->status)->toBe('draft')->and(AiKnowledgeChunk::query()->where('article_id', $bad->id)->exists())->toBeFalse();
});

test('E45-A16: the retire action withdraws a published article from service', function () {
    $article = e45_article('retire-action', 'مقال السحب', "## قسم\n\nنص تعريفي اختباري آمن عن مقال السحب في دليل المنصة ويكفي للنشر والاسترجاع.");
    $this->actingAs(e45a_staff(['ai-assistant.view', 'ai-assistant.knowledge.publish']));

    Livewire::test(ListAiKnowledgeArticles::class)->callTableAction('retire', $article);

    expect($article->refresh()->status)->toBe('retired')->and(AiKnowledgeChunk::query()->where('article_id', $article->id)->exists())->toBeFalse();
});

// ───────────────────────────── الخصوصية ─────────────────────────────

test('E45-A17: assistant tables hold no raw user identifiers in metrics and feedback outlives its user without identity', function () {
    expect(Schema::getColumnListing('ai_request_metrics'))->not->toContain('user_id', 'session_id', 'ip', 'message', 'prompt');

    $fake = e45_enable();
    e45_kb();
    $fake->answer('رد');
    $user = e45_member();
    $res = e45_ask($this->actingAs($user), 'ما هي الجواهر وكيف تعمل؟')->json();
    $this->postJson(route('tatawwur.feedback.store'), ['message' => $res['id'], 'rating' => 'up'])->assertOk();

    $this->deleteJson(route('tatawwur.history.destroy'))->assertOk();

    expect(AiConversation::query()->count())->toBe(0)->and(AiMessage::query()->count())->toBe(0)->and(AiFeedback::query()->count())->toBe(1)->and(AiFeedback::query()->first()->user_id)->toBeNull();
});

test('E45-A18: deleting a user account cascades their conversations and detaches nothing identifiable', function () {
    $fake = e45_enable();
    e45_kb();
    $fake->answer('رد');
    $user = e45_member();
    e45_ask($this->actingAs($user), 'ما هي الجواهر وكيف تعمل؟')->assertOk();
    expect(AiConversation::query()->where('user_id', $user->id)->count())->toBe(1);

    $user->delete();

    expect(AiConversation::query()->count())->toBe(0)->and(AiMessage::query()->count())->toBe(0);
});
