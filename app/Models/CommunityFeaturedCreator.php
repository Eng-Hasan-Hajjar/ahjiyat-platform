<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E37 - «مبدع مختار»: إبراز تحريري يديره فريق المنصة، **منفصل عن السمعة الخوارزمية** (لا يغيّر درجة ولا ترتيبًا ولا يمنح شيئًا). نافذة اختيارية [from, until).
 * لا يمنحه دعم ولا رعاية ولا دفع. تكتبه FeaturedCreatorService وحدها (صلاحية + تدقيق)؛ guarded + لا مسار عميل.
 */
class CommunityFeaturedCreator extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['featured_from' => 'datetime', 'featured_until' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function featuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'featured_by');
    }

    /** المختارون الآن: بدأت نافذتهم (أو بلا بداية) ولم تنتهِ (أو بلا نهاية). */
    public function scopeActiveNow(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where(fn ($q) => $q->whereNull($table.'.featured_from')->orWhere($table.'.featured_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull($table.'.featured_until')->orWhere($table.'.featured_until', '>', now()));
    }
}
