<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - سجل مكافأة رعاية مُنحت فعلًا (لقطة: الأساس، النقاط الأساسية، المحسوب، الممنوح، التمويل وقتها، البرنامج). source_key فريد ⇒ لا منح مزدوج.
 * هذا سجل توزيع فقط؛ الأصل الاقتصادي (الجواهر) مصدر حقيقته دفتر المحفظة (currency_transactions) عبر CurrencyWalletService.
 */
class SponsorshipRewardBonus extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }
}
