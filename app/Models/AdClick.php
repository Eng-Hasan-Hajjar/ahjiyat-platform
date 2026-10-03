<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** E14: سجلّ Append-Only بحت - لا تحديث، لا حذف إداري، لا IP، لا User Agent. */
class AdClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['ad_placement_id', 'sponsor_campaign_id', 'sponsor_creative_id', 'clicked_at'];

    protected $casts = ['clicked_at' => 'datetime'];
}
