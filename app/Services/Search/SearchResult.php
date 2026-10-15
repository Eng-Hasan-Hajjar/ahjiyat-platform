<?php

namespace App\Services\Search;

/**
 * E27: نتيجة بحث واحدة بحقول عرض فقط. لا تحمل بريدًا ولا هاتفًا ولا معرّفًا داخليًا (id) ولا أي حقل جواب/تلميح.
 * tier: 0 تطابق تام، 1 بادئة، 2 بادئة كلمة، 3 جزئي (الأصغر أعلى صلة).
 */
final class SearchResult
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $subtitle = null,
        public readonly ?string $badge = null,
        public readonly string $icon = 'search',
        public readonly int $tier = 3,
        public readonly mixed $subject = null,   // النموذج لعرض الصفحة الكاملة (لا يُسلسَل إلى JSON أبدًا)
    ) {}

    /** شكل الاقتراح الفوري: مسار نسبي فقط (يمنع توجيهًا لمضيف آخر) ونصوص عادية يعرضها العميل بـtextContent. */
    public function toSuggestion(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'badge' => $this->badge,
            'icon' => $this->icon,
            'url' => self::relativePath($this->url),
        ];
    }

    public static function relativePath(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim((string) (($parts['path'] ?? '/') ?: '/'), '/\\');   // دائمًا «/» واحدة في البداية (لا «//host»)

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
