<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** E14: سجلّ Append-Only بحت - لا تحديث، لا حذف إداري، لا user_id. */
class AdImpression extends Model
{
    public $timestamps = false;

    protected $fillable = ['ad_placement_id', 'sponsor_campaign_id', 'sponsor_creative_id', 'rendered_at'];

    protected $casts = ['rendered_at' => 'datetime'];
}
