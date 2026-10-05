<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** حظر أحادي الاتجاه. الكتابة عبر BlockService فقط (insertOrIgnore ذري)؛ لا updated_at. */
class UserBlock extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['blocker_id', 'blocked_id'];
}
