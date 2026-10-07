<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** كتم مؤقّت للدردشة العامة (E21): مستقل عن تجميد الحساب. نشِط = لم يُرفع ولم تنتهِ مدته. لا تعيين جماعي: تكتبه ChatMuteService. */
class ChatMute extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')->where('expires_at', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
