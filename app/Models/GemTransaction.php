<?php

namespace App\Models;

/**
 * @deprecated منذ E9 - طبقة توافق فقط فوق CurrencyTransaction.
 */
class GemTransaction extends CurrencyTransaction
{
    protected $fillable = [
        'user_id', 'amount', 'type', 'reason', 'reference_type', 'reference_id',
    ];
}