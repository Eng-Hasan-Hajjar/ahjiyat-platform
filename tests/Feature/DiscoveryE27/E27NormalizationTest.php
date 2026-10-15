<?php

require_once __DIR__.'/_support.php';

use App\Services\Search\SearchQuery;
use App\Services\Search\TextFold;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('N1: folding unifies alef, hamza, ta marbuta, alef maksura, tatweel and diacritics', function (string $input, string $expected) {
    expect(TextFold::fold($input))->toBe($expected);
})->with([
    ['أحجية', 'احجيه'], ['إسلام', 'اسلام'], ['آمال', 'امال'], ['ٱلكتاب', 'الكتاب'], ['مَدْرَسَةٌ', 'مدرسه'], ['ـمـدرسـة', 'مدرسه'],
    ['على', 'علي'], ['مؤمن', 'مومن'], ['رئيس', 'رييس'], ['ﻻ', 'لا'], ['  كَلِمَةٌ   طَوِيلَةٌ  ', 'كلمه طويله'],
]);

test('N2: digits in Arabic-Indic, Persian and ASCII forms fold together', function () {
    expect(TextFold::fold('٠١٢٣٤٥٦٧٨٩'))->toBe('0123456789')->and(TextFold::fold('۰۱۲۳۴۵۶۷۸۹'))->toBe('0123456789')->and(TextFold::fold('Level ٣'))->toBe('level 3');
});

test('N3: Latin case, Turkish dotted/dotless i and invisible characters fold away', function () {
    expect(TextFold::fold('İSTANBUL'))->toBe('istanbul')->and(TextFold::fold('ISPARTA ıı'))->toBe('isparta ii')->and(TextFold::fold("a\u{200D}b\u{200C}c"))->toBe('abc')
        ->and(TextFold::fold("a\u{200B}b"))->toBe('a b')->and(TextFold::fold("\u{202E}abc\u{202C}"))->toBe('abc')->and(TextFold::fold("a\x00b\tc\nd"))->toBe('a b c d');
});

test('N4: invalid UTF-8 and empty input fold to an empty string instead of failing', function () {
    expect(TextFold::fold("\xC3\x28"))->toBe('')->and(TextFold::fold(null))->toBe('')->and(TextFold::fold(''))->toBe('')->and(SearchQuery::from("\xFF\xFE")->isEmpty())->toBeTrue();
});

test('N5: the SQL fold of a column equals the PHP fold of the same text for every letter rule (parity)', function () {
    $corpus = ['أحجية', 'إسلام', 'آمال', 'ٱلكتاب', 'مَدْرَسَةٌ', 'ـمـدرسـة', 'على', 'مؤمن', 'رئيس', 'ISPARTA', 'ıspartı', 'Ab Cd', 'لغز  الذكاء'];
    DB::statement('create table e27_fold (v text)');
    foreach ($corpus as $text) {
        DB::table('e27_fold')->insert(['v' => $text]);
    }

    $expression = TextFold::sqlFold('v');
    $rows = DB::table('e27_fold')->selectRaw($expression.' as f, v')->get();

    foreach ($rows as $row) {
        // SQL لا يطوي المسافات المتعددة ولا أحرف Unicode الكبيرة خارج ASCII؛ نقارن بعد توحيد المسافات (الحدّ الموثّق للمطابقة الجزئية).
        expect(preg_replace('/\s+/u', ' ', $row->f))->toBe(TextFold::fold($row->v), $row->v);
    }
    DB::statement('drop table e27_fold');
});

test('N6: the SQL fold accepts only plain identifiers - anything else throws and never reaches SQL', function (string $bad) {
    expect(fn () => TextFold::sqlFold($bad))->toThrow(InvalidArgumentException::class);
})->with(["title; drop table users", "title) or (1=1", "users.name'", 'a.b.c', 'Title', '1abc', "t`x", '', 'name --', "na\nme"]);

