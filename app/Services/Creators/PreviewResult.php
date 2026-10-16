<?php

namespace App\Services\Creators;

/** E28 - نتيجة معاينة: صحيحة/خاطئة فقط (بلا مكافآت ولا تخزين). */
final class PreviewResult
{
    public function __construct(public readonly bool $correct) {}
}
