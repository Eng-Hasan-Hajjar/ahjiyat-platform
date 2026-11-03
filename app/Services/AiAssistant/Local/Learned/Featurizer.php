<?php

namespace App\Services\AiAssistant\Local\Learned;

use App\Services\Search\TextFold;

/**
 * TATAWWUR-FINAL - استخراج خصائص نصية حتمي للمصنِّف التعلّمي المحلي: كلمات مطوية (مفردة وثنائية) + n-gram حرفية (2-5) بحدود كلمة.
 * يطابق حرفيًا ما يفعله مدرّب النموذج (tools/tatawwur/train_intent_model.py يستهلك Featurizer::tokens فقط ثم يولّد n-gram بالخوارزمية نفسها)،
 * ويتحقق TILearnedParityTest من التطابق. لا شبكة ولا نموذج لغوي ولا تحميل خارجي.
 */
final class Featurizer
{
    public const CHAR_MIN = 2;

    public const CHAR_MAX = 5;

    /** @return list<string> كلمات السؤال بعد الطيّ وحذف علامات الترقيم. */
    public static function tokens(string $text): array
    {
        $folded = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', TextFold::fold($text)) ?? '';

        return array_values(array_filter(preg_split('/\s+/u', trim($folded)) ?: [], fn ($w) => $w !== ''));
    }

    /**
     * @param  list<string>  $tokens
     * @return array{word: array<string, int>, char: array<string, int>}
     */
    public static function counts(array $tokens): array
    {
        $word = [];
        $char = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $k = 'w:'.$t;
            $word[$k] = ($word[$k] ?? 0) + 1;

            if ($i + 1 < $count) {
                $k = 'b:'.$t.' '.$tokens[$i + 1];
                $word[$k] = ($word[$k] ?? 0) + 1;
            }

            $chars = mb_str_split(' '.$t.' ');
            $len = count($chars);

            for ($n = self::CHAR_MIN; $n <= self::CHAR_MAX; $n++) {
                $offset = 0;
                $k = 'c:'.implode('', array_slice($chars, 0, $n));
                $char[$k] = ($char[$k] ?? 0) + 1;

                while ($offset + $n < $len) {
                    $offset++;
                    $k = 'c:'.implode('', array_slice($chars, $offset, $n));
                    $char[$k] = ($char[$k] ?? 0) + 1;
                }

                if ($offset === 0) {
                    break;
                }
            }
        }

        return ['word' => $word, 'char' => $char];
    }
}
