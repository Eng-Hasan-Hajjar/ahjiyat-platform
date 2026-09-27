<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCosmeticLoadout extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'slot', 'store_item_id', 'equipped_at'];

    protected function casts(): array
    {
        return ['equipped_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }
}