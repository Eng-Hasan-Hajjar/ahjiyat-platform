<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\AiKnowledgeRetriever;
use App\Services\AiAssistant\TatawwurSettings;
use App\Support\HelpArticleBody;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * E45 - «دليل المنصة»: صفحات قراءة عامة لمقالات معرفة **منشورة** يدويًا (المصدر نفسه الذي يستند إليه المساعد، فالمستخدم يتحقق من الجواب بنفسه).
 *  - لا تكتب شيئًا. الضيف يرى مقالات public فقط؛ العضو الموثَّق يرى public + member. غير ذلك 404 (لا نكشف وجود مقال للأعضاء).
 *  - تبقى متاحة حتى لو أُوقف المساعد (TATAWWUR_AI_ENABLED=false).
 *  - البحث الاختياري ?q= يستعمل الاسترجاع المحلي نفسه (معجمي، بلا مزوّد ذكاء خارجي).
 */
class HelpCenterController extends Controller
{
    public function index(Request $request, AiKnowledgeRetriever $retriever): View
    {
        $member = TatawwurSettings::audienceFor($request->user()) === TatawwurSettings::AUDIENCE_MEMBER;
        $q = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $request->query('q', '')) ?? ''), 0, 80);

        $base = AiKnowledgeArticle::query()->published()->forAudience($member)->where('locale', 'ar');

        $found = null;

        if ($q !== '') {
            $slugs = collect($retriever->retrieve($q, $member, 8))->pluck('slug')->unique()->values();
            $found = $slugs->isEmpty() ? collect() : (clone $base)->whereIn('slug', $slugs->all())->get(['slug', 'title', 'summary', 'area'])
                ->sortBy(fn (AiKnowledgeArticle $a) => $slugs->search($a->slug))->values();
        }

        $articles = $found ?? (clone $base)->orderBy('title')->get(['slug', 'title', 'summary', 'area']);
        $order = array_keys(AiKnowledgeArticle::AREAS);
        $grouped = $found === null
            ? $articles->groupBy(fn (AiKnowledgeArticle $a) => array_key_exists($a->area, AiKnowledgeArticle::AREAS) ? $a->area : 'other')
                ->sortBy(fn ($items, $area) => array_search($area, $order, true))
            : collect();

        return view('help.index', [
            'q' => $q,
            'found' => $found,
            'grouped' => $grouped,
            'areas' => AiKnowledgeArticle::AREAS,
            'total' => $articles->count(),
            'assistantVisible' => TatawwurSettings::widgetVisibleFor($request->user()),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $member = TatawwurSettings::audienceFor($request->user()) === TatawwurSettings::AUDIENCE_MEMBER;

        $article = AiKnowledgeArticle::query()->published()->forAudience($member)->where('locale', 'ar')->where('slug', $slug)->first();

        abort_if($article === null, 404);

        $related = AiKnowledgeArticle::query()->published()->forAudience($member)->where('locale', 'ar')
            ->where('area', $article->area)->where('id', '!=', $article->id)->orderBy('title')->limit(4)->get(['slug', 'title']);

        return view('help.show', [
            'article' => $article,
            'blocks' => HelpArticleBody::blocks((string) $article->body),
            'areaLabel' => AiKnowledgeArticle::AREAS[$article->area] ?? AiKnowledgeArticle::AREAS['other'],
            'related' => $related,
            'assistantVisible' => TatawwurSettings::widgetVisibleFor($request->user()),
        ]);
    }
}
