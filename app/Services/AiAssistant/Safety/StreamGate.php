<?php

namespace App\Services\AiAssistant\Safety;

/**
 * E45 - بوابة البث: لا يخرج للعميل إلا نص مُنقّى ومفحوص بالكامل حتى النقطة المُفرَج عنها، مع حجب ذيل (HOLDBACK) إلى أن يكتمل الفحص.
 * أي مخالفة أو عدم اتساق بين لقطتين متتاليتين ⇒ تُغلق البوابة (blocked) ولا يصدر شيء بعدها: يستبدل المُنسِّق الردّ بنص آمن (فشل مغلق).
 */
final class StreamGate
{
    private string $buffer = '';

    private string $released = '';

    private bool $blocked = false;

    /** @var list<string> */
    private array $violations = [];

    public function __construct(private readonly AiOutputGuard $guard) {}

    /** يضيف دلتا ويعيد النص الجديد الآمن للإرسال الآن ('' إن لا شيء). */
    public function push(string $delta): string
    {
        if ($this->blocked) {
            return '';
        }

        $this->buffer .= $delta;
        $clean = $this->guard->sanitize($this->buffer);
        $found = $this->guard->inspect($clean);

        if ($found !== []) {
            return $this->block($found);
        }

        $length = mb_strlen($clean);
        $releasable = $length - AiOutputGuard::HOLDBACK;

        if ($releasable <= mb_strlen($this->released)) {
            return '';
        }

        $prefix = mb_substr($clean, 0, $releasable);
        // نقطة الإفراج عند آخر مسافة كي لا تُقطع كلمة/رمز في المنتصف.
        $cut = mb_strrpos($prefix, ' ');
        $prefix = $cut === false ? '' : mb_substr($prefix, 0, $cut + 1);

        if ($prefix === '' || mb_strlen($prefix) <= mb_strlen($this->released)) {
            return '';
        }

        if (! str_starts_with($prefix, $this->released)) {
            return $this->block(['inconsistent_stream']);
        }

        $delta = mb_substr($prefix, mb_strlen($this->released));
        $this->released = $prefix;

        return $delta;
    }

    /** ينهي البث: يعيد المتبقي الآمن، أو '' عند الحجب. */
    public function finish(): string
    {
        if ($this->blocked) {
            return '';
        }

        $clean = $this->guard->sanitize($this->buffer);
        $found = $this->guard->inspect($clean);

        if ($found !== []) {
            return $this->block($found);
        }

        if (! str_starts_with($clean, $this->released)) {
            return $this->block(['inconsistent_stream']);
        }

        $rest = mb_substr($clean, mb_strlen($this->released));
        $this->released = $clean;

        return $rest;
    }

    public function blocked(): bool
    {
        return $this->blocked;
    }

    /** ما أُفرج عنه فعلًا للعميل حتى الآن (مُنقّى). */
    public function released(): string
    {
        return $this->released;
    }

    /** @return list<string> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** النص الخام الكامل (للفحص النهائي من المُنسِّق فقط؛ لا يُخزَّن ولا يُسجَّل). */
    public function rawText(): string
    {
        return $this->buffer;
    }

    /** @param list<string> $found */
    private function block(array $found): string
    {
        $this->blocked = true;
        $this->violations = $found;

        return '';
    }
}
