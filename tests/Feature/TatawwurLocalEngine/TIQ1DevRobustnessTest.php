<?php

require_once __DIR__.'/_eval.php';

/*
| TI-Q1 - اختبار متانة على مجموعة التطوير فقط (gold_dev.php + مكمّلها gold_dev_q1.php): يولّد برمجيًا (حتميًّا، بلا عشوائية) صيغًا مشوَّشة لكل سؤال تطوير
| ويقيس هل يبقى فهم النية والإجابة سليمين: أخطاء إملائية، استبدال أدوات الاستفهام بعامية شامية/خليجية/مصرية/مغاربية، مفردات فصحى ← عامية،
| حشو أدبي، صياغة مختصرة (برقية). لا يلمس gold_heldout ولا gold_validation_q1.
| يدوي (TI_EVAL=1) ويكتب تقريرًا خارج المستودع (TI_EVAL_OUT). مولّد التشويش ينتج صيغًا من أسئلة التطوير نفسها ولا يضيف أي صياغة جديدة إلى المحرك.
*/

function tiq1_pick(string $seed, array $options): string
{
    return $options[crc32($seed) % count($options)];
}

/** @return list<string> كلمات عربية صالحة للتشويش (طول ≥ 4). */
function tiq1_noisy_words(string $q): array
{
    $words = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q) ?: '') ?: [];

    return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4 && preg_match('/^[\p{Arabic}]+$/u', $w) === 1));
}

function tiq1_typo(string $q): string
{
    $words = tiq1_noisy_words($q);

    if ($words === []) {
        return $q;
    }

    $word = $words[crc32($q.'w') % count($words)];
    $len = mb_strlen($word);
    $pos = 1 + (crc32($q.'p') % max(1, $len - 2));
    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

    switch (crc32($q.'o') % 3) {
        case 0:      // حذف حرف
            unset($chars[$pos]);
            break;
        case 1:      // تبديل حرفين متجاورين
            if ($pos + 1 < $len) {
                [$chars[$pos], $chars[$pos + 1]] = [$chars[$pos + 1], $chars[$pos]];
            }
            break;
        default:     // تكرار حرف
            array_splice($chars, $pos, 0, [$chars[$pos]]);
    }

    $typo = implode('', $chars);

    return $typo === $word ? $q : (string) preg_replace('/'.preg_quote($word, '/').'/u', $typo, $q, 1);
}

function tiq1_dialect(string $q): string
{
    $map = [
        '/(?<![\p{L}])كيف(?![\p{L}])/u' => ['ازاي', 'شلون', 'كيفاش', 'كيف بدي', 'شو الطريقة'],
        '/(?<![\p{L}])(ما هو|ما هي|ماذا|ما)(?![\p{L}])/u' => ['شو', 'ايش', 'إيه', 'شنو', 'وش'],
        '/(?<![\p{L}])(أين|اين)(?![\p{L}])/u' => ['وين', 'فين', 'فينه'],
        '/(?<![\p{L}])لماذا(?![\p{L}])/u' => ['ليش', 'ليه', 'علاش', 'لشو'],
        '/(?<![\p{L}])متى(?![\p{L}])/u' => ['امتى', 'ايمتى', 'وقتاش'],
        '/(?<![\p{L}])(أريد|اريد)(?![\p{L}])/u' => ['بدي', 'ابغى', 'عايز', 'نبغي'],
        '/(?<![\p{L}])(هل يمكنني|هل أستطيع|هل استطيع|هل يمكن)(?![\p{L}])/u' => ['اقدر', 'ينفع', 'بقدر', 'ممكن'],
        '/(?<![\p{L}])الآن(?![\p{L}])/u' => ['هلق', 'الحين', 'دلوقتي', 'توا'],
        '/(?<![\p{L}])ليس(?![\p{L}])/u' => ['مو', 'مش', 'ماهو'],
    ];
    $out = $q;

    foreach ($map as $pattern => $options) {
        if (preg_match($pattern, $out) === 1) {
            $out = (string) preg_replace($pattern, tiq1_pick($q.$pattern, $options), $out, 1);
        }
    }

    return $out;
}

function tiq1_filler(string $q): string
{
    $prefix = tiq1_pick($q.'f', ['لو سمحت ', 'ممكن تقولي ', 'عندي سؤال: ', 'يا مساعد ', 'مرحبا، ']);
    $suffix = tiq1_pick($q.'s', [' شكرا', ' لو تكرمت', '؟؟', ' بسرعة', ' وشكرا مقدما']);

    return $prefix.rtrim($q, '؟? ').$suffix;
}

function tiq1_telegraphic(string $q): string
{
    $drop = ['هل', 'ما', 'هو', 'هي', 'ماذا', 'كيف', 'أين', 'اين', 'لماذا', 'متى', 'في', 'من', 'على', 'إلى', 'الى', 'عن', 'أن', 'ان', 'يمكن', 'يمكنني', 'أستطيع', 'استطيع', 'لي', 'هذا', 'هذه', 'ذلك', 'تلك'];
    $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q))) ?: [];
    $kept = array_values(array_filter($words, fn ($w) => ! in_array($w, $drop, true)));

    return count($kept) >= 2 ? implode(' ', $kept) : $q;
}

/** @return array<string, callable(string): string> */
function tiq1_variants(): array
{
    return ['typo' => 'tiq1_typo', 'dialect' => 'tiq1_dialect', 'filler' => 'tiq1_filler', 'telegraphic' => 'tiq1_telegraphic'];
}

it('measures robustness of the local engine on perturbed dev questions (dev only)', function () {
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true]);
    ti_kb_full();

    $dev = require __DIR__.'/Datasets/gold_dev.php';
    $supplementPath = __DIR__.'/Datasets/gold_dev_q1.php';
    $supplement = is_file($supplementPath) ? require $supplementPath : [];
    $report = [];

    foreach (['base' => null] + tiq1_variants() as $name => $fn) {
        foreach (['dev' => $dev, 'devq1' => $supplement] as $setName => $set) {
            if ($set === []) {
                continue;
            }

            $variant = [];

            foreach ($set as $intent => $questions) {
                foreach ($questions as $q) {
                    $variant[$intent][] = $fn === null ? $q : $fn($q);
                }
            }

            $result = ti_run(ti_cases($variant, strtoupper(substr($setName, 0, 1)).substr($name, 0, 1)));
            $report[$setName.'-'.$name] = $result;
            ti_dump('q1-dev-'.$setName.'-'.$name, $result, true);
        }
    }

    expect(count($report))->toBeGreaterThanOrEqual(5);
})->skip(fn () => getenv('TI_EVAL') !== '1', 'قياس يدوي: TI_EVAL=1');
