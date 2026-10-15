<?php

namespace App\Services\Operations;

/**
 * E25 - نتيجة فحص واحدة. الرسالة تحمل اسم الإعداد (APP_KEY…) ولا تحمل قيمة سرية أبدًا.
 */
final class CheckResult
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const INFO = 'INFO';

    public function __construct(
        public readonly string $status,
        public readonly string $group,
        public readonly string $setting,
        public readonly string $message,
    ) {}

    /** @return array{status: string, group: string, setting: string, message: string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'group' => $this->group, 'setting' => $this->setting, 'message' => $this->message];
    }
}
