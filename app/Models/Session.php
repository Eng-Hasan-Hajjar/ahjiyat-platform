<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * غلاف Eloquent للقراءة/الحذف فقط فوق جدول sessions القياسي بـLaravel
 * (Database Session Driver) - ليس له علاقة بآلية الجلسات نفسها.
 */
class Session extends Model
{
    protected $table = 'sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['last_activity' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastActivityAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestamp($this->last_activity);
    }
}