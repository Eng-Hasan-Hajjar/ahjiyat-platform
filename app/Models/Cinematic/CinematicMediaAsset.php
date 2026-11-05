<?php

namespace App\Models\Cinematic;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * CIN-1 - ملف وسائط خاص (فيديو/ملصق/ترجمة/صوت) بعد فحص نوعه الحقيقي. لا يُخدم مباشرة أبدًا: عبر مسار محروس فقط.
 * الكتابة عبر CinematicMediaService فقط ($guarded=['*']).
 */
class CinematicMediaAsset extends Model
{
    public const ROLE_VIDEO = 'video';

    public const ROLE_POSTER = 'poster';

    public const ROLE_SUBTITLE = 'subtitle';

    public const ROLE_AUDIO = 'audio';

    public const ROLES = [self::ROLE_VIDEO, self::ROLE_POSTER, self::ROLE_SUBTITLE, self::ROLE_AUDIO];

    public const STATUS_READY = 'ready';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'cinematic_media_assets';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicMediaAsset $asset) {
            $asset->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'meta' => 'array',
            'retired_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
