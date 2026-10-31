<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E45 - رسالة محادثة. الدور مجموعة مغلقة (user | assistant): رسائل الأدوات الداخلية لا تُخزَّن ولا تُعرَض للمستخدم.
 * body مشفَّر بالتخزين (cast: encrypted) ومخفي من التسلسل؛ لا لوحة إدارية تعرضه ولا بحث عنه. الاحتفاظ القصير يخفف أثر تدوير APP_KEY (رسائل أقدم من المفتاح الجديد تصير غير قابلة للقراءة وتُعامَل كمفقودة).
 */
class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'body' => 'encrypted',
            'sources' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'message_id');
    }
}
