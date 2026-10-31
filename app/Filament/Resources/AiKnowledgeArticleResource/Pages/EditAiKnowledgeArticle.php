<?php

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiKnowledgeArticle extends EditRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    /** الحقول التحريرية فقط؛ المنشور المتغيّر يعود مسودة فلا يُخدَم محتوى لم تُراجَع نسخته الجديدة. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(auth()->user()?->can('ai-assistant.knowledge.manage'), 403);

        $wasPublished = $record->status === AiKnowledgeArticle::STATUS_PUBLISHED;
        $record->fill(collect($data)->only(AiKnowledgeArticle::EDITORIAL_FIELDS)->except(['locale'])->all());
        $record->save();

        app(KnowledgePublisher::class)->markEdited($record->refresh());

        if ($wasPublished && $record->refresh()->status === AiKnowledgeArticle::STATUS_DRAFT) {
            Notification::make()->warning()->title('عاد المقال مسودة')->body('تغيّر محتوى مقال منشور، فسُحب من الخدمة إلى أن يُنشر من جديد بعد المراجعة.')->send();
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
