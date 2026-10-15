<?php

namespace App\Services\Search;

/**
 * E27: مُدخَل بحث بعد التنظيف والتطبيع. غير قابل للتعديل. لا يحتفظ بشيء عن المستخدم.
 *  - display: النص المنظَّف كما كتبه المستخدم (للعرض في الحقل والروابط؛ يُهرَّب بـBlade دائمًا).
 *  - folded: الشكل المطوي للمطابقة (أحرف صغيرة، عربية موحَّدة، أرقام ASCII).
 *  - tokens: كلمات الشكل المطوي (حدّ أقصى بالإعدادات) للمطابقة الجزئية.
 */
final class SearchQuery
{
    /** @param list<string> $tokens */
    private function __construct(
        public readonly string $display,
        public readonly string $folded,
        public readonly array $tokens,
        public readonly bool $truncated,
    ) {}

    public static function from(?string $input): self
    {
        $max = max(1, (int) config('search.max_length', 64));
        $clean = (string) $input;

        if ($clean === '' || ! mb_check_encoding($clean, 'UTF-8')) {
            return new self('', '', [], false);
        }

        $clean = preg_replace('/\p{Cf}/u', '', str_replace("\u{200B}", ' ', $clean)) ?? '';
        $clean = trim(preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $clean) ?? '');
        $truncated = mb_strlen($clean) > $max;
        $clean = $truncated ? rtrim(mb_substr($clean, 0, $max)) : $clean;

        $folded = TextFold::fold($clean);
        $tokens = array_slice(array_values(array_unique(array_filter(explode(' ', $folded), fn (string $t) => $t !== ''))), 0, max(1, (int) config('search.max_tokens', 5)));

        return new self($clean, $folded, $tokens, $truncated);
    }

    /** عدد الحروف المطوية بلا مسافات: أساس الحدّ الأدنى. */
    public function length(): int
    {
        return mb_strlen(str_replace(' ', '', $this->folded));
    }

    public function isEmpty(): bool
    {
        return $this->folded === '';
    }

    public function meets(int $minLength): bool
    {
        return $this->length() >= max(1, $minLength);
    }

    /** نمط بادئة آمن للمقارنة مع الشكل المطوي. */
    public function foldedPrefixPattern(): string
    {
        return TextFold::escapeLike($this->folded).'%';
    }

    /** نمط بادئة كلمة (أي كلمة تبدأ بالمصطلح) للمقارنة مع الشكل المطوي. */
    public function foldedWordPattern(): string
    {
        return '% '.TextFold::escapeLike($this->folded).'%';
    }

    /** نمط بادئة بالنص الأصلي بأحرف صغيرة (يطابق العمود الخام حين يتساوى المطوي والخام في المحرك). */
    public function rawPrefixPattern(): string
    {
        return TextFold::escapeLike(mb_strtolower($this->display, 'UTF-8')).'%';
    }

    /**
     * أنماط المطابقة الجزئية لكل كلمة. أداة التعريف «ال» تُسقَط من الكلمات الطويلة (≥ 5 حروف) فيجد «الاحجية» عنوان «أحجية» وعكسه، لأن الصيغة المجرّدة جزء من الصيغتين.
     *
     * @return list<string>
     */
    public function tokenPatterns(): array
    {
        return array_map(fn (string $t) => '%'.TextFold::escapeLike(self::withoutArticle($t)).'%', $this->tokens);
    }

    private static function withoutArticle(string $token): string
    {
        return mb_strlen($token) >= 5 && str_starts_with($token, "\u{0627}\u{0644}") ? mb_substr($token, 2) : $token;
    }

    /** مفتاح مستقر لوسائط الروابط. */
    public function urlTerm(): string
    {
        return $this->display;
    }
}
