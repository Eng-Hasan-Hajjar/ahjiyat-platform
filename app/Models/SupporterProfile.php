<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E30 - ملف داعم (فرد/مؤسسة). لا تفاصيل دفع هنا ولا بالنظام كله. العلنية = status active ∧ is_public (scopePublic) وتُفحص حيًّا بكل عرض عام.
 * لا تعيين جماعي: كل كتابة عبر SupporterService (تدقيق + تنظيف + رابط آمن). الرابط العام بـslug (لا معرّف داخلي)؛ public_id للإحالات الآمنة.
 */
class SupporterProfile extends Model
{
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPES = [self::TYPE_INDIVIDUAL, self::TYPE_ORGANIZATION];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (SupporterProfile $profile) {
            $profile->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'anonymous_by_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SponsorshipContribution::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(SponsorshipProgramRole::class);
    }

    /** علني: نشط ومعلن. الموقوف/غير النشط/الخاص لا يظهر بأي سطح عام (ولو بقيت مساهماته تُحتسب بمجاميع البرنامج). */
    public function scopePublic(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.status', self::STATUS_ACTIVE)->where($table.'.is_public', true);
    }

    public function isPublic(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_public;
    }

    public function isOrganization(): bool
    {
        return $this->type === self::TYPE_ORGANIZATION;
    }
}
