<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E46: ملخّص فحص «سلامة الجواهر». سجل تدقيق يكتبه GemIntegrityRunner وحده؛ لا يلمس أي رصيد ولا دفتر.
 */
class GemIntegrityRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status', 'scope_kind', 'scope_currency_ids', 'scope_user_id', 'chunk', 'grace_seconds',
        'scanned', 'matched', 'proven_differences', 'undetermined', 'reason_counts', 'by_currency',
        'findings_stored', 'findings_truncated', 'triggered_by', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_currency_ids' => 'array',
            'reason_counts' => 'array',
            'by_currency' => 'array',
            'findings_truncated' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(GemIntegrityFinding::class, 'run_id');
    }

    /** فحوص اكتملت بنجاح وغطّت نطاقًا غير مقتصر على مستخدم واحد: وحدها «آخر نتيجة موثوقة». */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED)->whereNull('scope_user_id');
    }

    /** آخر فحص موثوق، أو null إن لم يُشغَّل أي فحص مكتمل بعد (فتُعرض «لم يُفحص بعد» لا صفر). */
    public static function lastTrusted(): ?self
    {
        return static::query()->trusted()->orderByDesc('finished_at')->orderByDesc('id')->first();
    }
}
