<?php

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\AiKnowledgeArticle;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiKnowledgeArticle extends CreateRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    /** مسودة دائمًا؛ اللغة ثابتة (ar)؛ المنشئ من الجلسة لا من النموذج. النشر إجراء منفصل. */
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(auth()->user()?->can('ai-assistant.knowledge.manage'), 403);

        $article = new AiKnowledgeArticle;
        $article->forceFill(collect($data)->only(AiKnowledgeArticle::EDITORIAL_FIELDS)->all() + [
            'locale' => 'ar',
            'status' => AiKnowledgeArticle::STATUS_DRAFT,
            'version' => 1,
            'created_by' => auth()->id(),
        ])->save();

        return $article;
    }
}
