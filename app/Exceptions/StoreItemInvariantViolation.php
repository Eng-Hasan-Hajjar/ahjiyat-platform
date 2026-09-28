<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * E11.1: تُرمى عند كسر قاعدة نطاق (Domain) لعنصر متجر، من أي مسار كتابة
 * Eloquent (Filament/Service/Seeder/Command/Factory). تمتد RuntimeException
 * ليبقى التعامل معها متّسقًا مع بقية أخطاء الأعمال بالمشروع.
 */
class StoreItemInvariantViolation extends RuntimeException
{
}