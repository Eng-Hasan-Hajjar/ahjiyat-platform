<?php

namespace App\Services\AiAssistant\Tools;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * E45 - سياق تنفيذ الأدوات، يبنيه الخادم وحده من الجلسة: المستخدم المصادَق (أو لا أحد) وعضويته. النموذج لا يختار هوية المستخدم أبدًا
 * (لا وسيط user_id/email في أي أداة)، وأدوات «حسابي» لا تُعرَض ولا تُنفَّذ إلا إن وُجد مستخدم موثَّق غير مجمَّد.
 */
final class ToolContext
{
    public function __construct(
        public readonly ?User $user,
        public readonly bool $member,
        public readonly CarbonImmutable $now,
    ) {}

    public static function forUser(?User $user, bool $member): self
    {
        return new self($member ? $user : null, $member && $user !== null, CarbonImmutable::now());
    }

    /** مستخدم موثَّق غير مجمَّد (شرط أدوات حسابي). */
    public function hasMember(): bool
    {
        return $this->member && $this->user !== null;
    }
}
