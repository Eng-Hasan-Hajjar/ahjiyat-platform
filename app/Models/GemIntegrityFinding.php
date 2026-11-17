<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E46: حالة غير مطابقة من فحص سلامة الجواهر (فرق مثبت أو لا يمكن الجزم). أرقام ومعرّفات داخلية فقط.
 */
class GemIntegrityFinding extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id', 'wallet_id', 'user_id', 'currency_id', 'verdict',
        'stored_available', 'derived_available', 'stored_pending', 'derived_pending',
        'ledger_rows', 'reasons', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'notes' => 'array',
            'stored_available' => 'integer',
            'derived_available' => 'integer',
            'stored_pending' => 'integer',
            'derived_pending' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GemIntegrityRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function diffAvailable(): int
    {
        return $this->stored_available - $this->derived_available;
    }

    public function diffPending(): int
    {
        return $this->stored_pending - $this->derived_pending;
    }
}
