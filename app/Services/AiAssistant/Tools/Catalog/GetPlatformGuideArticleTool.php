<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_platform_guide_article: مقال منشور من دليل المنصة بمعرّفه (للعضو: العامة + الخاصة بالأعضاء؛ للضيف: العامة فقط). المسودات والمسحوبة لا تظهر أبدًا. */
final class GetPlatformGuideArticleTool extends AiTool
{
    public function name(): string
    {
        return 'get_platform_guide_article';
    }

    public function description(): string
    {
        return 'Get one published platform guide (help center) article by its slug: title, summary and text. Use the slug of a knowledge excerpt to read the full article.';
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Help article slug.', 'maxLength' => 120]];
    }

    public function required(): array
    {
        return ['slug'];
    }

    public function area(): string
    {
        return 'general';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $article = AiKnowledgeArticle::query()->published()->forAudience($context->hasMember())
            ->where('locale', 'ar')->where('slug', (string) $arguments['slug'])->first();

        if ($article === null) {
            return $this->notFound('لا يوجد مقال منشور بهذا المعرّف في دليل المنصة.');
        }

        return $this->ok(
            [
                'article' => [
                    'title' => Presenter::text($article->title, 160),
                    'summary' => Presenter::text($article->summary, 400),
                    'text' => Presenter::text($article->body, 3500, true),
                    'path' => $article->publicPath(),
                ],
            ],
            array_values(array_filter([Presenter::link((string) $article->source_label, $article->publicPath())])),
        );
    }
}