test('N7: LIKE escaping makes percent, underscore and the escape character literal', function () {
    expect(TextFold::escapeLike('100%_!'))->toBe('100!%!_!!')->and(SearchQuery::from('50%')->foldedPrefixPattern())->toBe('50!%%');
});

test('N8: a query keeps its display form, folds for matching, caps tokens and length, and measures length without spaces', function () {
    $q = SearchQuery::from("  أَحْجِيَّةُ   المدينة  ");

    expect($q->display)->toBe('أَحْجِيَّةُ المدينة')->and($q->folded)->toBe('احجيه المدينه')->and($q->tokens)->toBe(['احجيه', 'المدينه'])->and($q->length())->toBe(12);

    $long = SearchQuery::from(str_repeat('ب', 200));
    expect(mb_strlen($long->display))->toBe(64)->and($long->truncated)->toBeTrue();

    expect(SearchQuery::from('a b c d e f g h')->tokens)->toHaveCount(5)->and(SearchQuery::from('a a a')->tokens)->toBe(['a']);
});

test('N9: the minimum length counts folded letters, not bytes - two Arabic letters pass, one does not', function () {
    expect(SearchQuery::from('لغ')->meets(2))->toBeTrue()->and(SearchQuery::from('ل')->meets(2))->toBeFalse()->and(SearchQuery::from('ل ')->meets(2))->toBeFalse()->and(SearchQuery::from('ـ')->meets(1))->toBeFalse();
});

test('N10: the definite article is dropped from long words for partial matching only', function () {
    expect(SearchQuery::from('الاحجية')->tokenPatterns())->toBe(['%احجيه%'])->and(SearchQuery::from('الله')->tokenPatterns())->toBe(['%الله%'])->and(SearchQuery::from('الم')->tokenPatterns())->toBe(['%الم%']);
});

test('N11: on a table beyond the partial limit only column prefixes match - fast, index-friendly, honest about the trade-off', function () {
    config(['search.partial_match_max_id' => 2]);
    e27_puzzle('أحجية أولى');
    e27_puzzle('ثانية أحجية');
    e27_puzzle('أحجية ثالثة');
    e27_cold();

    // بادئة بالنص كما كُتب تعمل؛ كلمة وسط العنوان لا تُطابَق في هذا الوضع (حدّ موثّق: الانتقال لمحرك بحث).
    expect(e27_group(e27_suggest($this, 'أحجية'), 'puzzles'))->toBe(['أحجية ثالثة', 'أحجية أولى'])
        ->and(e27_group(e27_suggest($this, 'ثالثة'), 'puzzles'))->toBe([]);
});

test('N12: below the partial limit the same data is found by a middle word and an Arabic variant', function () {
    config(['search.partial_match_max_id' => 100]);
    e27_puzzle('أحجية أولى');
    e27_puzzle('ثانية أحجية');
    e27_cold();

    // البادئة (المرتبة 1) تسبق الجزئي (المرتبة 3) حتى لو كان الثاني أحدث.
    expect(e27_group(e27_suggest($this, 'احجيه'), 'puzzles'))->toBe(['أحجية أولى', 'ثانية أحجية']);
    expect(e27_group(e27_suggest($this, 'اولي'), 'puzzles'))->toBe(['أحجية أولى'])->and(e27_group(e27_suggest($this, 'أولى'), 'puzzles'))->toBe(['أحجية أولى']);
});

test('N13: per-type overrides of the partial limit apply to that type only', function () {
    config(['search.partial_match_max_id' => 100, 'search.partial_match_max_id_by_type' => ['teams' => 1]]);
    e27_puzzle('ثانية أحجية');
    e19Team(null, ['name' => 'فريق الأول']);
    e19Team(null, ['name' => 'فريق الثاني']);
    e27_cold();

    expect(e27_group(e27_suggest($this, 'احجيه'), 'puzzles'))->toBe(['ثانية أحجية'])->and(e27_group(e27_suggest($this, 'الثاني'), 'teams'))->toBe([]);
});
