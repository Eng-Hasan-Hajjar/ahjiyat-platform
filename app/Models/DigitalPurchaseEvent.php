<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** E40 - سجل أحداث الشراء الرقمي (إضافة فقط): من فعل ماذا ومتى ومن أي حالة إلى أي حالة. لا تعديل ولا حذف. */
class DigitalPurchaseEvent extends Model
{
    public $timestamps = false;

    protected $table = 'digital_purchase_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_at ??= now();
        });

        static::updating(function (): void {
            throw new LogicException('سجل أحداث الشراء للإضافة فقط.');
        });

        static::deleting(function (): void {
            throw new LogicException('سجل أحداث الشراء لا يُحذف.');
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(DigitalPurchase::class, 'digital_purchase_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
