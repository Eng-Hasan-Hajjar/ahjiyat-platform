<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * سجل غير قابل للتعديل (Immutable) لتغييرات RBAC الإدارية فقط. لا Business
 * Logic هون - الكتابة الفعلية عبر AuthorizationAuditService حصراً.
 */
class AuthorizationAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}