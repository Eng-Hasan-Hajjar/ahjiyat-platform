<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'title', 'description', 'cover_image',
        'is_active', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'became_available_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // أُنشئت متاحة = إتاحة أولى حقيقية. (created لا saved: كائن أُنشئ للتو يحتفظ بـwasRecentlyCreated=true
        // فكان أي حفظ لاحق عليه يُطلق المزامنة بلا سبب.)
        static::created(fn (Campaign $campaign) => \App\Services\CampaignLifecycleService::syncFromHook($campaign));

        static::updated(function (Campaign $campaign) {
            // الحقول الثلاثة هي كل ما يدخل بتعريف التوفر (CampaignProgressService::isCampaignAvailable).
            if (! $campaign->wasChanged(['is_active', 'starts_at', 'ends_at'])) {
                return;
            }

            \App\Services\CampaignLifecycleService::syncFromHook($campaign);

            // وهي نفسها ما يُغيّر "هل الموسم مباشر؟" لموسم هذه الحملة.
            $season = $campaign->season;

            if ($season !== null) {
                \App\Services\SeasonLifecycleService::syncFromHook($season);
            }
        });
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CampaignStage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Campaign عادية أو راعٍ لا تملك Season - Nullable عمداً. */
    public function season(): HasOne
    {
        return $this->hasOne(Season::class);
    }
}