<?php

namespace App\Events;

/** حدث مجال (E17-C) بعد commit: اعتُمدت النتائج النهائية لحدث. يحمل معرّف الحدث فقط. */
class CompetitiveEventFinalized
{
    public function __construct(public readonly int $eventId) {}
}
