<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'quest_enabled', 'streak_enabled', 'achievement_enabled', 'season_enabled', 'campaign_enabled', 'social_enabled'];

    /** غياب الصف = الافتراضيات: الكل مفعَّل (تنطبق أيضًا على نسخة غير محفوظة). */
    protected $attributes = [
        'quest_enabled' => true,
        'streak_enabled' => true,
        'achievement_enabled' => true,
        'season_enabled' => true,
        'campaign_enabled' => true,
        'social_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'quest_enabled' => 'boolean',
            'streak_enabled' => 'boolean',
            'achievement_enabled' => 'boolean',
            'season_enabled' => 'boolean',
            'campaign_enabled' => 'boolean',
            'social_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
