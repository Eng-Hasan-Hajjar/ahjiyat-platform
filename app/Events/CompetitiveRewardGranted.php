<?php

namespace App\Events;

/** حدث مجال (E18): مُنحت جائزة تنافسية فعلًا (بعد اكتمال المنح). يحمل معرّف سجل المنح فقط. */
class CompetitiveRewardGranted
{
    public function __construct(public readonly int $grantId) {}
}
