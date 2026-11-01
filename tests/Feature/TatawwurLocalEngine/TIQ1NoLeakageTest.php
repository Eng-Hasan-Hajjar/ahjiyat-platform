<?php

use App\Services\Search\TextFold;

/*
| TI-Q1 - منع تسريب أسئلة التقييم إلى المحرك.
|
| يمسح هذا الاختبار كل السلاسل النصية في شيفرة المحرك الداخلي (معجم، فهرس نوايا، مصنّف، محلّل ...) ويتحقق من أن أي عبارة من ثلاث كلمات فأكثر
| لا تطابق مقطعًا متصلًا من سؤال في مجموعة التحقق المستقلة V2 أو في مجموعة الـ held-out القديمة، إلا ما كان موجودًا أصلًا في خط الأساس
| (الالتزام 16c02b5 قبل TI-Q1) ومثبَّتًا هنا بالاسم. أي عبارة جديدة مطابقة لسؤال تقييم تُفشل الاختبار.
| (العبارات من كلمة أو كلمتين عامة بطبيعتها وتتقاطع مصادفة مع أي مجموعة أسئلة عربية؛ لذلك يقتصر الفحص على ثلاث كلمات فأكثر.)
*/

const TIQ1_LEAK_MIN_TOKENS = 3;

/** عبارات ثلاثية فأكثر كانت موجودة في المعجم قبل TI-Q1 (مجمّدة؛ مسجَّلة كتلويث سابق لمجموعة الـ held-out في تقرير الجودة). */
const TIQ1_LEAK_BASELINE_ALLOWANCE = [
    'اتخذ علي حسابي', 'اذا ما دخلت', 'اكتب لي كود', 'الالغاز اللي حفظتها', 'باقي لي علي', 'رابط ادعو فيه', 'صاحب اعلي نتيجه', 'صفحه لاعب ثاني',
    'علي المستوي الجاي', 'قايمه الالغاز اللي حفظتها', 'قبل ما اصوت', 'ما تظهر لي', 'ما تنباع بالمتجر', 'ما دخلت يوم', 'ما صارت متاحه',
    'مخفيه عن الغرباء', 'من غير ما ينكشف', 'من وين تجي',
];

function tiq1_leak_fold(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($s)));
}

/** @return array<string, array<string, true>> مقاطع (≥3 كلمات) من الأسئلة ⇒ المجموعة التي جاءت منها */
function tiq1_leak_ngrams(): array
{
    $ngrams = [];

    foreach (['v2' => 'gold_validation_q1.php', 'heldout' => 'gold_heldout.php'] as $set => $file) {
        foreach (require __DIR__.'/Datasets/'.$file as $questions) {
            foreach ($questions as $q) {
                $tokens = explode(' ', tiq1_leak_fold($q));
                $n = count($tokens);

                for ($a = 0; $a < $n; $a++) {
                    for ($len = TIQ1_LEAK_MIN_TOKENS; $len <= min(10, $n - $a); $len++) {
                        $ngrams[implode(' ', array_slice($tokens, $a, $len))][$set] = true;
                    }
                }
            }
        }
    }

    return $ngrams;
}

/** @return list<string> ملفات شيفرة المحرك الداخلي. */
function tiq1_leak_engine_files(): array
{
    $root = base_path('app/Services/AiAssistant/Local');
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

test('TIQ1-LEAK-01: no engine string equals a whole question of V2 or the held-out set', function () {
    $whole = [];

    foreach (['gold_validation_q1.php', 'gold_heldout.php'] as $file) {
        foreach (require __DIR__.'/Datasets/'.$file as $questions) {
            foreach ($questions as $q) {
                $whole[tiq1_leak_fold($q)] = true;
            }
        }
    }

    $hits = [];

    foreach (tiq1_leak_engine_files() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            foreach (explode('|', substr($token[1], 1, -1)) as $piece) {
                $folded = tiq1_leak_fold($piece);

                if ($folded !== '' && isset($whole[$folded])) {
                    $hits[] = basename($file).': '.$folded;
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

test('TIQ1-LEAK-02: no engine phrase of three or more words matches a V2 or held-out question except the pinned pre-Q1 baseline', function () {
    $ngrams = tiq1_leak_ngrams();
    $unexpected = [];
    $seenBaseline = [];

    foreach (tiq1_leak_engine_files() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            foreach (explode('|', substr($token[1], 1, -1)) as $piece) {
                $folded = tiq1_leak_fold($piece);

                if (substr_count($folded, ' ') + 1 < TIQ1_LEAK_MIN_TOKENS || ! isset($ngrams[$folded])) {
                    continue;
                }

                if (in_array($folded, TIQ1_LEAK_BASELINE_ALLOWANCE, true)) {
                    $seenBaseline[$folded] = true;
                } else {
                    $unexpected[] = basename($file).': '.$folded.' ['.implode(',', array_keys($ngrams[$folded])).']';
                }
            }
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([])
        ->and(count($seenBaseline))->toBeLessThanOrEqual(count(TIQ1_LEAK_BASELINE_ALLOWANCE));
});

test('TIQ1-LEAK-03: the frozen evaluation datasets are not read by any engine source', function () {
    foreach (tiq1_leak_engine_files() as $file) {
        $source = (string) file_get_contents($file);

        expect(str_contains($source, 'gold_validation_q1'))->toBeFalse(basename($file).' references V2')
            ->and(str_contains($source, 'gold_heldout'))->toBeFalse(basename($file).' references the held-out set')
            ->and(str_contains($source, 'Datasets/'))->toBeFalse(basename($file).' reads a tests dataset');
    }
});
