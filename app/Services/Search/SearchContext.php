<?php

namespace App\Services\Search;

use App\Models\User;

/** E27: من يبحث. الضيف وغير الموثَّق يُعاملان كزائر في نتائج اللاعبين (لا ملفات «للأعضاء فقط»، ولا استثناء حظر). */
final class SearchContext
{
    public function __construct(public readonly ?User $viewer = null) {}

    public static function for(?User $viewer): self
    {
        return new self($viewer);
    }

    public function isGuest(): bool
    {
        return $this->viewer === null;
    }

    /** مشاهد موثَّق البريد وغير مجمَّد: يرى ملفات «الأعضاء» ويُطبَّق عليه الحظر. */
    public function isTrustedMember(): bool
    {
        return $this->viewer !== null && $this->viewer->email_verified_at !== null && ! $this->viewer->is_frozen;
    }
}
