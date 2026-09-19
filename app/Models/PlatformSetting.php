<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف واحد = مفتاح واحد بمجموعة واحدة. لا Business Logic هون (Casting/
 * Defaults/Cache كلها بـPlatformSettingsService) - هذا الـModel تخزين خام
 * فقط. value مخزَّنة دائمًا كنص (JSON-encoded للأنواع المركّبة) - التحويل
 * لنوعها الحقيقي (bool/int/float/array) يتم فقط عبر الـService.
 */
class PlatformSetting extends Model
{
    use HasFactory;

    protected $fillable = ['group', 'key', 'value', 'type', 'updated_by'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}