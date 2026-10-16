<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * E28 - محتوى المبدع نص عادي فقط. يُرفض عند الإدخال: أي وسم HTML/تعليق/XML/معالجة (<script، <img، <!--، <?php ...) وأي محرف تحكّم أو تحكّم اتجاهي مخادع (RLO/LRO...).
 * الدفاع الثاني عند العرض: Blade {{ }} المهرَّب دائمًا (لا {!! !!} لمحتوى المبدع). لا Blade/JS/CSS/iframe يصل لأي عارض.
 */
class NoMarkup implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/<\s*[a-z\/!?]/i', $value) === 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
            $fail('لا يُسمح بالوسوم أو الأكواد أو المحارف الخاصة في :attribute؛ اكتب نصًا عاديًا فقط.');
        }
    }
}
