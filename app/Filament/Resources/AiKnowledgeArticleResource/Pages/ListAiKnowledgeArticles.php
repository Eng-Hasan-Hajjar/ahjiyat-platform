<?php

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiKnowledgeArticles extends ListRecords
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
