<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E30 - مستوى داعم يُدار من الإدارة (اسم عربي + حدّ أدنى بالوحدة الصغرى لعملة المحاسبة). المستوى لا يُخزَّن على الداعم ولا يأتي من العميل:
 * يُشتقّ دائمًا من صافي دعمه المؤكَّد (SupporterTierService)، فالاسترداد يخفّضه آليًا.
 */
class SupporterTier extends Model
{
    protected $fillable = ['name', 'slug', 'minimum_accounting_amount_minor', 'sort_order', 'badge_key', 'is_active'];

    /** أي تعديل/حذف لمستوى يُبطل كاشات الرعاية فورًا (المستويات تُدار من قاعدة البيانات؛ الكاش ليس سلطة). */
    protected static function booted(): void
    {
        $bust = fn () => \App\Services\Sponsorship\SponsorshipCache::bust();
        static::saved($bust);
        static::deleted($bust);
    }

    protected function casts(): array
    {
        return ['minimum_accounting_amount_minor' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
