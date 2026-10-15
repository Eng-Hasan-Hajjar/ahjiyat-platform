<?php

namespace App\Services\Discovery;

/** E27-B: بطاقة عرض موحَّدة لقسم اكتشاف. نصوص عادية ومسار فقط؛ يُهرَّب الإخراج بـBlade. لا بيانات حساسة. */
final class DiscoverCard
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $icon = 'puzzle',
        public readonly ?string $subtitle = null,
        public readonly ?string $badge = null,
        public readonly ?string $badgeTone = 'neutral',
        public readonly ?string $reason = null,
    ) {}
}
