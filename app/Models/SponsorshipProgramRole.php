<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - دور داعم ضمن برنامج: supporter < sponsor < main_sponsor. الراعي الرئيسي واحد لكل برنامج بقيد قاعدة البيانات (main_marker فريد).
 * الراعي الرئيسي ≠ أعلى داعم: يُعيَّن إداريًا ويشترط مساهمة مؤكَّدة بهذا البرنامج.
 */
class SponsorshipProgramRole extends Model
{
    public const ROLE_SUPPORTER = 'supporter';

    public const ROLE_SPONSOR = 'sponsor';

    public const ROLE_MAIN_SPONSOR = 'main_sponsor';

    public const ROLES = [self::ROLE_SUPPORTER, self::ROLE_SPONSOR, self::ROLE_MAIN_SPONSOR];

    protected $guarded = ['*'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }
}
