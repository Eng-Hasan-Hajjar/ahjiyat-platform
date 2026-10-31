<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E45 - مقطع نص آمن مفهرَس مشتق من مقال منشور (يكتبه KnowledgePublisher وحده). article_version يربطه بنسخة المقال التي أنتجته:
 * مقطع نسخته ≠ نسخة المقال الحالية لا يُخدَم أبدًا. لا يحوي أي حقل إجابة أو بيانات خاصة أو إجراء إداري (يُفحص عند النشر).
 */
class AiKnowledgeChunk extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'article_version' => 'integer',
            'position' => 'integer',
            'char_count' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }
}
