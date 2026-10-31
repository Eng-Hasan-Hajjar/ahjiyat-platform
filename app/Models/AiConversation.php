<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E45 - محادثة مساعد «تطوّر AI» لعضو موثَّق. **منفصلة تمامًا عن دردشة E21** (لا chat_threads ولا chat_messages).
 * تخص مالكها وحده (كل قراءة/كتابة تُقيَّد بـuser_id للمستخدم الحالي: لا IDOR). expires_at = الاحتفاظ؛ تنظيفها بأمر ai:conversations:prune.
 * الضيف لا صف له: محادثته جلسة مؤقتة (انظر ConversationService).
 */
class AiConversation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'messages_count' => 'integer',
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            $conversation->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('seq');
    }
}
