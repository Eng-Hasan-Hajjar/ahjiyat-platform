<?php

namespace App\Events;

/** حدث مجال (E20) بعد commit: اعتُمدت بطولة فرق. يحمل معرّفها فقط. */
class TeamChampionshipFinalized
{
    public function __construct(public readonly int $championshipId) {}
}
